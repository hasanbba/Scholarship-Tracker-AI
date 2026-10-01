<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
final class FetchFailure extends \RuntimeException
{
    public function __construct(public readonly string $failureCode, public readonly bool $retryable = false, string $message = '', public readonly ?int $statusCode = null) { parent::__construct($message ?: $failureCode); }
}
