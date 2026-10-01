<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrawlerWorker;
use App\Models\CrawlerWorkerCredential;
use App\Models\CrawlJob;
use App\Models\ScholarshipSource;
use App\Services\Crawler\CrawlerEventRecorder;
use App\Services\Crawler\CrawlJobService;
use App\Services\Crawler\WorkerActivationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CrawlerAdminController extends Controller
{
    public function issueActivation(Request $request, WorkerActivationService $activation): JsonResponse
    {
        $data = Validator::make($request->all(), ['worker_label' => ['required', 'string', 'max:120']])->validate();

        return response()->json(ApiResponse::success('Activation code issued. This code is shown once.', $activation->issueCode($data['worker_label'], $request->user())), 201);
    }

    public function workers(): JsonResponse
    {
        $workers = CrawlerWorker::query()->withCount(['credentials as active_credentials_count' => fn ($q) => $q->whereNull('revoked_at')->where(fn ($sub) => $sub->whereNull('expires_at')->orWhere('expires_at', '>', now()))])->latest()->get()
            ->map(fn (CrawlerWorker $worker) => ['uuid' => $worker->uuid, 'label' => $worker->label, 'status' => $worker->presence(), 'software_version' => $worker->software_version, 'protocol_version' => $worker->protocol_version, 'last_heartbeat_at' => $worker->last_heartbeat_at?->toIso8601String(), 'active_credentials_count' => $worker->active_credentials_count]);

        return response()->json(ApiResponse::success('Crawler workers retrieved.', $workers));
    }

    public function disable(Request $request, CrawlerWorker $worker, CrawlerEventRecorder $events): JsonResponse
    {
        DB::transaction(function () use ($request, $worker, $events): void {
            $now = now();
            $worker->forceFill(['status' => 'disabled', 'disabled_at' => $now])->save();
            $worker->credentials()->whereNull('revoked_at')->update(['revoked_at' => $now]);
            $events->record('worker_disabled', worker: $worker, user: $request->user());
            $events->record('credential_revoked', worker: $worker, user: $request->user(), details: ['reason' => 'worker_disabled']);
        });

        return response()->json(ApiResponse::success('Worker disabled and credentials revoked.'));
    }

    public function rotate(Request $request, CrawlerWorker $worker, WorkerActivationService $activation): JsonResponse
    {
        if ($worker->status !== 'active') {
            throw new ConflictHttpException('Disabled workers cannot receive credentials.');
        }
        $result = $activation->rotate($worker, $request->user());

        return response()->json(ApiResponse::success('Credential rotated. Store this token securely; it is shown once.', $result));
    }

    public function updateSource(Request $request, ScholarshipSource $source, CrawlerEventRecorder $events): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'crawl_enabled' => ['sometimes', 'boolean'], 'crawl_method' => ['sometimes', Rule::in(['http', 'manual'])],
            'crawl_frequency' => ['sometimes', Rule::in(['manual', 'daily', 'weekly', 'monthly'])],
            'crawl_priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'allowed_path_prefix' => ['nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if ($value !== null && (! str_starts_with($value, '/') || str_contains($value, '?') || str_contains($value, '#') || preg_match('~(?:^|/)\.\.(?:/|$)~', $value))) {
                    $fail('The path prefix must be a safe path beginning with /.');
                }
            }],
            'robots_policy' => ['sometimes', Rule::in(['unknown', 'allowed', 'blocked', 'manual_review'])],
            'source_concurrency_limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ])->validate();
        DB::transaction(function () use ($source, $data, $request, $events): void {
            $source->forceFill($data)->save();
            $events->record('source_crawl_configuration_updated', source: $source, user: $request->user(), details: $data);
        });

        return response()->json(ApiResponse::success('Source crawl configuration updated.', $source->fresh()));
    }

    public function createJob(Request $request, ScholarshipSource $source, CrawlJobService $jobs): JsonResponse
    {
        $data = Validator::make($request->all(), ['idempotency_key' => ['required', 'string', 'max:191'], 'available_at' => ['nullable', 'date'], 'priority' => ['nullable', 'integer', 'min:0', 'max:100']])->validate();
        $job = $jobs->createManual($source, $request->user(), $data['idempotency_key'], isset($data['available_at']) ? new \DateTimeImmutable($data['available_at']) : null, (int) ($data['priority'] ?? 0));

        return response()->json(ApiResponse::success('Crawl job created or replayed.', $job), 201);
    }

    public function jobs(): JsonResponse
    {
        return response()->json(ApiResponse::success('Crawl jobs retrieved.', CrawlJob::query()->with(['source:id,source_name,source_url'])->withCount('attempts')->latest()->paginate(50)));
    }

    public function cancel(Request $request, CrawlJob $job, CrawlerEventRecorder $events): JsonResponse
    {
        DB::transaction(function () use ($request, $job, $events): void {
            $job = CrawlJob::query()->lockForUpdate()->findOrFail($job->id);
            if (! in_array($job->state, ['queued', 'retry_pending', 'blocked', 'manual_review'], true)) {
                throw new ConflictHttpException('This job cannot be cancelled in its current state.');
            }
            $job->forceFill(['state' => 'cancelled', 'completed_at' => now()])->save();
            $events->record('job_cancelled', $job->source, $job, user: $request->user());
        });

        return response()->json(ApiResponse::success('Crawl job cancelled.'));
    }

    public function retry(Request $request, CrawlJob $job, CrawlerEventRecorder $events, CrawlJobService $jobs): JsonResponse
    {
        DB::transaction(function () use ($request, $job, $events, $jobs): void {
            $job = CrawlJob::query()->lockForUpdate()->findOrFail($job->id);
            if (! in_array($job->state, ['failed', 'blocked', 'manual_review'], true)) {
                throw new ConflictHttpException('This job cannot be retried in its current state.');
            }
            $jobs->assertEligibleSource($job->source);
            $previousMaximum = $job->max_attempts;
            $job->forceFill(['state' => 'retry_pending', 'available_at' => now(), 'max_attempts' => $job->max_attempts + config('crawler.max_attempts'), 'completed_at' => null, 'last_error_category' => null, 'last_error_message' => null])->save();
            $events->record('retry_scheduled', $job->source, $job, user: $request->user(), details: ['previous_max_attempts' => $previousMaximum, 'new_max_attempts' => $job->max_attempts]);
        });

        return response()->json(ApiResponse::success('Crawl retry scheduled.', $job->fresh()));
    }

    public function revoke(Request $request, CrawlerWorker $worker, CrawlerWorkerCredential $credential, CrawlerEventRecorder $events): JsonResponse
    {
        if ($credential->worker_id !== $worker->id) {
            throw new ConflictHttpException('Credential does not belong to this worker.');
        }
        $credential->forceFill(['revoked_at' => now()])->save();
        $events->record('credential_revoked', worker: $worker, user: $request->user(), details: ['credential_id' => $credential->id]);

        return response()->json(ApiResponse::success('Credential revoked.'));
    }
}
