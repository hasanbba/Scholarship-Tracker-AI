<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Cli;

use Scholarship\CrawlerWorker\Api\CrawlerApiClientInterface;
use Scholarship\CrawlerWorker\Config\WorkerConfig;
use Scholarship\CrawlerWorker\Credential\CredentialStoreInterface;
use Scholarship\CrawlerWorker\Exception\WorkerStateException;
use Scholarship\CrawlerWorker\Logging\StructuredLogger;
use Scholarship\CrawlerWorker\Spool\LocalSpoolInterface;
use Scholarship\CrawlerWorker\Support\RequestKey;
use Scholarship\CrawlerWorker\Support\ShutdownController;
use Scholarship\CrawlerWorker\Support\WorkerIdentityStore;
use Scholarship\CrawlerWorker\Fetch\FetchFailure;
use Scholarship\CrawlerWorker\Fetch\SafeFetcher;

final class WorkerApplication
{
    public function __construct(
        private WorkerConfig $config,
        private readonly CrawlerApiClientInterface $api,
        private readonly CredentialStoreInterface $credentials,
        private readonly WorkerIdentityStore $identities,
        private readonly LocalSpoolInterface $spool,
        private readonly StructuredLogger $logger,
        private readonly ShutdownController $shutdown,
        private readonly ?SafeFetcher $fetcher = null,
    ) {}

    public function apiUrl(): string { return $this->config->apiBaseUrl; }

    public function activate(string $activationCode): array
    {
        $this->config->validateForApi();
        $this->logger->rememberSecret($activationCode);
        $this->logger->log('info', 'worker.activation.started', ['endpoint' => '/api/v1/crawler/workers/activate']);
        try {
            $result = $this->api->activate($activationCode, $this->config->protocolVersion, $this->config->softwareVersion);
        } catch (\Throwable $exception) {
            $this->logger->log('error', 'worker.activation.failed', ['error_code' => $exception::class]);
            throw $exception;
        }
        $this->logger->rememberSecret($result['token']);
        $this->credentials->store($this->config->credentialReference, $result['token']);
        $identity = [
            'worker_uuid' => $result['worker']['uuid'], 'label' => $result['worker']['label'],
            'api_base_url' => $this->config->apiBaseUrl, 'expires_at' => $result['expires_at'],
        ];
        $this->identities->save($identity);
        $this->logger->log('info', 'worker.activation.succeeded', ['worker_id' => $identity['worker_uuid']]);
        return $identity;
    }

    public function status(): array
    {
        $identity = $this->identities->load() ?? throw new WorkerStateException('Worker has not been activated on this machine.');
        $token = $this->credentials->retrieve($this->config->credentialReference) ?? throw new WorkerStateException('Worker credential is not available in the configured credential store.');
        $this->logger->rememberSecret($token);
        $status = $this->api->getCurrentWorker($token);
        if (($status['uuid'] ?? null) !== $identity['worker_uuid']) throw new WorkerStateException('Server worker identity does not match local worker identity.');
        $this->logger->log('info', 'worker.status.checked', ['worker_id' => $identity['worker_uuid'], 'worker_status' => $status['status'], 'endpoint' => '/api/v1/crawler/workers/me']);
        return ['worker' => $status, 'api_base_url' => $identity['api_base_url'], 'credential_state' => 'available', 'expires_at' => $identity['expires_at']];
    }

    public function doctor(): array
    {
        $this->config->validateForApi();
        $this->ensureWritableDirectory($this->config->logDirectory);
        $this->ensureWritableDirectory($this->config->spoolDirectory);
        $health = $this->api->health();
        if (($health['status'] ?? null) !== 'ok') throw new WorkerStateException('Laravel health endpoint returned an incompatible response.');
        $identity = $this->identities->load();
        $token = $this->credentials->retrieve($this->config->credentialReference);
        $workerStatus = null;
        if ($token !== null) {
            $this->logger->rememberSecret($token);
            $workerStatus = $this->api->getCurrentWorker($token);
            if ($identity && ($workerStatus['uuid'] ?? null) !== $identity['worker_uuid']) throw new WorkerStateException('Server worker identity does not match local worker identity.');
        }
        return [
            'php_version' => PHP_VERSION, 'api_base_url' => $this->config->apiBaseUrl,
            'https' => str_starts_with(strtolower($this->config->apiBaseUrl), 'https://'),
            'credential_store' => $this->config->credentialStore, 'credential_available' => $token !== null,
            'worker_authenticated' => $workerStatus !== null, 'worker_uuid' => $workerStatus['uuid'] ?? null,
            'log_directory_writable' => true, 'spool_directory_writable' => true,
            'api_health' => $health['status'],
        ];
    }

    public function once(): ?array
    {
        $token = $this->requireCredential();
        $identity = $this->identities->load() ?? throw new WorkerStateException('Worker identity is missing. Run activate first.');
        $claimKey = RequestKey::uuid();
        $this->logger->log('info', 'job.claim.started', ['worker_id' => $identity['worker_uuid'], 'request_id' => $claimKey, 'endpoint' => '/api/v1/crawler/jobs/claim']);
        try {
            $job = $this->api->claimJob($token, $claimKey);
        } catch (\Throwable $exception) {
            $this->logger->log('error', 'job.claim.failed', ['worker_id' => $identity['worker_uuid'], 'request_id' => $claimKey, 'error_code' => $exception::class]);
            throw $exception;
        }
        if ($job === null) {
            $this->logger->log('info', 'job.claim.empty', ['worker_id' => $identity['worker_uuid'], 'request_id' => $claimKey]);
            return null;
        }
        $this->logger->log('info', 'job.claim.succeeded', ['worker_id' => $identity['worker_uuid'], 'job_id' => (int) $job['job_id'], 'attempt_id' => (int) $job['attempt_id'], 'request_id' => $claimKey]);
        $this->executeJob($token, $job, $identity['worker_uuid'], true);
        return $job;
    }

    public function poll(?int $maxCycles = null): void
    {
        $this->shutdown->register();
        $cycles = 0;
        $token = $this->requireCredential();
        $identity = $this->identities->load() ?? throw new WorkerStateException('Worker identity is missing. Run activate first.');
        $active = null;
        $localLeaseExpiresAt = 0;
        $this->logger->log('info', 'worker.started', ['worker_id' => $identity['worker_uuid'], 'mode' => 'poll']);
        while (! $this->shutdown->requested() && ($maxCycles === null || $cycles < $maxCycles)) {
            $cycles++;
            if ($active === null) {
                $key = RequestKey::uuid();
                $this->logger->log('info', 'job.claim.started', ['worker_id' => $identity['worker_uuid'], 'request_id' => $key]);
                try {
                    $active = $this->api->claimJob($token, $key);
                } catch (\Throwable $exception) {
                    $this->logger->log('error', 'job.claim.failed', ['worker_id' => $identity['worker_uuid'], 'request_id' => $key, 'error_code' => $exception::class]);
                    throw $exception;
                }
                if ($active === null) {
                    $this->logger->log('info', 'job.claim.empty', ['worker_id' => $identity['worker_uuid'], 'request_id' => $key]);
                    $this->sleepSeconds($this->config->pollIntervalSeconds);
                    continue;
                }
                $this->logger->log('info', 'job.claim.succeeded', ['worker_id' => $identity['worker_uuid'], 'job_id' => (int) $active['job_id'], 'attempt_id' => (int) $active['attempt_id']]);
                $localLeaseExpiresAt = strtotime((string) $active['lease_expires_at']) ?: time();
                $localLeaseExpiresAt = $this->executeJob($token, $active, $identity['worker_uuid']);
            }
            if ($active !== null && time() >= $localLeaseExpiresAt) {
                $this->logger->log('warning', 'worker.communication_lease_expired', ['worker_id' => $identity['worker_uuid'], 'job_id' => (int) $active['job_id'], 'attempt_id' => (int) $active['attempt_id']]);
                $active = null;
                continue;
            }
            $this->sleepSeconds($this->config->pollIntervalSeconds);
        }
        $this->logger->log('info', 'worker.poll.stopped', ['worker_id' => $identity['worker_uuid'], 'active_job_id' => $active['job_id'] ?? null, 'cycles' => $cycles]);
    }

    private function requireCredential(): string
    {
        $token = $this->credentials->retrieve($this->config->credentialReference);
        if ($token === null || $token === '') throw new WorkerStateException('Worker credential is unavailable. Run activate first.');
        $this->logger->rememberSecret($token);
        return $token;
    }

    private function ensureWritableDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) throw new WorkerStateException('A local worker directory is not writable.');
        $probe = $directory.'/'.bin2hex(random_bytes(5)).'.tmp';
        if (@file_put_contents($probe, '') === false) throw new WorkerStateException('A local worker directory is not writable.');
        @unlink($probe);
    }

    private function sleepSeconds(int $seconds): void
    {
        $deadline = microtime(true) + max(1, $seconds);
        while (! $this->shutdown->requested() && microtime(true) < $deadline) usleep(200_000);
    }

    private function executeJob(string $token, array $job, string $workerId, bool $throwOnFailure = false): int
    {
        if ($this->fetcher === null) {
            $this->spool->createEntry((int)$job['job_id'],(int)$job['attempt_id'],['lease_generation'=>(int)$job['lease_generation'],'state'=>'communication_only']);
            $this->api->heartbeat($token,(int)$job['job_id'],(int)$job['attempt_id'],$job['lease_token']);
            $this->logger->log('info','job.communication_only',['worker_id'=>$workerId,'job_id'=>(int)$job['job_id'],'attempt_id'=>(int)$job['attempt_id']]);
            return strtotime((string)$job['lease_expires_at']) ?: time();
        }
        $lastHeartbeat = microtime(true); $leaseGeneration = (int)$job['lease_generation']; $leaseExpiresAt=strtotime((string)$job['lease_expires_at']) ?: time();
        $requestId=RequestKey::uuid();
        $heartbeat = function() use ($token,$job,$workerId,&$lastHeartbeat,$leaseGeneration,&$leaseExpiresAt): void {
            if (microtime(true)-$lastHeartbeat < $this->config->heartbeatIntervalSeconds && time() < $leaseExpiresAt-5) return;
            $result=$this->api->heartbeat($token,(int)$job['job_id'],(int)$job['attempt_id'],$job['lease_token']);
            if ((int)$result['lease_generation'] !== $leaseGeneration) throw new WorkerStateException('Job lease fencing generation changed.');
            $leaseExpiresAt=strtotime((string)$result['lease_expires_at']) ?: $leaseExpiresAt;
            $lastHeartbeat=microtime(true);
            $this->logger->log('info','heartbeat.sent',['worker_id'=>$workerId,'job_id'=>(int)$job['job_id'],'attempt_id'=>(int)$job['attempt_id'],'lease_generation'=>$leaseGeneration]);
        };
        $startedAt=microtime(true);
        try {
            $result=$this->fetcher->fetch($job,$heartbeat);
            $this->logger->log('info','fetch.completed',['worker_id'=>$workerId,'job_id'=>(int)$job['job_id'],'attempt_id'=>(int)$job['attempt_id'],'request_id'=>$requestId,'requested_url'=>$result->data['requested_url'],'final_url'=>$result->data['final_url'],'http_status'=>$result->data['status_code'],'duration_ms'=>$result->data['duration_ms'],'failure_code'=>null,'artifact_reference'=>$result->data['artifact_reference'],'sha256'=>$result->data['sha256']]);
            fwrite(STDOUT,'Fetch complete: '.$result->data['content_length'].' bytes; artifact '.$result->data['artifact_reference'].PHP_EOL);
            $entry = substr((string) $result->data['artifact_reference'], 0, strpos((string) $result->data['artifact_reference'], '/'));
            $bytes = $this->spool->readPayload($entry);
            $artifactId = $this->stableUuid($job['job_id'].':'.$job['attempt_id'].':'.$result->data['sha256']);
            $uploadKey = 'artifact:'.$job['job_id'].':'.$job['attempt_id'].':'.$result->data['sha256'];
            $metadata = ['lease_generation' => $leaseGeneration, 'artifact_id' => $artifactId, 'sha256' => $result->data['sha256'],
                'content_type' => $result->data['content_type'], 'content_length' => $result->data['content_length'],
                'requested_url' => $result->data['requested_url'], 'final_url' => $result->data['final_url'], 'fetched_at' => $result->data['fetched_at']];
            $this->api->uploadArtifact($token, (int) $job['job_id'], (int) $job['attempt_id'], $metadata, $bytes, $uploadKey);
            $this->api->submitFetchResult($token, (int) $job['job_id'], (int) $job['attempt_id'], array_merge($result->data, ['lease_generation' => $leaseGeneration, 'artifact_id' => $artifactId]), 'result:'.$job['job_id'].':'.$job['attempt_id']);
            $this->spool->delete($entry);
        } catch (FetchFailure $failure) {
            $fetchResult=['outcome'=>'failed','job_id'=>(int)$job['job_id'],'attempt_id'=>(int)$job['attempt_id'],'requested_url'=>$this->fetcher->safeLogUrl((string)$job['requested_url']),'final_url'=>null,'status_code'=>$failure->statusCode,'content_type'=>null,'content_length'=>0,'sha256'=>null,'artifact_reference'=>null,'fetched_at'=>gmdate('c'),'redirects'=>[],'robots_result'=>in_array($failure->failureCode,['ROBOTS_BLOCKED','ROBOTS_UNAVAILABLE'],true)?$failure->failureCode:null,'duration_ms'=>(int)((microtime(true)-$startedAt)*1000),'failure_code'=>$failure->failureCode,'failure_category'=>$failure->failureCode==='HTTP_429'?'RATE_LIMITED':null,'retryable'=>$failure->retryable];
            $this->logger->log('warning','fetch.failed',['worker_id'=>$workerId,'job_id'=>(int)$job['job_id'],'attempt_id'=>(int)$job['attempt_id'],'request_id'=>$requestId,'requested_url'=>$fetchResult['requested_url'],'http_status'=>$failure->statusCode,'duration_ms'=>$fetchResult['duration_ms'],'failure_code'=>$failure->failureCode,'retryable'=>$failure->retryable,'fetch_result'=>$fetchResult]);
            fwrite(STDERR,'Fetch failed: '.$failure->failureCode.PHP_EOL);
            $fetchResult['lease_generation'] = $leaseGeneration;
            $this->api->submitFetchResult($token, (int) $job['job_id'], (int) $job['attempt_id'], $fetchResult, 'result:'.$job['job_id'].':'.$job['attempt_id']);
        }
        return $leaseExpiresAt;
    }

    private function stableUuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
