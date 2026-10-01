<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CrawlAttempt;
use App\Models\CrawlerWorker;
use App\Models\CrawlJob;
use App\Services\Crawler\CrawlHeartbeatService;
use App\Services\Crawler\CrawlJobClaimService;
use App\Services\Crawler\CrawlResultSubmissionService;
use App\Services\Crawler\WorkerActivationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class CrawlerWorkerController extends Controller
{
    public function uploadArtifact(Request $request, CrawlJob $job, CrawlAttempt $attempt, CrawlResultSubmissionService $results): JsonResponse
    {
        $encoded = (string) $request->header('X-Crawler-Metadata', '');
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $meta = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($meta)) return response()->json(ApiResponse::failure('Artifact metadata is invalid.'), 422);
        if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== strtolower((string) ($meta['content_type'] ?? ''))) return response()->json(ApiResponse::failure('Artifact content type header does not match metadata.'), 422);
        $receipt = $results->upload($request->attributes->get('crawler_worker'), $job, $attempt, $meta, $request->getContent(), (string) $request->header('Idempotency-Key', ''));
        return response()->json(ApiResponse::success('Artifact accepted.', $receipt), 201);
    }

    public function submitResult(Request $request, CrawlJob $job, CrawlAttempt $attempt, CrawlResultSubmissionService $results): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'lease_generation' => ['required', 'integer', 'min:1'], 'outcome' => ['required', 'in:fetched,failed'],
            'requested_url' => ['required', 'string', 'max:2048'], 'final_url' => ['required_if:outcome,fetched', 'nullable', 'string', 'max:2048'],
            'fetched_at' => ['required', 'date'], 'status_code' => ['nullable', 'integer', 'between:100,599'],
            'content_type' => ['nullable', 'string', 'max:160'], 'content_length' => ['required', 'integer', 'min:0'],
            'sha256' => ['nullable', 'string', 'size:64'], 'artifact_id' => ['nullable', 'uuid'], 'artifact_reference' => ['nullable', 'string', 'max:255'],
            'redirects' => ['array', 'max:20'], 'robots_result' => ['nullable', 'string', 'max:64'], 'duration_ms' => ['required', 'integer', 'min:0'],
            'failure_code' => ['nullable', 'string', 'max:64'], 'retryable' => ['required', 'boolean'], 'response_metadata' => ['array'],
        ])->validate();
        $receipt = $results->submit($request->attributes->get('crawler_worker'), $job, $attempt, $data, (string) $request->header('Idempotency-Key', ''));
        return response()->json(ApiResponse::success('Fetch result accepted.', $receipt));
    }

    public function activate(Request $request, WorkerActivationService $activation): JsonResponse
    {
        $data = Validator::make($request->all(), ['activation_code' => ['required', 'string', 'size:64'], 'software_version' => ['nullable', 'string', 'max:40'], 'protocol_version' => ['required', 'integer', 'in:1']])->validate();
        try {
            $result = $activation->activate($data['activation_code'], $data['software_version'] ?? null, (int) $data['protocol_version']);
        } catch (UnauthorizedHttpException) {
            return response()->json(ApiResponse::failure('Invalid or expired activation code.'), 401);
        }

        return response()->json(ApiResponse::success('Worker activated. Store this token securely; it is shown once.', [
            'worker' => ['uuid' => $result['worker']->uuid, 'label' => $result['worker']->label, 'status' => $result['worker']->status],
            'token' => $result['token'], 'expires_at' => $result['expires_at']->toIso8601String(),
        ]), 201);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var CrawlerWorker $worker */
        $worker = $request->attributes->get('crawler_worker');

        return response()->json(ApiResponse::success('Worker status retrieved.', [
            'uuid' => $worker->uuid, 'label' => $worker->label, 'status' => $worker->presence(),
            'protocol_version' => $worker->protocol_version, 'last_heartbeat_at' => $worker->last_heartbeat_at?->toIso8601String(),
        ]));
    }

    public function claim(Request $request, CrawlJobClaimService $claims): JsonResponse
    {
        $data = Validator::make($request->all(), ['claim_request_key' => ['required', 'string', 'max:191']])->validate();
        $result = $claims->claim($request->attributes->get('crawler_worker'), $data['claim_request_key']);

        return response()->json(ApiResponse::success($result ? 'Crawl job claimed.' : 'No eligible crawl job is available.', $result));
    }

    public function heartbeat(Request $request, CrawlJob $job, CrawlAttempt $attempt, CrawlHeartbeatService $heartbeats): JsonResponse
    {
        $data = Validator::make($request->all(), ['lease_token' => ['required', 'string', 'size:64']])->validate();
        $attempt = $heartbeats->heartbeat($request->attributes->get('crawler_worker'), $job, $attempt, $data['lease_token']);

        return response()->json(ApiResponse::success('Heartbeat accepted.', ['lease_expires_at' => $attempt->lease_expires_at->toIso8601String(), 'lease_generation' => $attempt->lease_generation]));
    }
}
