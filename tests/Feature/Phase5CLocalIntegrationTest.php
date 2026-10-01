<?php

namespace Tests\Feature;

use App\Models\CrawlJob;
use App\Models\CrawlerWorker;
use App\Models\CrawlerWorkerCredential;
use App\Models\CrawlerEvent;
use App\Models\RawObservation;
use App\Models\ScholarshipSource;
use App\Services\Crawler\CrawlerDueSourceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Scholarship\CrawlerWorker\Api\CrawlerApiClient;
use Scholarship\CrawlerWorker\Api\CurlTransport;
use Scholarship\CrawlerWorker\Api\RetryPolicy;
use Scholarship\CrawlerWorker\Api\SleeperInterface;
use Scholarship\CrawlerWorker\Cli\WorkerApplication;
use Scholarship\CrawlerWorker\Config\WorkerConfig;
use Scholarship\CrawlerWorker\Credential\CredentialStoreInterface;
use Scholarship\CrawlerWorker\Fetch\DnsResolverInterface;
use Scholarship\CrawlerWorker\Fetch\FetchHttpClientInterface;
use Scholarship\CrawlerWorker\Fetch\IpAddressPolicy;
use Scholarship\CrawlerWorker\Fetch\RobotsPolicy;
use Scholarship\CrawlerWorker\Fetch\SafeFetcher;
use Scholarship\CrawlerWorker\Fetch\UrlPolicy;
use Scholarship\CrawlerWorker\Logging\StructuredLogger;
use Scholarship\CrawlerWorker\Spool\LocalSpool;
use Scholarship\CrawlerWorker\Support\ShutdownController;
use Scholarship\CrawlerWorker\Support\WorkerIdentityStore;
use Tests\TestCase;

class Phase5CLocalIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_real_worker_api_handoff_observation_processing_idempotency_and_changed_content(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName(), 'Run this live integration gate against scholarship_tracker_test on MySQL.');
        $workerRoot = base_path('crawler-worker');
        $workingDirectory = getcwd();
        require_once $workerRoot.'/vendor/autoload.php';
        $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase5c-local-e2e-'.bin2hex(random_bytes(5));
        mkdir($temporary, 0700, true);
        $apiProcess = null;
        $fixtureProcess = null;
        $artifactRefs = [];
        try {
            $fixturePort = $this->freePort();
            $apiPort = $this->freePort();
            $fixtureNonce = bin2hex(random_bytes(5));
            $versionA = '<html><body>Scholarship fixture version A '.$fixtureNonce.'</body></html>';
            $versionB = '<html><body>Scholarship fixture version B changed '.$fixtureNonce.'</body></html>';
            file_put_contents($temporary.'/fixture.html', $versionA);
            file_put_contents($temporary.'/router.php', <<<'PHP'
<?php
$path = $_SERVER['HTTP_X_FIXTURE_PATH'] ?? '/';
if ($path === '/robots.txt') { http_response_code(404); exit; }
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__.'/fixture.html');
PHP);
            $fixtureProcess = $this->startServer([PHP_BINARY, '-S', '127.0.0.1:'.$fixturePort, $temporary.'/router.php'], $temporary.'/fixture.log');
            $fixtureBase = 'http://127.0.0.1:'.$fixturePort;
            $this->waitForHttp($fixtureBase.'/health');
            $apiProcess = $this->startServer([PHP_BINARY, '-S', '127.0.0.1:'.$apiPort, '-t', public_path(), public_path('index.php')], $temporary.'/laravel.log');
            $apiBase = 'http://127.0.0.1:'.$apiPort;
            $this->waitForHttp($apiBase.'/api/v1/health');

            $worker = CrawlerWorker::query()->create(['label' => 'Phase 5C local E2E', 'status' => 'active', 'activated_at' => now(), 'last_heartbeat_at' => now()]);
            $token = bin2hex(random_bytes(32));
            CrawlerWorkerCredential::query()->create(['worker_id' => $worker->id, 'token_hash' => hash('sha256', $token), 'scopes' => ['workers.me', 'jobs.claim', 'jobs.heartbeat', 'jobs.results'], 'expires_at' => now()->addHour()]);
            $url = 'https://phase5c-fixture.example.test/scholarships/current';
            $source = ScholarshipSource::query()->create([
                'source_type' => 'other_official', 'source_name' => 'Phase 5C local fixture', 'source_url' => $url,
                'source_url_hash' => hash('sha256', $url), 'status' => 'active', 'crawl_enabled' => true,
                'crawl_method' => 'http', 'crawl_frequency' => 'daily', 'robots_policy' => 'allowed', 'allowed_path_prefix' => '/scholarships',
                'source_concurrency_limit' => 1,
            ]);

            $values = require $workerRoot.'/config/worker.php';
            $values = array_replace($values, [
                'api_base_url' => $apiBase, 'environment' => 'development', 'allow_insecure_local_api' => true,
                'credential_store' => 'file-dev', 'credential_reference' => 'phase5c-local-e2e',
                'request_timeout_seconds' => 10, 'connect_timeout_seconds' => 3, 'heartbeat_interval_seconds' => 3,
                'max_api_retries' => 1, 'retry_base_delay_ms' => 10, 'retry_max_delay_ms' => 50,
                'max_spool_entry_bytes' => 4194304, 'spool_directory' => $temporary.'/spool',
                'log_directory' => $temporary.'/worker-logs', 'identity_file' => $temporary.'/identity.json',
                'development_credential_file' => $temporary.'/worker-credential.json', 'fetch_connect_timeout_seconds' => 3,
                'fetch_timeout_seconds' => 10, 'fetch_operation_timeout_seconds' => 20, 'max_fetch_bytes' => 4194304,
                'minimum_source_delay_ms' => 0,
            ]);
            $config = WorkerConfig::fromArray($workerRoot, $values);
            $config->validateForApi();
            $logger = new StructuredLogger($config->logDirectory, false);
            $credentialStore = new class($token) implements CredentialStoreInterface {
                public function __construct(private string $token) {}
                public function store(string $reference, string $credential): void { $this->token = $credential; }
                public function retrieve(string $reference): ?string { return $this->token; }
                public function delete(string $reference): void { $this->token = ''; }
                public function exists(string $reference): bool { return $this->token !== ''; }
            };
            $identity = new WorkerIdentityStore($config->identityFile);
            $identity->save(['worker_uuid' => $worker->uuid, 'label' => $worker->label, 'api_base_url' => $apiBase, 'expires_at' => now()->addHour()->toIso8601String()]);
            $sleeper = new class implements SleeperInterface { public function sleepMilliseconds(int $milliseconds): void {} };
            $api = new CrawlerApiClient($config, new CurlTransport(), new RetryPolicy(1, 10, 50, $sleeper), $logger, static fn () => $worker->uuid);
            $dns = new class implements DnsResolverInterface { public function resolve(string $hostname): array { return ['93.184.216.34']; } };
            $fetchHttp = new class($fixtureBase) implements FetchHttpClientInterface {
                public function __construct(private string $fixtureBase) {}
                public function get(array $target, array $headers, int $maxBytes, callable $onChunk, ?callable $heartbeat = null): array
                {
                    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => "X-Fixture-Path: ".($target['path'] ?? '/')."\r\n", 'timeout' => 5, 'ignore_errors' => true]]);
                    $body = file_get_contents($this->fixtureBase.'/fixture', false, $context);
                    if (! is_string($body)) throw new \RuntimeException('Local fixture HTTP request failed.');
                    preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $match);
                    $status = (int) ($match[1] ?? 0);
                    $responseHeaders = [];
                    foreach ($http_response_header ?? [] as $line) if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $responseHeaders[strtolower(trim($key))] = trim($value); }
                    if (strlen($body) > $maxBytes) throw new \RuntimeException('Local fixture exceeded the fetch cap.');
                    if ($body !== '') $onChunk($body, $status);
                    $heartbeat && $heartbeat();
                    return ['status' => $status, 'headers' => $responseHeaders, 'bytes' => strlen($body), 'duration_ms' => 1];
                }
            };
            $spool = new LocalSpool($config->spoolDirectory, $config->maxSpoolEntryBytes);
            $fetcher = new SafeFetcher(new UrlPolicy(new IpAddressPolicy(), $dns), $fetchHttp, new RobotsPolicy(), new RetryPolicy(0, 1, 5, $sleeper), $spool, $config->maxFetchBytes, $config->maxRedirects, $config->allowedContentTypes, 0, $config->fetchOperationTimeoutSeconds);
            $application = new WorkerApplication($config, $api, $credentialStore, $identity, $spool, $logger, new ShutdownController($logger), $fetcher);

            $dispatch = app(CrawlerDueSourceService::class)->dispatchDue();
            $this->assertSame(1, $dispatch['scheduled']);
            $firstJob = CrawlJob::query()->where('source_id', $source->id)->where('state', 'queued')->firstOrFail();
            $application->once();
            $firstAttempt = $firstJob->fresh()->currentAttempt;
            $artifactRefs[] = $firstAttempt->artifact_ref;
            $this->assertSame('completed', $firstAttempt->state);
            $this->assertSame('completed', $firstJob->fresh()->state);
            $this->assertSame(hash('sha256', $versionA), $firstAttempt->content_hash);
            $firstObservationId = $firstAttempt->observation_id;
            $this->assertSame('invalid', $firstObservationId ? \App\Models\ProcessingRun::query()->where('observation_id', $firstObservationId)->value('status') : null);
            $this->assertTrue(Storage::disk('local')->exists($firstAttempt->artifact_ref), 'Artifact must exist on the private local disk.');

            $secondJob = $this->createJob($source, 'phase5c-live-identical');
            $application->once();
            $secondAttempt = $secondJob->fresh()->currentAttempt;
            $artifactRefs[] = $secondAttempt->artifact_ref;
            $this->assertSame($firstObservationId, $secondAttempt->observation_id);
            $this->assertDatabaseCount('raw_observations', 1);

            file_put_contents($temporary.'/fixture.html', $versionB);
            $thirdJob = $this->createJob($source, 'phase5c-live-changed');
            $application->once();
            $thirdAttempt = $thirdJob->fresh()->currentAttempt;
            $artifactRefs[] = $thirdAttempt->artifact_ref;
            $this->assertNotSame($firstObservationId, $thirdAttempt->observation_id);
            $this->assertDatabaseCount('raw_observations', 2);
            $this->assertNotSame($firstAttempt->content_hash, $thirdAttempt->content_hash);
            $this->assertSame('completed', $thirdAttempt->state);
            $this->assertSame('completed', $thirdJob->fresh()->state);

            $replay = $api->submitFetchResult($token, $thirdJob->id, $thirdAttempt->id, $thirdAttempt->fetch_result, $thirdAttempt->result_idempotency_key);
            $this->assertSame($thirdAttempt->observation_id, $replay['observation_id']);
            $this->assertDatabaseCount('raw_observations', 2);
            $this->assertGreaterThanOrEqual(3, CrawlerEvent::query()->where('event_type', 'artifact.uploaded')->count());
            $this->assertGreaterThanOrEqual(3, CrawlerEvent::query()->where('event_type', 'processing.dispatched')->count());
            $this->assertGreaterThanOrEqual(3, CrawlerEvent::query()->where('event_type', 'job.completed')->count());
            $this->assertSame('crawler', RawObservation::query()->findOrFail($firstObservationId)->producer_type);
        } finally {
            $this->stopServer($fixtureProcess);
            $this->stopServer($apiProcess);
            foreach (array_unique(array_filter($artifactRefs)) as $artifactRef) Storage::disk('local')->delete($artifactRef);
            \Illuminate\Support\Facades\File::deleteDirectory($temporary);
            if (is_string($workingDirectory)) @chdir($workingDirectory);
            foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendorDirectory => $loader) {
                if (realpath($vendorDirectory) === realpath($workerRoot.'/vendor')) $loader->unregister();
            }
        }
    }

    private function createJob(ScholarshipSource $source, string $key): CrawlJob
    {
        return CrawlJob::query()->create(['source_id' => $source->id, 'idempotency_key' => $key, 'state' => 'queued', 'priority' => 0,
            'requested_url' => $source->source_url, 'available_at' => now(), 'max_attempts' => 3]);
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (! is_resource($socket)) $this->fail('Could not reserve a temporary local integration-test port.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr(strrchr((string) $address, ':'), 1);
    }

    private function startServer(array $command, string $logPath)
    {
        $log = fopen($logPath, 'ab');
        $process = proc_open($command, [['pipe', 'r'], $log, $log], $pipes, base_path());
        if (! is_resource($process)) $this->fail('Could not start a temporary local HTTP integration server.');
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        return $process;
    }

    private function waitForHttp(string $url): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2]);
            curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($status >= 200 && $status < 500) return;
            usleep(100_000);
        }
        $this->fail('Temporary local HTTP server did not become ready: '.$url);
    }

    private function stopServer(mixed $process): void
    {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
}
