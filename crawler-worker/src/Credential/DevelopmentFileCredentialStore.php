<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Credential;

use Scholarship\CrawlerWorker\Exception\LocalStorageException;

/** Plaintext adapter for disposable local development only. Never use in production. */
final class DevelopmentFileCredentialStore implements CredentialStoreInterface
{
    public function __construct(private readonly string $path) {}

    public function store(string $reference, string $credential): void
    {
        $data = $this->readAll();
        $data[$reference] = $credential;
        $this->writeAll($data);
    }

    public function retrieve(string $reference): ?string
    {
        $value = $this->readAll()[$reference] ?? null;
        return is_string($value) ? $value : null;
    }

    public function delete(string $reference): void
    {
        $data = $this->readAll();
        unset($data[$reference]);
        $this->writeAll($data);
    }

    public function exists(string $reference): bool
    {
        return $this->retrieve($reference) !== null;
    }

    private function readAll(): array
    {
        if (! is_file($this->path)) return [];
        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data)) throw new LocalStorageException('Development credential file is unreadable.');
        return $data;
    }

    private function writeAll(array $data): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new LocalStorageException('Credential storage directory is not writable.');
        }
        $temporary = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) throw new LocalStorageException('Could not write development credential storage.');
        @chmod($temporary, 0600);
        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new LocalStorageException('Could not finalize development credential storage.');
        }
        @chmod($this->path, 0600);
    }
}
