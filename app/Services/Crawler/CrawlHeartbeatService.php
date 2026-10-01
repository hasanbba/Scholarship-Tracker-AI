<?php

namespace App\Services\Crawler;

use App\Models\CrawlAttempt;
use App\Models\CrawlerWorker;
use App\Models\CrawlJob;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CrawlHeartbeatService
{
    public function heartbeat(CrawlerWorker $worker, CrawlJob $job, CrawlAttempt $attempt, string $leaseToken): CrawlAttempt
    {
        return DB::transaction(function () use ($worker, $job, $attempt, $leaseToken): CrawlAttempt {
            $lockedJob = CrawlJob::query()->lockForUpdate()->findOrFail($job->id);
            $lockedAttempt = CrawlAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($lockedAttempt->job_id !== $lockedJob->id || $lockedAttempt->worker_id !== $worker->id) {
                throw new AccessDeniedHttpException('Attempt ownership mismatch.');
            }
            if ($lockedJob->assigned_worker_id !== $worker->id || $lockedJob->lease_generation !== $lockedAttempt->lease_generation) {
                throw new ConflictHttpException('Lease fencing check failed.');
            }
            if ($lockedAttempt->lease_expires_at->isPast() || ! hash_equals($lockedAttempt->lease_token_hash, hash('sha256', $leaseToken))) {
                throw new ConflictHttpException('Lease is expired or invalid.');
            }
            $now = now();
            $expires = $now->copy()->addSeconds(config('crawler.lease_seconds'));
            $lockedAttempt->forceFill(['state' => 'processing', 'heartbeat_at' => $now, 'lease_expires_at' => $expires])->save();
            $lockedJob->forceFill(['state' => 'processing', 'lease_expires_at' => $expires])->save();
            $worker->forceFill(['last_heartbeat_at' => $now])->save();
            app(CrawlerEventRecorder::class)->record('heartbeat', $lockedJob->source, $lockedJob, $lockedAttempt, $worker, details: ['lease_generation' => $lockedAttempt->lease_generation]);

            return $lockedAttempt->refresh();
        }, 3);
    }
}
