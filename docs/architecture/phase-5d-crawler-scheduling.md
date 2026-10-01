# Phase 5D: Crawler scheduling

## Flow and scope

The source registry remains authoritative. `crawler:dispatch-due` scans active, enabled sources in descending `crawl_priority` order, then stable ID order. The dispatcher creates ordinary `crawl_jobs` through `CrawlJobService::createScheduled`; those jobs use the existing claim, lease, safe fetch, artifact, result, and observation pipeline. Scheduler-created URLs always come from the registered source. No queue worker, AI extraction, scholarship verification, or publication behavior is added.

## Due calculation

The most recent `completed` job with `completed_at` is the successful-crawl anchor. If there is no success yet, an eligible source is due from its `created_at`. Daily and weekly frequencies add one day or one week to the anchor. Monthly adds one calendar month without overflow (for example, January 31 becomes February 28). A source is due at or after its calculated timestamp.

The existing values `manual`, `daily`, `weekly`, and `monthly` are preserved. `manual` sources are ignored. Null or unknown frequencies fail closed and record one `source_frequency_invalid` event per source/frequency value. A source must be active, enabled, use the HTTP method, and have `robots_policy=allowed` to be scheduled.

## Duplicate, concurrency, and retry behavior

Before scheduling, the dispatcher suppresses a source that has a `queued`, `leased`, `processing`, `retry_pending`, `blocked`, or `manual_review` job. This avoids competing with manual work and with the existing retry/backoff lifecycle. Expired leases continue to be handled by `crawler:reap-expired-leases`.

Each source is locked with `SELECT ... FOR UPDATE` inside a transaction while eligibility, pending jobs, due time, and job creation are decided. The existing unique idempotency key (`schedule:{sourceId}:{frequency}-{slot}`) supplies an additional database guard. Job priority is copied from `crawl_priority`. Since dispatch permits at most one outstanding job per source, it cannot exceed that source's concurrency limit; the worker claim service remains responsible for claim-time concurrency enforcement.

A failed job never counts as success. A terminal `failed` job starts a cooldown equal to the configured frequency from its completion time; while cooling down, a fresh scheduled job is not created. A pending retry job suppresses normal scheduling until the existing retry lifecycle resolves it. A later successful job becomes the new schedule anchor. Historical jobs and attempts are not rewritten.

## Events and observability

New automatic work records the existing `job_created` event with `origin=scheduler` and a `job_scheduled` event with the frequency, due timestamp, and priority. Invalid frequency diagnostics are deduplicated. Routine not-due and duplicate-suppressed checks do not write events. The authorized admin endpoint `GET /api/v1/admin/crawler/sources/{source}/schedule` exposes last success, last attempt, next due, due status, and the oldest active job; existing job listing remains the source for queued, processing, retry, and failed job history.

## Scheduling and deployment

Laravel's scheduler dispatches due jobs hourly and reaps expired leases every minute. The command is repeatable and may also be invoked manually. A cPanel cron entry can run Laravel's scheduler every minute:

```cron
* * * * * cd /home/ACCOUNT/path-to-app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Use the PHP binary and application path configured for the cPanel account. No permanently running Laravel queue worker is required for scheduling. Windows standalone workers continue polling the existing jobs claim API and never access MySQL directly.

## Known limits

Monthly frequency means one calendar month after the anchor, not a fixed 30-day period. The failure cooldown is frequency-sized and does not replace or edit the retry policy. The endpoint reports derived schedule state; it does not provide a new dashboard. Deployment must configure cPanel cron and keep the Laravel scheduler enabled.
