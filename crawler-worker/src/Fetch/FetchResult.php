<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
final class FetchResult implements \JsonSerializable
{
    public function __construct(public readonly array $data) {}
    public function jsonSerialize(): array { return $this->data; }
}
