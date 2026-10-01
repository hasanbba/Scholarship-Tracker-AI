<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Support;

use Scholarship\CrawlerWorker\Exception\LocalStorageException;

final class WorkerIdentityStore
{
    public function __construct(private readonly string $path) {}

    public function load(): ?array
    {
        if (! is_file($this->path)) return null;
        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || ! is_string($data['worker_uuid'] ?? null) || ! is_string($data['api_base_url'] ?? null)) {
            throw new LocalStorageException('Worker identity file is invalid.');
        }
        return $data;
    }

    public function save(array $identity): void
    {
        foreach (['worker_uuid', 'label', 'api_base_url', 'expires_at'] as $field) {
            if (! is_string($identity[$field] ?? null)) throw new LocalStorageException('Worker identity is incomplete.');
        }
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) throw new LocalStorageException('Worker state directory is not writable.');
        $temporary = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($temporary, $json, LOCK_EX) === false || ! @rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new LocalStorageException('Could not save worker identity.');
        }
        @chmod($this->path, 0600);
    }

    public function delete(): void
    {
        if (is_file($this->path) && ! @unlink($this->path)) throw new LocalStorageException('Could not delete worker identity.');
    }
}
