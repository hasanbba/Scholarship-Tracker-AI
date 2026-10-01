<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $durationMs = 0,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
