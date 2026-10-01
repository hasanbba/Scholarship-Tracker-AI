<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

final class NativeSleeper implements SleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
