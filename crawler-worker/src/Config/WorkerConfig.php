<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Config;

use Scholarship\CrawlerWorker\Exception\ConfigurationException;

final class WorkerConfig
{
    public function __construct(
        public readonly string $root,
        public readonly string $apiBaseUrl,
        public readonly string $environment,
        public readonly bool $allowInsecureLocalApi,
        public readonly string $credentialStore,
        public readonly string $credentialReference,
        public readonly string $workerUuid,
        public readonly int $protocolVersion,
        public readonly string $softwareVersion,
        public readonly int $connectTimeoutSeconds,
        public readonly int $requestTimeoutSeconds,
        public readonly int $heartbeatIntervalSeconds,
        public readonly int $pollIntervalSeconds,
        public readonly int $maxApiRetries,
        public readonly int $retryBaseDelayMs,
        public readonly int $retryMaxDelayMs,
        public readonly int $maxApiResponseBytes,
        public readonly int $maxSpoolEntryBytes,
        public readonly string $spoolDirectory,
        public readonly string $logDirectory,
        public readonly string $identityFile,
        public readonly string $developmentCredentialFile,
        public readonly int $fetchConnectTimeoutSeconds,
        public readonly int $fetchTimeoutSeconds,
        public readonly int $maxFetchBytes,
        public readonly int $maxRedirects,
        public readonly int $minimumSourceDelayMs,
        public readonly bool $allowHttpSources,
        public readonly array $allowedContentTypes,
        public readonly int $fetchOperationTimeoutSeconds,
    ) {}

    public static function load(string $root): self
    {
        $values = require $root.'/config/worker.php';
        $local = $root.'/config/local.php';
        if (is_file($local)) {
            $overrides = require $local;
            if (is_array($overrides)) {
                $values = array_replace($values, $overrides);
            }
        }
        if (empty($values['api_base_url']) && is_file($root.'/storage/worker-state.json')) {
            $state = json_decode((string) @file_get_contents($root.'/storage/worker-state.json'), true);
            if (is_array($state) && is_string($state['api_base_url'] ?? null)) $values['api_base_url'] = $state['api_base_url'];
        }

        return self::fromArray($root, $values);
    }

    public static function fromArray(string $root, array $values): self
    {
        $config = new self(
            root: $root,
            apiBaseUrl: rtrim(trim((string) ($values['api_base_url'] ?? '')), '/'),
            environment: (string) ($values['environment'] ?? 'development'),
            allowInsecureLocalApi: (bool) ($values['allow_insecure_local_api'] ?? false),
            credentialStore: (string) ($values['credential_store'] ?? ''),
            credentialReference: (string) ($values['credential_reference'] ?? 'ScholarshipTracker.CrawlerWorker'),
            workerUuid: (string) ($values['worker_uuid'] ?? ''),
            protocolVersion: (int) ($values['protocol_version'] ?? 1),
            softwareVersion: (string) ($values['software_version'] ?? '0.1.0'),
            connectTimeoutSeconds: (int) ($values['connect_timeout_seconds'] ?? 5),
            requestTimeoutSeconds: (int) ($values['request_timeout_seconds'] ?? 20),
            heartbeatIntervalSeconds: (int) ($values['heartbeat_interval_seconds'] ?? 30),
            pollIntervalSeconds: (int) ($values['poll_interval_seconds'] ?? 5),
            maxApiRetries: (int) ($values['max_api_retries'] ?? 3),
            retryBaseDelayMs: (int) ($values['retry_base_delay_ms'] ?? 250),
            retryMaxDelayMs: (int) ($values['retry_max_delay_ms'] ?? 5000),
            maxApiResponseBytes: (int) ($values['max_api_response_bytes'] ?? 1048576),
            maxSpoolEntryBytes: (int) ($values['max_spool_entry_bytes'] ?? 10485760),
            spoolDirectory: (string) ($values['spool_directory'] ?? $root.'/storage/spool'),
            logDirectory: (string) ($values['log_directory'] ?? $root.'/storage/logs'),
            identityFile: (string) ($values['identity_file'] ?? $root.'/storage/worker-state.json'),
            developmentCredentialFile: (string) ($values['development_credential_file'] ?? $root.'/storage/credentials.dev.json'),
            fetchConnectTimeoutSeconds: (int) ($values['fetch_connect_timeout_seconds'] ?? 5),
            fetchTimeoutSeconds: (int) ($values['fetch_timeout_seconds'] ?? 30),
            maxFetchBytes: (int) ($values['max_fetch_bytes'] ?? 4194304),
            maxRedirects: (int) ($values['max_redirects'] ?? 4),
            minimumSourceDelayMs: (int) ($values['minimum_source_delay_ms'] ?? 1000),
            allowHttpSources: (bool) ($values['allow_http_sources'] ?? false),
            allowedContentTypes: (array) ($values['allowed_content_types'] ?? ['text/html','application/xhtml+xml','application/json','application/pdf']),
            fetchOperationTimeoutSeconds: (int) ($values['fetch_operation_timeout_seconds'] ?? 90),
        );
        $config->validateLocal();

        return $config;
    }

    public function withApiBaseUrl(string $url): self
    {
        $copy = get_object_vars($this);
        $copy['apiBaseUrl'] = rtrim(trim($url), '/');
        return new self(...$copy);
    }

    public function validateForApi(): void
    {
        if ($this->apiBaseUrl === '') {
            throw new ConfigurationException('Set WORKER_API_BASE_URL or enter the API URL during activation.');
        }
        $parts = parse_url($this->apiBaseUrl);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ConfigurationException('API URL is invalid or contains forbidden URL components.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $localHost = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true);
        $localHttp = $scheme === 'http' && $localHost && $this->environment === 'development' && $this->allowInsecureLocalApi;
        if ($scheme !== 'https' && ! $localHttp) {
            throw new ConfigurationException('HTTPS is required except for explicitly enabled local development URLs.');
        }
        if (! in_array($this->credentialStore, ['windows', 'file-dev'], true)) {
            throw new ConfigurationException('credential_store must be windows or the explicitly development-only file-dev adapter.');
        }
        if ($this->credentialStore === 'file-dev' && $this->environment !== 'development') {
            throw new ConfigurationException('The file-dev credential adapter is only allowed in development mode.');
        }
    }

    private function validateLocal(): void
    {
        if ($this->protocolVersion !== 1 || $this->connectTimeoutSeconds < 1 || $this->requestTimeoutSeconds < $this->connectTimeoutSeconds
            || $this->heartbeatIntervalSeconds < 1 || $this->pollIntervalSeconds < 1 || $this->maxApiRetries < 0
            || $this->retryBaseDelayMs < 0 || $this->retryMaxDelayMs < $this->retryBaseDelayMs
            || $this->maxApiResponseBytes < 1024 || $this->maxSpoolEntryBytes < 1
            || $this->fetchConnectTimeoutSeconds < 1 || $this->fetchTimeoutSeconds < $this->fetchConnectTimeoutSeconds
            || $this->fetchTimeoutSeconds > 300 || $this->maxFetchBytes < 1 || $this->maxFetchBytes > 4194304
            || $this->fetchOperationTimeoutSeconds < $this->fetchTimeoutSeconds || $this->fetchOperationTimeoutSeconds > 600
            || $this->maxRedirects < 0 || $this->maxRedirects > 5 || $this->minimumSourceDelayMs < 0 || $this->minimumSourceDelayMs > 60000) {
            throw new ConfigurationException('Worker configuration has invalid timeout, retry, protocol, or size limits.');
        }
    }
}
