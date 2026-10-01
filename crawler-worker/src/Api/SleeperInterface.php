<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

interface SleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void;
}
