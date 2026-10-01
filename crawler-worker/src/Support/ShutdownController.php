<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Support;

use Scholarship\CrawlerWorker\Logging\StructuredLogger;

final class ShutdownController
{
    private bool $requested = false;

    public function __construct(private readonly StructuredLogger $logger) {}

    public function register(): void
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([defined('SIGINT') ? SIGINT : null, defined('SIGTERM') ? SIGTERM : null] as $signal) {
                if ($signal !== null) pcntl_signal($signal, function (int $signal): void { $this->requested = true; });
            }
        }
        register_shutdown_function(function (): void {
            $last = error_get_last();
            $this->logger->log('info', 'worker.shutdown', ['reason' => $this->requested ? 'signal' : ($last ? 'runtime_shutdown' : 'normal_exit')]);
        });
    }

    public function requested(): bool { return $this->requested; }
}
