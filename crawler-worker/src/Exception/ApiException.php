<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Exception;

class ApiException extends WorkerException
{
    public function __construct(string $message, public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }
}
