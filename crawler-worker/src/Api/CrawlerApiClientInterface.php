<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

interface CrawlerApiClientInterface
{
    public function activate(string $activationCode, int $protocolVersion, string $softwareVersion): array;
    public function getCurrentWorker(string $token): array;
    public function claimJob(string $token, string $claimRequestKey): ?array;
    public function heartbeat(string $token, int $jobId, int $attemptId, string $leaseToken): array;
    public function uploadArtifact(string $token, int $jobId, int $attemptId, array $metadata, string $bytes, string $idempotencyKey): array;
    public function submitFetchResult(string $token, int $jobId, int $attemptId, array $result, string $idempotencyKey): array;
    public function health(): array;
}
