<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

final class RetryPolicy
{
    public function __construct(
        private readonly int $maxRetries,
        private readonly int $baseDelayMs,
        private readonly int $maxDelayMs,
        private readonly SleeperInterface $sleeper,
        private readonly ?\Closure $jitter = null,
    ) {}

    public function maxRetries(): int { return $this->maxRetries; }

    public function retryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
    }

    public function pause(int $retryNumber, ?string $retryAfter = null): int
    {
        $serverDelay = $this->retryAfterMilliseconds($retryAfter);
        $delay = $serverDelay ?? min($this->maxDelayMs, $this->baseDelayMs * (2 ** max(0, $retryNumber - 1)));
        $delay = min($this->maxDelayMs, max(0, $delay));
        if ($serverDelay === null && $this->jitter !== null && $delay > 0) {
            $delay = (int) min($this->maxDelayMs, max(0, ($this->jitter)($delay)));
        } elseif ($serverDelay === null && $delay > 0) {
            $delay = random_int((int) floor($delay * 0.8), $delay);
        }
        $this->sleeper->sleepMilliseconds($delay);
        return $delay;
    }

    private function retryAfterMilliseconds(?string $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (ctype_digit(trim($value))) return ((int) trim($value)) * 1000;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : max(0, ($timestamp - time()) * 1000);
    }
}
