<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Logging;

use Scholarship\CrawlerWorker\Exception\LocalStorageException;

final class StructuredLogger
{
    private array $secrets = [];

    public function __construct(private readonly string $directory, private readonly bool $console = true) {}

    public function rememberSecret(string $secret): void
    {
        if ($secret !== '') {
            $this->secrets[] = $secret;
        }
    }

    public function log(string $level, string $event, array $context = []): void
    {
        $record = $this->redact([
            'timestamp' => gmdate('c'), 'level' => $level, 'event' => $event,
            ...$context,
        ]);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($line)) {
            $line = '{"timestamp":"'.gmdate('c').'","level":"error","event":"log.encoding_failed"}';
        }
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new LocalStorageException('Log directory is not writable.');
        }
        $written = @file_put_contents($this->directory.'/worker.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new LocalStorageException('Could not write the structured log.');
        }
        if ($this->console) {
            fwrite(STDOUT, $line.PHP_EOL);
        }
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/token|authorization|activation.?code|password|secret|cookie|credential.?value/i', $key)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $child) {
                $safe[$childKey] = $this->redact($child, (string) $childKey);
            }
            return $safe;
        }
        if (is_string($value)) {
            foreach ($this->secrets as $secret) {
                $value = str_replace($secret, '[REDACTED]', $value);
            }
        }
        return $value;
    }
}
