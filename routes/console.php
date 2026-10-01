<?php

use App\Services\Crawler\ExpireCrawlLeasesService;
use App\Services\Crawler\CrawlerDueSourceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('crawler:reap-expired-leases {--limit=200}', function (ExpireCrawlLeasesService $leases) {
    $count = $leases->reap(max(1, (int) $this->option('limit')));
    $this->info("Reaped {$count} expired crawler lease(s).");
})->purpose('Requeue or fail crawler jobs with expired leases');

Artisan::command('crawler:dispatch-due {--limit=200}', function (CrawlerDueSourceService $sources) {
    $stats = $sources->dispatchDue(max(1, (int) $this->option('limit')));
    $this->info(sprintf(
        'Due-source dispatch finished: %d scanned, %d scheduled, %d suppressed, %d not due, %d manual, %d invalid frequency, %d configuration skipped, %d errors.',
        $stats['scanned'], $stats['scheduled'], $stats['suppressed'], $stats['not_due'], $stats['manual'],
        $stats['invalid_frequency'], $stats['configuration_skipped'], $stats['errors'],
    ));
    return $stats['errors'] > 0 ? \Symfony\Component\Console\Command\Command::FAILURE : \Symfony\Component\Console\Command\Command::SUCCESS;
})->purpose('Create idempotent crawl jobs for eligible registered sources that are due');

Schedule::command('crawler:dispatch-due --limit=200')->hourly();
Schedule::command('crawler:reap-expired-leases --limit=200')->everyMinute();
