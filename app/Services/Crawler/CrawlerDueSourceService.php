<?php

namespace App\Services\Crawler;

use App\Models\CrawlerEvent;
use App\Models\CrawlJob;
use App\Models\ScholarshipSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CrawlerDueSourceService
{
    private const ACTIVE_JOB_STATES = ['queued', 'leased', 'processing', 'retry_pending', 'blocked', 'manual_review'];

    public function dispatchDue(int $limit = 200): array
    {
        $stats = ['scanned' => 0, 'scheduled' => 0, 'suppressed' => 0, 'not_due' => 0, 'manual' => 0, 'invalid_frequency' => 0, 'configuration_skipped' => 0, 'errors' => 0];
        $sourceIds = ScholarshipSource::query()->where('status', 'active')->where('crawl_enabled', true)
            ->orderByDesc('crawl_priority')->orderBy('id')->limit(max(1, min($limit, 1000)))->pluck('id');

        foreach ($sourceIds as $sourceId) {
            $stats['scanned']++;
            try {
                $outcome = $this->dispatchSource((int) $sourceId);
                $stats[$outcome]++;
            } catch (Throwable $exception) {
                $stats['errors']++;
                Log::error('crawler.due_dispatch.failed', ['source_id' => (int) $sourceId, 'exception' => $exception::class]);
            }
        }

        return $stats;
    }

    public function scheduleStatus(ScholarshipSource $source): array
    {
        $lastSuccess = $source->crawlJobs()->where('state', 'completed')->whereNotNull('completed_at')->orderByDesc('completed_at')->first();
        $lastFailure = $source->crawlJobs()->where('state', 'failed')->whereNotNull('completed_at')->orderByDesc('completed_at')->first();
        $frequency = $source->crawl_frequency;
        $nextDue = $this->nextDueAt($source, $lastSuccess?->completed_at, $lastFailure?->completed_at);
        $pending = $source->crawlJobs()->whereIn('state', self::ACTIVE_JOB_STATES)->orderBy('available_at')->first();
        $configurationEligible = $source->status === 'active' && $source->crawl_enabled && $source->crawl_method === 'http' && $source->robots_policy === 'allowed' && $this->interval($frequency) !== null;
        $lastAttemptedAt = DB::table('crawl_jobs')->leftJoin('crawl_attempts', 'crawl_attempts.job_id', '=', 'crawl_jobs.id')
            ->where('crawl_jobs.source_id', $source->id)
            ->max(DB::raw('COALESCE(crawl_attempts.completed_at, crawl_attempts.started_at, crawl_jobs.created_at)'));

        return [
            'crawl_enabled' => (bool) $source->crawl_enabled,
            'crawl_frequency' => $frequency,
            'crawl_priority' => (int) $source->crawl_priority,
            'last_successful_crawl_at' => $lastSuccess?->completed_at?->toIso8601String(),
            'last_attempted_at' => $lastAttemptedAt ? CarbonImmutable::parse($lastAttemptedAt)->toIso8601String() : null,
            'next_due_at' => $nextDue?->toIso8601String(),
            'is_due' => $configurationEligible && $pending === null && $nextDue !== null && $nextDue->lessThanOrEqualTo(now()),
            'pending_job' => $pending ? ['id' => $pending->id, 'state' => $pending->state, 'available_at' => $pending->available_at?->toIso8601String()] : null,
        ];
    }

    private function dispatchSource(int $sourceId): string
    {
        return DB::transaction(function () use ($sourceId): string {
            $source = ScholarshipSource::query()->lockForUpdate()->find($sourceId);
            if (! $source || $source->status !== 'active' || ! $source->crawl_enabled) return 'configuration_skipped';
            if ($source->crawl_frequency === 'manual') return 'manual';
            $interval = $this->interval($source->crawl_frequency);
            if ($interval === null) {
                $this->recordInvalidFrequencyOnce($source);
                return 'invalid_frequency';
            }
            if ($source->crawl_method !== 'http' || $source->robots_policy !== 'allowed') return 'configuration_skipped';

            $pending = CrawlJob::query()->where('source_id', $source->id)->whereIn('state', self::ACTIVE_JOB_STATES)->exists();
            if ($pending) return 'suppressed';

            $lastSuccess = CrawlJob::query()->where('source_id', $source->id)->where('state', 'completed')->whereNotNull('completed_at')->max('completed_at');
            $lastFailure = CrawlJob::query()->where('source_id', $source->id)->where('state', 'failed')->whereNotNull('completed_at')->max('completed_at');
            $dueAt = $this->nextDueAt($source, $lastSuccess, $lastFailure);
            if ($dueAt === null || $dueAt->isFuture()) return 'not_due';

            $slot = match ($source->crawl_frequency) {
                'daily' => now()->format('Ymd'),
                'weekly' => now()->startOfWeek()->format('Ymd'),
                'monthly' => now()->format('Ym'),
            };
            $job = app(CrawlJobService::class)->createScheduled($source, $source->crawl_frequency.'-'.$slot, $dueAt);
            app(CrawlerEventRecorder::class)->record('job_scheduled', $source, $job, details: [
                'frequency' => $source->crawl_frequency,
                'due_at' => $dueAt->toIso8601String(),
                'priority' => (int) $source->crawl_priority,
            ]);

            return 'scheduled';
        }, 3);
    }

    private function nextDueAt(ScholarshipSource $source, mixed $lastSuccessAt, mixed $lastFailureAt): ?CarbonImmutable
    {
        $interval = $this->interval($source->crawl_frequency);
        if ($interval === null) return null;

        $success = $lastSuccessAt ? CarbonImmutable::parse($lastSuccessAt) : null;
        $failure = $lastFailureAt ? CarbonImmutable::parse($lastFailureAt) : null;
        if ($failure !== null && ($success === null || $failure->greaterThan($success))) {
            return $this->addInterval($failure, $source->crawl_frequency);
        }
        if ($success !== null) return $this->addInterval($success, $source->crawl_frequency);

        return $source->created_at ? CarbonImmutable::parse($source->created_at) : now()->toImmutable();
    }

    private function interval(?string $frequency): ?int
    {
        return match ($frequency) {
            'daily' => 1,
            'weekly' => 7,
            'monthly' => 30,
            default => null,
        };
    }

    private function addInterval(CarbonImmutable $anchor, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'daily' => $anchor->addDay(),
            'weekly' => $anchor->addWeek(),
            'monthly' => $anchor->addMonthNoOverflow(),
        };
    }

    private function recordInvalidFrequencyOnce(ScholarshipSource $source): void
    {
        $value = $source->crawl_frequency ?? '[null]';
        $exists = CrawlerEvent::query()->where('source_id', $source->id)->where('event_type', 'source_frequency_invalid')->where('details->frequency', $value)->exists();
        if (! $exists) app(CrawlerEventRecorder::class)->record('source_frequency_invalid', $source, details: ['frequency' => $value]);
    }
}
