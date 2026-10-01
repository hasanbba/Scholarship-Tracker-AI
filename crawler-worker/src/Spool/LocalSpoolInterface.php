<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Spool;

interface LocalSpoolInterface
{
    public function createEntry(int $jobId, int $attemptId, array $metadata = []): string;
    public function writeBounded(string $entryId, string $bytes): void;
    public function readMetadata(string $entryId): array;
    public function readPayload(string $entryId): string;
    public function updateMetadata(string $entryId, array $metadata): void;
    public function finalize(string $entryId): void;
    public function delete(string $entryId): void;
    public function cleanupOlderThan(int $timestamp): int;
}
