<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
final class ResponseSizeGuard
{
    private int $bytes=0; private bool $exceeded=false;
    public function __construct(private readonly int $maximumBytes) {}
    public function accept(string $chunk):bool { $this->bytes+=strlen($chunk);if($this->bytes>$this->maximumBytes){$this->exceeded=true;return false;}return true; }
    public function bytes():int { return $this->bytes; }
    public function exceeded():bool { return $this->exceeded; }
}
