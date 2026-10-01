<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
interface FetchHttpClientInterface
{
    /** Streams bytes to callback with the current HTTP status. Callback returns false to abort. */
    public function get(array $target, array $headers, int $maxBytes, callable $onChunk, ?callable $heartbeat = null): array;
}
