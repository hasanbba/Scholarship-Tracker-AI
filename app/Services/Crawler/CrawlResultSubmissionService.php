<?php

namespace App\Services\Crawler;

use App\Models\CrawlAttempt;
use App\Models\CrawlerWorker;
use App\Models\CrawlJob;
use App\Models\ProcessingRun;
use App\Models\RawObservation;
use App\Models\ScholarshipSource;
use App\Services\DataQuality\ObservationIngestionService;
use App\Services\DataQuality\ObservationProcessor;
use App\Services\DataQuality\HtmlScholarshipExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CrawlResultSubmissionService
{
    public const MAX_ARTIFACT_BYTES = 4 * 1024 * 1024;
    private const MIME_TYPES = ['text/html', 'application/xhtml+xml', 'application/json', 'text/json', 'application/pdf'];

    public function upload(CrawlerWorker $worker, CrawlJob $job, CrawlAttempt $attempt, array $meta, string $bytes, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($worker, $job, $attempt, $meta, $bytes, $idempotencyKey): array {
            [$job, $attempt, $source] = $this->lockAndAssertLease($worker, $job, $attempt, (int) ($meta['lease_generation'] ?? -1));
            if ($bytes === '' || strlen($bytes) > self::MAX_ARTIFACT_BYTES) throw new UnprocessableEntityHttpException('Artifact must be non-empty and at most 4 MiB.');
            $hash = hash('sha256', $bytes);
            $mime = strtolower(trim(explode(';', (string) ($meta['content_type'] ?? ''))[0]));
            if (! in_array($mime, self::MIME_TYPES, true) || ! hash_equals($hash, strtolower((string) ($meta['sha256'] ?? ''))) || (int) ($meta['content_length'] ?? -1) !== strlen($bytes)) {
                throw new UnprocessableEntityHttpException('Artifact type, length, or SHA-256 is invalid.');
            }
            if (! is_string($meta['fetched_at'] ?? null) || strtotime($meta['fetched_at']) === false) throw new UnprocessableEntityHttpException('Artifact fetch timestamp is invalid.');
            if ($mime === 'application/pdf' && ! str_starts_with($bytes, '%PDF-')) throw new UnprocessableEntityHttpException('PDF signature does not match its declared media type.');
            if (in_array($mime, ['application/json', 'text/json'], true) && ! in_array(ltrim($bytes)[0] ?? '', ['{', '['], true)) throw new UnprocessableEntityHttpException('JSON signature does not match its declared media type.');
            $this->assertUrls($source, $job, $meta);
            $artifactId = (string) ($meta['artifact_id'] ?? '');
            if (! \Illuminate\Support\Str::isUuid($artifactId) || $idempotencyKey === '' || strlen($idempotencyKey) > 191) throw new UnprocessableEntityHttpException('Artifact identity or idempotency key is invalid.');
            if ($attempt->artifact_id !== null) {
                if ($attempt->artifact_id !== $artifactId || $attempt->artifact_metadata['upload_key'] !== $idempotencyKey || ! hash_equals($attempt->content_hash, $hash)) throw new ConflictHttpException('Attempt already has a different artifact receipt.');
                app(CrawlerEventRecorder::class)->record('artifact.upload.replayed', $source, $job, $attempt, $worker, details: ['sha256' => $hash]);
                return $this->artifactReceipt($attempt);
            }
            if ($attempt->result_hash !== null) throw new ConflictHttpException('Fetch result was already submitted.');
            $path = 'evidence/sha256/'.substr($hash, 0, 2).'/'.$hash;
            $disk = Storage::disk('local');
            if (! $disk->exists($path) && ! $disk->put($path, $bytes)) throw new \RuntimeException('Private artifact storage failed.');
            $attempt->forceFill(['artifact_id' => $artifactId, 'artifact_ref' => $path, 'artifact_content_type' => $mime,
                'artifact_metadata' => ['upload_key' => $idempotencyKey, 'content_length' => strlen($bytes), 'requested_url' => $meta['requested_url'], 'final_url' => $meta['final_url'], 'fetched_at' => $meta['fetched_at']],
                'content_hash' => $hash, 'content_length' => strlen($bytes), 'final_url' => $meta['final_url']])->save();
            app(CrawlerEventRecorder::class)->record('artifact.uploaded', $source, $job, $attempt, $worker, details: ['sha256' => $hash, 'content_length' => strlen($bytes), 'content_type' => $mime]);
            return $this->artifactReceipt($attempt->refresh());
        }, 3);
    }

    public function submit(CrawlerWorker $worker, CrawlJob $job, CrawlAttempt $attempt, array $result, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($worker, $job, $attempt, $result, $idempotencyKey): array {
            $job = CrawlJob::query()->lockForUpdate()->findOrFail($job->id);
            $attempt = CrawlAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->job_id !== $job->id || $attempt->worker_id !== $worker->id) throw new AccessDeniedHttpException('Worker does not own this attempt.');
            $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $encoded);
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) throw new UnprocessableEntityHttpException('Result idempotency key is invalid.');
            if ($attempt->result_hash !== null) {
                if ($attempt->result_idempotency_key !== $idempotencyKey || ! hash_equals($attempt->result_hash, $hash)) throw new ConflictHttpException('Attempt already has a different fetch result.');
                app(CrawlerEventRecorder::class)->record('fetch.result.replayed', $job->source, $job, $attempt, $worker);
                return $this->resultReceipt($attempt);
            }
            [$job, $attempt, $source] = $this->lockAndAssertLease($worker, $job, $attempt, (int) ($result['lease_generation'] ?? -1));
            $this->assertUrls($source, $job, $result);
            $this->assertSafeResultMetadata($result);
            $outcome = $result['outcome'] ?? null;
            if (! in_array($outcome, ['fetched', 'failed'], true)) throw new UnprocessableEntityHttpException('Result outcome must be fetched or failed.');
            $observation = null;
            if ($outcome === 'fetched') {
                if (! $attempt->artifact_id || ! is_array($attempt->artifact_metadata) || ! hash_equals($attempt->content_hash, (string) ($result['sha256'] ?? ''))
                    || ! hash_equals($attempt->artifact_id, (string) ($result['artifact_id'] ?? '')) || (int) $attempt->content_length !== (int) ($result['content_length'] ?? -1)
                    || $attempt->artifact_content_type !== strtolower((string) ($result['content_type'] ?? ''))
                    || $attempt->artifact_metadata['requested_url'] !== $result['requested_url'] || $attempt->artifact_metadata['final_url'] !== $result['final_url']
                    || $attempt->artifact_metadata['fetched_at'] !== $result['fetched_at']) throw new ConflictHttpException('Fetch result does not match the accepted artifact.');
                $observationKey = 'crawler:'.$source->id.':'.hash('sha256', app(ObservationIngestionService::class)->canonicalComparisonUrl($result['final_url'])).':'.$attempt->content_hash;
                $existingId = RawObservation::query()->where('source_id', $source->id)->where('idempotency_key', $observationKey)->value('id');
                $observation = app(ObservationIngestionService::class)->ingest($source, $result['final_url'], Storage::disk('local')->get($attempt->artifact_ref), $observationKey, 'crawler', 'job:'.$job->id.':attempt:'.$attempt->id, 'success', $attempt->artifact_content_type, new \DateTimeImmutable($result['fetched_at']));
                $runKey = 'crawl:'.$source->id.':'.$job->id.':'.$attempt->id;
                if ($attempt->artifact_content_type === 'application/pdf') {
                    $run = ProcessingRun::query()->firstOrCreate(['observation_id' => $observation->id, 'run_key' => $runKey], ['parser_name' => 'unsupported', 'parser_version' => 'none', 'normalization_version' => '1', 'validation_version' => '1', 'status' => 'failed', 'last_error_code' => 'unsupported_parser', 'finished_at' => now()]);
                } else {
                    $parserVersion = in_array($attempt->artifact_content_type, ['text/html', 'application/xhtml+xml'], true)
                        ? HtmlScholarshipExtractor::VERSION : 'json-v1';
                    $run = app(ObservationProcessor::class)->process($observation, $runKey, $parserVersion);
                }
                $attempt->forceFill(['observation_id' => $observation->id]);
                app(CrawlerEventRecorder::class)->record($existingId ? 'observation.replayed' : 'observation.created', $source, $job, $attempt, $worker, details: ['observation_id' => $observation->id, 'processing_run_id' => $run->id]);
                app(CrawlerEventRecorder::class)->record('processing.dispatched', $source, $job, $attempt, $worker, details: ['processing_run_id' => $run->id, 'status' => $run->status]);
                $attempt->forceFill(['state' => 'completed', 'final_url' => $result['final_url'], 'http_status' => $result['status_code'] ?? null, 'response_duration_ms' => $result['duration_ms'] ?? null, 'completed_at' => now()]);
                $job->forceFill(['state' => 'completed', 'completed_at' => now(), 'lease_expires_at' => null, 'assigned_worker_id' => null]);
                app(CrawlerEventRecorder::class)->record('job.completed', $source, $job, $attempt, $worker, details: ['outcome' => 'fetched', 'observation_id' => $observation->id]);
            } else {
                $code = substr((string) ($result['failure_code'] ?? 'FETCH_FAILED'), 0, 64);
                $retryable = (bool) ($result['retryable'] ?? false);
                $terminal = ! $retryable || $job->attempt_count >= $job->max_attempts;
                $attempt->forceFill(['state' => 'failed', 'final_url' => $result['final_url'] ?? null, 'http_status' => $result['status_code'] ?? null,
                    'response_duration_ms' => $result['duration_ms'] ?? null, 'error_category' => $code, 'error_message' => 'Fetch failed: '.$code, 'completed_at' => now()]);
                $job->forceFill(['state' => $terminal ? 'failed' : 'retry_pending', 'available_at' => now()->addSeconds(30), 'assigned_worker_id' => null, 'lease_expires_at' => null,
                    'last_error_category' => $code, 'last_error_message' => 'Fetch failed: '.$code, 'completed_at' => $terminal ? now() : null]);
                app(CrawlerEventRecorder::class)->record($terminal ? 'job.failed' : 'job.retry_pending', $source, $job, $attempt, $worker, details: ['failure_code' => $code, 'retryable' => $retryable]);
            }
            $attempt->forceFill(['result_hash' => $hash, 'result_idempotency_key' => $idempotencyKey, 'fetch_result' => $result])->save();
            $job->save();
            app(CrawlerEventRecorder::class)->record('fetch.result.submitted', $source, $job, $attempt, $worker, details: ['outcome' => $outcome, 'sha256' => $result['sha256'] ?? null]);
            return $this->resultReceipt($attempt->refresh());
        }, 3);
    }

    private function lockAndAssertLease(CrawlerWorker $worker, CrawlJob $job, CrawlAttempt $attempt, int $generation): array
    {
        $job = CrawlJob::query()->lockForUpdate()->findOrFail($job->id);
        $attempt = CrawlAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
        $source = ScholarshipSource::query()->findOrFail($job->source_id);
        if ($attempt->job_id !== $job->id || $attempt->worker_id !== $worker->id || $job->assigned_worker_id !== $worker->id) throw new AccessDeniedHttpException('Worker does not own this attempt.');
        if ($attempt->lease_generation !== $generation || $job->lease_generation !== $generation || $job->current_attempt_id !== $attempt->id) throw new ConflictHttpException('Lease fencing check failed.');
        if ($attempt->lease_expires_at->isPast() || ! in_array($attempt->state, ['leased', 'processing'], true) || ! in_array($job->state, ['leased', 'processing'], true)) throw new ConflictHttpException('Attempt lease has expired or is no longer active.');
        if ($job->requested_url !== $attempt->requested_url || $source->status !== 'active' || ! $source->crawl_enabled) throw new ConflictHttpException('Job source no longer matches the active crawl configuration.');
        return [$job, $attempt, $source];
    }

    private function assertUrls(ScholarshipSource $source, CrawlJob $job, array $data): void
    {
        foreach (['requested_url', 'final_url'] as $key) {
            if ($key === 'final_url' && ($data['outcome'] ?? 'fetched') === 'failed' && empty($data[$key])) continue;
            if (! is_string($data[$key] ?? null) || $data[$key] === '') throw new UnprocessableEntityHttpException('Requested and final URLs are required.');
            $candidate = parse_url($data[$key]); $registered = parse_url($source->source_url);
            if (! is_array($candidate) || ! is_array($registered) || isset($candidate['user']) || isset($candidate['pass']) || strtolower($candidate['host'] ?? '') !== strtolower($registered['host'] ?? '')
                || strtolower($candidate['scheme'] ?? '') !== strtolower($registered['scheme'] ?? '') || ($candidate['port'] ?? null) !== ($registered['port'] ?? null)) throw new ConflictHttpException('Result URL is outside the registered origin.');
            $path = $candidate['path'] ?? '/'; $prefix = $source->allowed_path_prefix ?: (parse_url($job->requested_url, PHP_URL_PATH) ?: '/');
            if (! str_starts_with($path, rtrim($prefix, '/').'/') && $path !== rtrim($prefix, '/')) throw new ConflictHttpException('Result URL is outside the registered path scope.');
        }
        $requested = parse_url($data['requested_url']); $claimed = parse_url($job->requested_url);
        if (! is_array($requested) || ! is_array($claimed) || strtolower($requested['host'] ?? '') !== strtolower($claimed['host'] ?? '') || ($requested['path'] ?? '/') !== ($claimed['path'] ?? '/')) throw new ConflictHttpException('Requested URL does not match the claimed job.');
    }

    private function assertSafeResultMetadata(array $result): void
    {
        $redirects = $result['redirects'] ?? [];
        if (! is_array($redirects) || count($redirects) > 20) throw new UnprocessableEntityHttpException('Redirect metadata is invalid.');
        foreach ($redirects as $redirect) {
            if (! is_array($redirect) || ! is_string($redirect['from'] ?? null) || ! is_string($redirect['to'] ?? null)
                || ! is_numeric($redirect['status'] ?? null) || strlen($redirect['from']) > 2048 || strlen($redirect['to']) > 2048) throw new UnprocessableEntityHttpException('Redirect metadata is invalid.');
        }
        $headers = $result['response_metadata'] ?? [];
        if (! is_array($headers) || array_diff(array_keys($headers), ['etag', 'last-modified', 'content-language']) !== []) throw new UnprocessableEntityHttpException('Response metadata contains unsupported fields.');
        foreach ($headers as $value) if (! is_string($value) || strlen($value) > 512) throw new UnprocessableEntityHttpException('Response metadata value is invalid.');
    }

    private function artifactReceipt(CrawlAttempt $attempt): array { return ['artifact_id' => $attempt->artifact_id, 'artifact_ref' => $attempt->artifact_ref, 'sha256' => $attempt->content_hash, 'content_type' => $attempt->artifact_content_type, 'content_length' => $attempt->content_length]; }
    private function resultReceipt(CrawlAttempt $attempt): array { return ['job_id' => $attempt->job_id, 'attempt_id' => $attempt->id, 'state' => $attempt->state, 'observation_id' => $attempt->observation_id, 'result_hash' => $attempt->result_hash]; }
}
