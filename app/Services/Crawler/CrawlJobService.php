<?php

namespace App\Services\Crawler;

use App\Models\CrawlJob;
use App\Models\ScholarshipSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrawlJobService
{
    public function createManual(ScholarshipSource $source, User $user, string $key, ?\DateTimeInterface $availableAt = null, int $priority = 0): CrawlJob
    {
        return DB::transaction(function () use ($source, $user, $key, $availableAt, $priority): CrawlJob {
            $source = ScholarshipSource::query()->lockForUpdate()->findOrFail($source->id);
            $this->assertEligibleSource($source);
            $existing = CrawlJob::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->source_id !== $source->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was used for a different source.']);
                }

                return $existing;
            }
            $job = CrawlJob::query()->create([
                'source_id' => $source->id, 'created_by_user_id' => $user->id, 'idempotency_key' => $key,
                'state' => 'queued', 'priority' => $priority ?: $source->crawl_priority,
                'requested_url' => $source->source_url, 'available_at' => $availableAt ?? now(),
                'max_attempts' => config('crawler.max_attempts'),
            ]);
            app(CrawlerEventRecorder::class)->record('job_created', $source, $job, user: $user, details: ['origin' => 'manual']);

            return $job;
        });
    }

    /** Scheduler-facing seam; caller chooses a stable source/time-slot key. */
    public function createScheduled(ScholarshipSource $source, string $slotKey, \DateTimeInterface $availableAt): CrawlJob
    {
        return DB::transaction(function () use ($source, $slotKey, $availableAt): CrawlJob {
            $source = ScholarshipSource::query()->lockForUpdate()->findOrFail($source->id);
            $this->assertEligibleSource($source);
            $key = "schedule:{$source->id}:{$slotKey}";
            $job = CrawlJob::query()->firstOrCreate(['idempotency_key' => $key], [
                'source_id' => $source->id, 'state' => 'queued', 'priority' => $source->crawl_priority,
                'requested_url' => $source->source_url, 'available_at' => $availableAt,
                'max_attempts' => config('crawler.max_attempts'),
            ]);
            if ($job->wasRecentlyCreated) {
                app(CrawlerEventRecorder::class)->record('job_created', $source, $job, details: ['origin' => 'scheduler']);
            }

            return $job;
        });
    }

    public function assertEligibleSource(ScholarshipSource $source): void
    {
        if ($source->status !== 'active' || ! $source->crawl_enabled || $source->robots_policy !== 'allowed') {
            throw ValidationException::withMessages(['source_id' => 'The source is inactive or not approved for crawling.']);
        }
    }
}
