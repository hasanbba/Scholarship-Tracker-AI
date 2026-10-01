<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

use Scholarship\CrawlerWorker\Config\WorkerConfig;
use Scholarship\CrawlerWorker\Exception\ApiException;
use Scholarship\CrawlerWorker\Exception\AuthenticationException;
use Scholarship\CrawlerWorker\Exception\AuthorizationException;
use Scholarship\CrawlerWorker\Exception\NonRetryableApiException;
use Scholarship\CrawlerWorker\Exception\ProtocolException;
use Scholarship\CrawlerWorker\Exception\RetryableApiException;
use Scholarship\CrawlerWorker\Exception\TransportException;
use Scholarship\CrawlerWorker\Logging\StructuredLogger;

final class CrawlerApiClient implements CrawlerApiClientInterface
{
    public function __construct(
        private readonly WorkerConfig $config,
        private readonly HttpTransportInterface $transport,
        private readonly RetryPolicy $retry,
        private readonly StructuredLogger $logger,
        private readonly ?\Closure $workerIdResolver = null,
    ) {}

    public function activate(string $activationCode, int $protocolVersion, string $softwareVersion): array
    {
        $data = $this->request('POST', '/api/v1/crawler/workers/activate', null, [
            'activation_code' => $activationCode, 'protocol_version' => $protocolVersion, 'software_version' => $softwareVersion,
        ], retry: false, expectedStatus: 201);
        $worker = $data['worker'] ?? null;
        $token = $data['token'] ?? null;
        if (! is_array($worker) || ! is_string($worker['uuid'] ?? null) || ! is_string($worker['label'] ?? null)
            || ! is_string($worker['status'] ?? null) || ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/i', $token)
            || ! is_string($data['expires_at'] ?? null)) {
            throw new ProtocolException('Activation response did not match the Phase 5A schema.');
        }
        return ['worker' => $worker, 'token' => $token, 'expires_at' => $data['expires_at']];
    }

    public function getCurrentWorker(string $token): array
    {
        $data = $this->request('GET', '/api/v1/crawler/workers/me', $token);
        foreach (['uuid', 'label', 'status', 'protocol_version'] as $field) {
            if (! array_key_exists($field, $data)) throw new ProtocolException('Worker status response did not match the Phase 5A schema.');
        }
        return $data;
    }

    public function claimJob(string $token, string $claimRequestKey): ?array
    {
        $data = $this->request('POST', '/api/v1/crawler/jobs/claim', $token, ['claim_request_key' => $claimRequestKey]);
        if ($data === null) return null;
        foreach (['job_id', 'attempt_id', 'lease_generation', 'lease_token', 'lease_expires_at', 'requested_url', 'allowed_path_prefix', 'source_id', 'registered_source_url', 'source_concurrency_limit', 'robots_policy'] as $field) {
            if (! array_key_exists($field, $data)) throw new ProtocolException('Claim response did not match the Phase 5A schema.');
        }
        if (! is_numeric($data['job_id']) || ! is_numeric($data['attempt_id']) || ! is_numeric($data['lease_generation'])
            || ! is_string($data['requested_url']) || ! is_string($data['allowed_path_prefix'])
            || ! is_string($data['lease_expires_at']) || ! is_string($data['lease_token']) || ! preg_match('/^[a-f0-9]{64}$/i', $data['lease_token'])
            || ! is_numeric($data['source_id']) || ! is_string($data['registered_source_url']) || ! is_numeric($data['source_concurrency_limit'])
            || ! is_string($data['robots_policy']) || (int)$data['job_id']<1 || (int)$data['attempt_id']<1
            || (int)$data['source_id']<1 || (int)$data['source_concurrency_limit']<1 || (int)$data['source_concurrency_limit']>10
            || strlen($data['allowed_path_prefix'])>2048 || ! in_array($data['robots_policy'],['allowed','blocked','unknown','manual_review'],true)) {
            throw new ProtocolException('Claim response contained an invalid lease token.');
        }
        return $data;
    }

    public function heartbeat(string $token, int $jobId, int $attemptId, string $leaseToken): array
    {
        $data = $this->request('POST', "/api/v1/crawler/jobs/{$jobId}/attempts/{$attemptId}/heartbeat", $token, ['lease_token' => $leaseToken]);
        if (! is_string($data['lease_expires_at'] ?? null) || ! is_numeric($data['lease_generation'] ?? null)) {
            throw new ProtocolException('Heartbeat response did not match the Phase 5A schema.');
        }
        return $data;
    }

    public function uploadArtifact(string $token, int $jobId, int $attemptId, array $metadata, string $bytes, string $idempotencyKey): array
    {
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $metaHeader = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $this->request('POST', "/api/v1/crawler/jobs/{$jobId}/attempts/{$attemptId}/artifacts", $token, null, rawBody: $bytes, extraHeaders: [
            'Content-Type' => (string) ($metadata['content_type'] ?? 'application/octet-stream'), 'X-Crawler-Metadata' => $metaHeader, 'Idempotency-Key' => $idempotencyKey,
        ], expectedStatus: 201);
    }

    public function submitFetchResult(string $token, int $jobId, int $attemptId, array $result, string $idempotencyKey): array
    {
        return $this->request('POST', "/api/v1/crawler/jobs/{$jobId}/attempts/{$attemptId}/results", $token, $result, extraHeaders: ['Idempotency-Key' => $idempotencyKey]);
    }

    public function health(): array
    {
        return $this->request('GET', '/api/v1/health', null);
    }

    private function request(string $method, string $path, ?string $token = null, ?array $payload = null, bool $retry = true, ?int $expectedStatus = 200, ?string $rawBody = null, array $extraHeaders = []): ?array
    {
        $this->config->validateForApi();
        $requestId = self::uuid();
        $headers = ['Accept' => 'application/json', 'X-Request-ID' => $requestId, 'User-Agent' => 'ScholarshipCrawlerWorker/'.$this->config->softwareVersion];
        if ($payload !== null) $headers['Content-Type'] = 'application/json';
        if ($token !== null) $headers['Authorization'] = 'Bearer '.$token;
        $headers = array_merge($headers, $extraHeaders);
        try {
            $body = $rawBody ?? ($payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\JsonException) {
            throw new ProtocolException('Could not encode the API request.');
        }
        $request = new HttpRequest($method, $this->config->apiBaseUrl.$path, $headers, $body,
            $this->config->connectTimeoutSeconds, $this->config->requestTimeoutSeconds, $this->config->maxApiResponseBytes);
        $maxRetries = $retry ? $this->retry->maxRetries() : 0;
        for ($attempt = 0; ; $attempt++) {
            $start = hrtime(true);
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $exception) {
                $duration = (int) round((hrtime(true) - $start) / 1_000_000);
                if ($attempt < $maxRetries) {
                    $delay = $this->retry->pause($attempt + 1);
                    $this->logger->log('warning', 'api.retry', ['worker_id' => $this->workerId(), 'request_id' => $requestId, 'endpoint' => $path, 'http_status' => null, 'duration_ms' => $duration, 'retry_attempt' => $attempt + 1, 'delay_ms' => $delay, 'error_code' => 'transport']);
                    continue;
                }
                $this->logger->log('error', 'api.request.failed', ['worker_id' => $this->workerId(), 'request_id' => $requestId, 'endpoint' => $path, 'http_status' => null, 'duration_ms' => $duration, 'retry_attempt' => $attempt, 'error_code' => 'transport']);
                throw $exception;
            }
            $this->logger->log($response->status >= 400 ? 'warning' : 'info', 'api.request', [
                'worker_id' => $this->workerId(), 'request_id' => $requestId, 'endpoint' => $path, 'http_status' => $response->status,
                'duration_ms' => $response->durationMs, 'retry_attempt' => $attempt,
            ]);
            if ($retry && $attempt < $maxRetries && $this->retry->retryableStatus($response->status)) {
                $delay = $this->retry->pause($attempt + 1, $response->header('retry-after'));
                $this->logger->log('warning', 'api.retry', ['worker_id' => $this->workerId(), 'request_id' => $requestId, 'endpoint' => $path, 'http_status' => $response->status, 'duration_ms' => $response->durationMs, 'retry_attempt' => $attempt + 1, 'delay_ms' => $delay]);
                continue;
            }
            if ($this->retry->retryableStatus($response->status)) {
                throw new RetryableApiException('Laravel API transient failure; bounded retries exhausted.', $response->status);
            }
            if ($expectedStatus !== null && $response->status !== $expectedStatus) {
                $message = match ($response->status) {
                    401 => $path === '/api/v1/crawler/workers/activate' ? 'Activation code is invalid, expired, or already used.' : 'Worker authentication failed.',
                    403 => 'Worker is disabled or lacks the required scope.',
                    409 => 'Lease or request conflicts with current server state.',
                    422 => 'Laravel API rejected the request validation.',
                    default => 'Laravel API returned HTTP '.$response->status.'.',
                };
                if ($response->status === 401) {
                    $this->logger->log('warning', 'api.authentication_failed', ['worker_id' => $this->workerId(), 'request_id' => $requestId, 'endpoint' => $path, 'http_status' => 401]);
                    throw new AuthenticationException($message, 401);
                }
                if ($response->status === 403) {
                    $this->logger->log('warning', 'api.authorization_failed', ['worker_id' => $this->workerId(), 'request_id' => $requestId, 'endpoint' => $path, 'http_status' => 403]);
                    throw new AuthorizationException($message, 403);
                }
                if ($response->status === 409 || $response->status === 422 || $response->status === 400) throw new NonRetryableApiException($message, $response->status);
                throw new ApiException($message, $response->status);
            }
            try {
                $envelope = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ProtocolException('Laravel API returned malformed JSON.');
            }
            if (! is_array($envelope) || ! is_bool($envelope['success'] ?? null) || ! is_string($envelope['message'] ?? null)) {
                throw new ProtocolException('Laravel API response envelope is invalid.');
            }
            if ($envelope['success'] !== true || ! array_key_exists('data', $envelope)) {
                throw new ProtocolException('Laravel API success response is missing data.');
            }
            if (! is_array($envelope['data']) && $envelope['data'] !== null) {
                throw new ProtocolException('Laravel API data field has an invalid type.');
            }
            return $envelope['data'];
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    private function workerId(): ?string
    {
        if ($this->workerIdResolver === null) return null;
        $value = ($this->workerIdResolver)();
        return is_string($value) && $value !== '' ? $value : null;
    }
}
