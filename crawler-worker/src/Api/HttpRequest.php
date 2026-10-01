<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

final class HttpRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly int $connectTimeoutSeconds,
        public readonly int $requestTimeoutSeconds,
        public readonly int $maxResponseBytes,
    ) {}
}
