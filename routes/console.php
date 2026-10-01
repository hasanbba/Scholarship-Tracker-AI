<?php

use App\Services\Crawler\ExpireCrawlLeasesService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('crawler:reap-expired-leases {--limit=200}', function (ExpireCrawlLeasesService $leases) {
    $count = $leases->reap(max(1, (int) $this->option('limit')));
    $this->info("Reaped {$count} expired crawler lease(s).");
})->purpose('Requeue or fail crawler jobs with expired leases');
