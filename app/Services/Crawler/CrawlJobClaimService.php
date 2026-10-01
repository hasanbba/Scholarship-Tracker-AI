<?php

namespace App\Services\Crawler;

use App\Models\CrawlAttempt;
use App\Models\CrawlerWorker;
use App\Models\CrawlJob;
use App\Models\ScholarshipSource;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CrawlJobClaimService
{
    public function claim(CrawlerWorker $worker, string $requestKey): ?array
    {
        return DB::transaction(function () use ($worker, $requestKey): ?array {
            $prior = CrawlAttempt::query()->where('worker_id', $worker->id)->where('claim_request_key', $requestKey)->first();
            if ($prior) {
                if (! in_array($prior->state, ['leased', 'processing'], true) || $prior->lease_expires_at->isPast()) {
                    throw new ConflictHttpException('The previous claim lease is no longer active.');
                }

                return $this->response($prior->refresh());
            }

            $candidates = CrawlJob::query()->whereIn('state', ['queued', 'retry_pending'])
                ->where('available_at', '<=', now())->whereColumn('attempt_count', '<', 'max_attempts')
                ->orderByDesc('priority')->orderBy('available_at')->orderBy('id')
                ->limit(20)->lock('for update skip locked')->get();

            foreach ($candidates as $job) {
                $source = ScholarshipSource::query()->whereKey($job->source_id)->lockForUpdate()->first();
                if (! $source || $source->status !== 'active' || ! $source->crawl_enabled || $source->robots_policy !== 'allowed' || $source->crawl_method !== 'http') {
                    continue;
                }
                $active = CrawlJob::query()->where('source_id', $source->id)->whereIn('state', ['leased', 'processing'])
                    ->where('lease_expires_at', '>', now())->count();
                if ($active >= max(1, $source->source_concurrency_limit)) {
                    continue;
                }
                $locked = CrawlJob::query()->lockForUpdate()->find($job->id);
                if (! $locked || ! in_array($locked->state, ['queued', 'retry_pending'], true) || $locked->available_at->isFuture()) {
                    continue;
                }
                $generation = $locked->lease_generation + 1;
                $attemptNumber = $locked->attempt_count + 1;
                $now = now();
                $expires = $now->copy()->addSeconds(config('crawler.lease_seconds'));
                $locked->forceFill([
                    'state' => 'leased', 'assigned_worker_id' => $worker->id, 'lease_expires_at' => $expires,
                    'lease_generation' => $generation, 'attempt_count' => $attemptNumber,
                ])->save();
                $attempt = new CrawlAttempt([
                    'worker_id' => $worker->id, 'attempt_number' => $attemptNumber, 'lease_generation' => $generation,
                    'claim_request_key' => $requestKey, 'state' => 'leased', 'requested_url' => $locked->requested_url,
                    'started_at' => $now, 'lease_expires_at' => $expires,
                ]);
                $attempt->job()->associate($locked);
                $attempt->lease_token_hash = hash('sha256', $this->leaseToken($attemptNumber, $generation, $locked->id, $worker->id));
                $attempt->save();
                $locked->forceFill(['current_attempt_id' => $attempt->id])->save();
                app(CrawlerEventRecorder::class)->record('job_claimed', $source, $locked, $attempt, $worker, details: ['lease_generation' => $generation, 'attempt_number' => $attemptNumber]);

                return $this->response($attempt);
            }

            return null;
        }, 3);
    }

    public function leaseToken(int $attemptNumber, int $generation, int $jobId, int $workerId): string
    {
        return hash_hmac('sha256', implode(':', [$jobId, $attemptNumber, $generation, $workerId]), (string) config('app.key'));
    }

    private function response(CrawlAttempt $attempt): array
    {
        $attempt->loadMissing('job.source');

        return ['job_id' => $attempt->job_id, 'attempt_id' => $attempt->id, 'lease_generation' => $attempt->lease_generation,
            'lease_token' => $this->leaseToken($attempt->attempt_number, $attempt->lease_generation, $attempt->job_id, $attempt->worker_id),
            'lease_expires_at' => $attempt->lease_expires_at->toIso8601String(), 'requested_url' => $attempt->requested_url,
            'source_id' => $attempt->job->source->id,
            'registered_source_url' => $attempt->job->source->source_url,
            'source_concurrency_limit' => $attempt->job->source->source_concurrency_limit,
            'robots_policy' => $attempt->job->source->robots_policy,
            'allowed_path_prefix' => $attempt->job->source->allowed_path_prefix ?? (parse_url($attempt->requested_url, PHP_URL_PATH) ?: '/')];
    }
}
