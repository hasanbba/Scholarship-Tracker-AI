<?php

namespace App\Services\Crawler;

use App\Models\CrawlJob;
use Illuminate\Support\Facades\DB;

class ExpireCrawlLeasesService
{
    public function reap(int $limit = 200): int
    {
        $ids = CrawlJob::query()->whereIn('state', ['leased', 'processing'])->where('lease_expires_at', '<=', now())->orderBy('lease_expires_at')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $job = CrawlJob::query()->lockForUpdate()->find($id);
                if (! $job || ! in_array($job->state, ['leased', 'processing'], true) || $job->lease_expires_at?->isFuture()) {
                    return;
                }
                $attempt = $job->attempts()->where('lease_generation', $job->lease_generation)->lockForUpdate()->first();
                $terminal = $job->attempt_count >= $job->max_attempts;
                $attempt?->forceFill(['state' => 'expired', 'completed_at' => now(), 'error_category' => 'lease_expired'])->save();
                $job->forceFill(['state' => $terminal ? 'failed' : 'retry_pending', 'available_at' => now(), 'assigned_worker_id' => null, 'lease_expires_at' => null, 'last_error_category' => 'lease_expired', 'last_error_message' => 'Worker lease expired.', 'completed_at' => $terminal ? now() : null])->save();
                app(CrawlerEventRecorder::class)->record('lease_expired', $job->source, $job, $attempt, $attempt?->worker, details: ['lease_generation' => $job->lease_generation, 'terminal' => $terminal]);
                $count++;
            }, 3);
        }

        return $count;
    }
}
