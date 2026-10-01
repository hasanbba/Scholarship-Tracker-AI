<?php

namespace Tests\Feature;

use App\Models\CrawlerEvent;
use App\Models\CrawlerWorker;
use App\Models\CrawlJob;
use App\Models\ScholarshipSource;
use App\Services\Crawler\CrawlerDueSourceService;
use App\Services\Crawler\CrawlJobClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlerDueSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_manual_and_unknown_frequency_sources_fail_closed(): void
    {
        $disabled = $this->source(['crawl_enabled' => false]);
        $manual = $this->source(['crawl_frequency' => 'manual']);
        $invalid = $this->source(['crawl_frequency' => 'fortnightly']);
        $dispatcher = app(CrawlerDueSourceService::class);

        $first = $dispatcher->dispatchDue();
        $this->assertSame(2, $first['scanned']);
        $this->assertSame(1, $first['manual']);
        $this->assertSame(1, $first['invalid_frequency']);
        $this->assertSame(0, $first['scheduled']);
        $dispatcher->dispatchDue();
        $this->assertSame(1, CrawlerEvent::query()->where('source_id', $invalid->id)->where('event_type', 'source_frequency_invalid')->count());
        $this->assertDatabaseCount('crawl_jobs', 0);
        $this->assertFalse($disabled->fresh()->crawl_enabled);
    }

    public function test_never_crawled_due_source_schedules_once_with_priority_and_worker_can_claim_it(): void
    {
        $source = $this->source(['crawl_priority' => 87, 'crawl_frequency' => 'daily']);
        $dispatcher = app(CrawlerDueSourceService::class);

        $this->assertSame(1, $dispatcher->dispatchDue()['scheduled']);
        $this->assertSame(0, $dispatcher->dispatchDue()['scheduled']);
        $this->assertDatabaseCount('crawl_jobs', 1);
        $job = CrawlJob::query()->firstOrFail();
        $this->assertSame(87, $job->priority);
        $this->assertSame('schedule:'.$source->id.':daily-'.now()->format('Ymd'), $job->idempotency_key);
        $this->assertDatabaseHas('crawler_events', ['event_type' => 'job_scheduled', 'source_id' => $source->id, 'job_id' => $job->id]);

        $worker = CrawlerWorker::query()->create(['label' => 'scheduled-job claimant', 'status' => 'active']);
        $claim = app(CrawlJobClaimService::class)->claim($worker, 'claim-scheduled-job');
        $this->assertSame($job->id, $claim['job_id']);
        $this->assertSame($source->id, $claim['source_id']);
        $this->assertSame($source->source_url, $claim['requested_url']);
    }

    public function test_successful_crawl_sets_next_due_without_creating_jobs_early_and_monthly_is_calendar_based(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $daily = $this->source(['crawl_frequency' => 'daily']);
        $dailySuccess = $this->completedJob($daily, now()->subHours(23));
        $dispatcher = app(CrawlerDueSourceService::class);
        $status = $dispatcher->scheduleStatus($daily);
        $this->assertSame($dailySuccess->completed_at->addDay()->toIso8601String(), $status['next_due_at']);
        $this->assertFalse($status['is_due']);
        $this->assertSame(0, $dispatcher->dispatchDue()['scheduled']);
        $this->travelTo('2026-10-02 11:00:00');
        $this->assertSame(1, $dispatcher->dispatchDue()['scheduled']);

        $this->travelTo('2026-01-31 10:00:00');
        $monthly = $this->source(['crawl_frequency' => 'monthly']);
        $this->completedJob($monthly, CarbonImmutable::parse('2026-01-31 10:00:00'));
        $monthlyStatus = $dispatcher->scheduleStatus($monthly);
        $this->assertSame('2026-02-28T10:00:00+00:00', $monthlyStatus['next_due_at']);
        $this->assertFalse($monthlyStatus['is_due']);
        $this->travelTo('2026-02-28 10:00:00');
        $this->assertTrue($dispatcher->scheduleStatus($monthly)['is_due']);
    }

    public function test_weekly_frequency_waits_a_full_interval_after_success(): void
    {
        $this->travelTo('2026-10-08 09:00:00');
        $source = $this->source(['crawl_frequency' => 'weekly']);
        $this->completedJob($source, now()->subDays(6));
        $dispatcher = app(CrawlerDueSourceService::class);
        $this->assertFalse($dispatcher->scheduleStatus($source)['is_due']);
        $this->travelTo('2026-10-09 09:00:00');
        $this->assertTrue($dispatcher->scheduleStatus($source)['is_due']);
    }

    public function test_queued_active_retry_pending_manual_and_terminal_failure_suppress_redundant_scheduling(): void
    {
        $dispatcher = app(CrawlerDueSourceService::class);
        $suppressedCount = 0;
        foreach (['queued', 'leased', 'processing', 'retry_pending', 'manual_review', 'blocked'] as $state) {
            $source = $this->source();
            $this->job($source, 'existing-'.$source->id, $state);
            $suppressedCount++;
            $this->assertSame($suppressedCount, $dispatcher->dispatchDue()['suppressed'], 'Expected suppression for '.$state);
        }

        $failedSource = $this->source(['crawl_frequency' => 'daily']);
        $failureAt = now()->subHours(2);
        $this->job($failedSource, 'terminal-failure', 'failed', $failureAt);
        $failedStatus = $dispatcher->scheduleStatus($failedSource);
        $this->assertNull($failedStatus['last_successful_crawl_at']);
        $this->assertSame($failureAt->addDay()->toIso8601String(), $failedStatus['next_due_at']);
        $this->assertSame(0, $dispatcher->dispatchDue()['scheduled']);
        $this->travelTo(now()->addHours(22));
        $this->assertSame(1, $dispatcher->dispatchDue()['scheduled']);
    }

    public function test_schedule_status_exposes_success_attempt_pending_and_next_due_information(): void
    {
        $source = $this->source(['crawl_frequency' => 'daily']);
        $completed = $this->completedJob($source, now()->subDays(2));
        $this->job($source, 'pending-status', 'queued');
        $status = app(CrawlerDueSourceService::class)->scheduleStatus($source);

        $this->assertSame($completed->completed_at->toIso8601String(), $status['last_successful_crawl_at']);
        $this->assertNotNull($status['last_attempted_at']);
        $this->assertSame('queued', $status['pending_job']['state']);
        $this->assertFalse($status['is_due']);
    }

    private function source(array $attributes = []): ScholarshipSource
    {
        static $next = 1;
        $url = 'https://scheduler-fixture.example.test/source-'.$next++;
        return ScholarshipSource::query()->create(array_replace([
            'source_type' => 'other_official', 'source_name' => 'Due source fixture', 'source_url' => $url,
            'source_url_hash' => hash('sha256', $url), 'status' => 'active', 'crawl_enabled' => true,
            'crawl_method' => 'http', 'crawl_frequency' => 'daily', 'crawl_priority' => 10,
            'allowed_path_prefix' => '/', 'robots_policy' => 'allowed', 'source_concurrency_limit' => 1,
        ], $attributes));
    }

    private function completedJob(ScholarshipSource $source, \DateTimeInterface $completedAt): CrawlJob
    {
        return $this->job($source, 'completed-'.$source->id, 'completed', $completedAt);
    }

    private function job(ScholarshipSource $source, string $key, string $state, ?\DateTimeInterface $completedAt = null): CrawlJob
    {
        return CrawlJob::query()->create([
            'source_id' => $source->id, 'idempotency_key' => $key, 'state' => $state, 'priority' => 10,
            'requested_url' => $source->source_url, 'available_at' => now(), 'max_attempts' => 3,
            'completed_at' => $completedAt,
        ]);
    }
}
