# Phase 5D verification and operations

## Local verification

Run commands from the Laravel project root. Point automated tests only at the isolated MySQL database `scholarship_tracker_test`; never use the development database `scholarship_tracker` for test migrations.

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'scholarship_tracker_test'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3307'
php artisan test --compact tests/Feature/CrawlerDueSourceTest.php
php artisan test --compact tests/Feature/Phase5CLocalIntegrationTest.php
php artisan schedule:list
```

The focused due-source tests cover disabled/manual/invalid configuration, initial due behavior, daily/weekly/monthly timing, repeated dispatch, priority, active and retry-pending suppression, failure cooldown, schedule status, and worker claim compatibility. The Phase 5C local integration test starts only loopback fixture/API servers; it now obtains its first job from the due-source dispatcher and carries it through the worker artifact/result/observation path. It does not contact external websites.

Before release, also run the existing Phase 5A, Phase 5B, Phase 5C, full Laravel/MySQL suite, standalone worker suite, PHP lint, Composer validation, and `git diff --check`. Preserve any unrelated dirty or untracked work while reviewing results.

## cPanel scheduler

Configure the account's cron to invoke Laravel's scheduler every minute (replace the account path and PHP binary with the values shown by cPanel):

```cron
* * * * * cd /home/ACCOUNT/path-to-app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Laravel dispatches due sources hourly and reaps expired crawler leases every minute. To inspect registered schedules, run `php artisan schedule:list`. To run a dispatch once manually, use `php artisan crawler:dispatch-due --limit=200`.

The command prints scanned, scheduled, suppressed, not-due, manual, invalid-frequency, configuration-skipped, and error counts. Nonzero per-source errors return a failing command status for cron monitoring. The external Windows worker must remain online and poll the existing claim API for jobs to be processed; scheduling itself does not require a queue worker.

## Database boundary

No source timestamp or scheduling table is required: due state is derived from registered source configuration and crawl history. For tests, verify `DB_DATABASE=scholarship_tracker_test` before running the Laravel suite. Do not run destructive migration commands against `scholarship_tracker`.
