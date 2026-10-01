<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Spool;

use Scholarship\CrawlerWorker\Exception\LocalStorageException;

final class LocalSpool implements LocalSpoolInterface
{
    public function __construct(private readonly string $directory, private readonly int $maxEntryBytes) {}

    public function createEntry(int $jobId, int $attemptId, array $metadata = []): string
    {
        if ($jobId < 1 || $attemptId < 1) throw new LocalStorageException('Job and attempt identifiers must be positive.');
        $entryId = $jobId.'-'.$attemptId;
        $path = $this->entryPath($entryId);
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) throw new LocalStorageException('Spool directory is not writable.');
        if (! @mkdir($path, 0700)) throw new LocalStorageException('Spool entry already exists or cannot be created.');
        $record = ['entry_id' => $entryId, 'job_id' => $jobId, 'attempt_id' => $attemptId, 'state' => 'open', 'bytes' => 0, 'created_at' => gmdate('c'), 'metadata' => $metadata];
        $this->writeMetadata($path, $record);
        if (@file_put_contents($path.'/payload.part', '') === false) throw new LocalStorageException('Could not initialize spool payload.');
        return $entryId;
    }

    public function writeBounded(string $entryId, string $bytes): void
    {
        $path = $this->entryPath($entryId);
        $lock = @fopen($path.'/entry.lock', 'c');
        if (! is_resource($lock)) throw new LocalStorageException('Spool entry lock is unavailable.');
        try {
            if (! flock($lock, LOCK_EX)) throw new LocalStorageException('Could not lock spool entry.');
            $metadata = $this->readMetadata($entryId);
            if ($metadata['state'] !== 'open') throw new LocalStorageException('Spool entry is already finalized.');
            clearstatcache(true, $path.'/payload.part');
            $existingSize = is_file($path.'/payload.part') ? (int) filesize($path.'/payload.part') : 0;
            $newSize = max((int) $metadata['bytes'], $existingSize) + strlen($bytes);
            if ($newSize > $this->maxEntryBytes) throw new LocalStorageException('Spool entry exceeds the configured size limit.');
            if (is_link($path.'/payload.part')) throw new LocalStorageException('Spool payload must not be a symbolic link.');
            $handle = @fopen($path.'/payload.part', 'ab');
            if (! is_resource($handle)) throw new LocalStorageException('Spool payload is not writable.');
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes)) throw new LocalStorageException('Could not write spool payload.');
                fflush($handle);
            } finally {
                fclose($handle);
            }
            $metadata['bytes'] = $newSize;
            $this->writeMetadata($path, $metadata);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function readMetadata(string $entryId): array
    {
        $path = $this->entryPath($entryId);
        if (is_link($path.'/metadata.json')) throw new LocalStorageException('Spool metadata must not be a symbolic link.');
        $raw = @file_get_contents($path.'/metadata.json');
        $metadata = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($metadata)) throw new LocalStorageException('Spool metadata is missing or invalid.');
        return $metadata;
    }

    public function readPayload(string $entryId): string
    {
        $path = $this->entryPath($entryId).'/payload.bin';
        if (is_link($path) || ! is_file($path)) throw new LocalStorageException('Finalized spool payload is unavailable.');
        $size = filesize($path);
        if (! is_int($size) || $size < 1 || $size > $this->maxEntryBytes) throw new LocalStorageException('Spool payload has an invalid size.');
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) !== $size) throw new LocalStorageException('Spool payload could not be read completely.');
        return $bytes;
    }

    public function updateMetadata(string $entryId, array $metadata): void
    {
        $path = $this->entryPath($entryId);
        if (!is_dir($path) || is_link($path)) throw new LocalStorageException('Spool entry is unavailable.');
        $metadata['entry_id'] = $entryId;
        $this->writeMetadata($path, $metadata);
    }

    public function finalize(string $entryId): void
    {
        $path = $this->entryPath($entryId);
        $metadata = $this->readMetadata($entryId);
        if ($metadata['state'] !== 'open' || ! @rename($path.'/payload.part', $path.'/payload.bin')) throw new LocalStorageException('Could not finalize spool entry.');
        $metadata['state'] = 'finalized';
        $metadata['finalized_at'] = gmdate('c');
        $this->writeMetadata($path, $metadata);
    }

    public function delete(string $entryId): void
    {
        $path = $this->entryPath($entryId);
        if (! is_dir($path)) return;
        foreach (['payload.part', 'payload.bin', 'metadata.json', 'entry.lock'] as $file) if (is_file($path.'/'.$file)) @unlink($path.'/'.$file);
        if (! @rmdir($path)) throw new LocalStorageException('Could not remove spool entry.');
    }

    public function cleanupOlderThan(int $timestamp): int
    {
        if (! is_dir($this->directory)) return 0;
        $removed = 0;
        foreach (new \DirectoryIterator($this->directory) as $item) {
            if (! $item->isDir() || $item->isLink() || $item->isDot() || ! preg_match('/^\d+-\d+$/', $item->getFilename()) || $item->getMTime() >= $timestamp) continue;
            $this->delete($item->getFilename());
            $removed++;
        }
        return $removed;
    }

    private function entryPath(string $entryId): string
    {
        if (! preg_match('/^\d+-\d+$/', $entryId)) throw new LocalStorageException('Invalid spool entry identifier.');
        $path = rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.$entryId;
        if (is_link($path)) throw new LocalStorageException('Spool entries must not be symbolic links.');
        return $path;
    }

    private function writeMetadata(string $path, array $metadata): void
    {
        $temporary = $path.'/metadata.'.bin2hex(random_bytes(4)).'.tmp';
        try { $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); } catch (\JsonException) { throw new LocalStorageException('Spool metadata is not encodable.'); }
        if (@file_put_contents($temporary, $json, LOCK_EX) === false || ! @rename($temporary, $path.'/metadata.json')) {
            @unlink($temporary);
            throw new LocalStorageException('Could not save spool metadata.');
        }
        @chmod($path.'/metadata.json', 0600);
    }
}
