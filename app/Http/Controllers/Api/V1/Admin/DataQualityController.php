<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DataQuality\ObservationRequest;
use App\Http\Requests\Api\V1\DataQuality\ProcessObservationRequest;
use App\Http\Requests\Api\V1\DataQuality\ReviewDecisionRequest;
use App\Models\RawObservation;
use App\Models\ReviewTask;
use App\Models\ScholarshipSource;
use App\Services\DataQuality\ChangeDetectionService;
use App\Services\DataQuality\ObservationIngestionService;
use App\Services\DataQuality\ObservationProcessor;
use App\Services\DataQuality\ReviewWorkflowService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataQualityController extends Controller
{
    public function storeObservation(ObservationRequest $request, ObservationIngestionService $service): JsonResponse
    {
        $source = ScholarshipSource::query()->findOrFail($request->integer('source_id'));
        $observation = $service->ingest(
            $source,
            $request->string('observed_url')->toString(),
            $request->string('raw_payload')->toString(),
            $request->string('idempotency_key')->toString(),
            $request->string('producer_type', 'admin')->toString(),
            $request->input('producer_ref'),
            $request->input('source_access_status', 'success'),
            $request->input('content_type', 'application/json'),
        );

        $observation->loadMissing('source');

        return response()->json(ApiResponse::success('Observation evidence stored.', $this->observationData($observation)), $observation->wasRecentlyCreated ? 201 : 200);
    }

    public function processObservation(
        ProcessObservationRequest $request,
        RawObservation $observation,
        ObservationProcessor $processor,
        ChangeDetectionService $detection,
    ): JsonResponse {
        $run = $processor->process($observation, $request->string('run_key')->toString(), $request->input('parser_version'));
        $task = $run->status === 'succeeded' ? $detection->detect($run) : null;

        return response()->json(ApiResponse::success('Observation processing completed.', [
            'processing_run' => $run->only(['id', 'observation_id', 'parser_name', 'parser_version', 'normalization_version', 'validation_version', 'status', 'attempt_count', 'extracted_payload', 'normalized_payload', 'validation_errors', 'validation_warnings', 'last_error_code', 'started_at', 'finished_at']),
            'review_task_id' => $task?->id,
        ]), $run->status === 'failed' || $run->status === 'invalid' ? 422 : 201);
    }

    public function observations(Request $request): JsonResponse
    {
        $items = RawObservation::query()
            ->when($request->filled('source_id'), fn ($query) => $query->where('source_id', $request->integer('source_id')))
            ->when($request->filled('content_hash'), fn ($query) => $query->where('content_hash', $request->string('content_hash')))
            ->with('source:id,scholarship_id,university_id,source_name,source_url')
            ->orderByDesc('id')->paginate(min(max($request->integer('per_page', 20), 1), 50));

        return response()->json(['success' => true, 'message' => 'Observations retrieved.', 'data' => $items->getCollection()->map(fn (RawObservation $item) => $this->observationData($item))->values(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    public function artifact(RawObservation $observation): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($observation->artifact_ref), 404);
        $contents = $disk->get($observation->artifact_ref);
        abort_unless(hash_equals($observation->content_hash, hash('sha256', $contents)), 410, 'Evidence artifact integrity check failed.');

        return response()->streamDownload(static function () use ($contents): void {
            echo $contents;
        }, 'observation-'.$observation->id.'.txt', ['Content-Type' => $observation->content_type ?: 'application/octet-stream']);
    }

    public function reviewTasks(Request $request): JsonResponse
    {
        $items = ReviewTask::query()
            ->when($request->filled('state'), fn ($query) => $query->where('state', $request->string('state')))
            ->when($request->filled('task_type'), fn ($query) => $query->where('task_type', $request->string('task_type')))
            ->with(['scholarship:id,title,slug', 'cycle:id,cycle_key,label', 'assignee:id,name', 'duplicateCandidate', 'proposals'])
            ->orderByRaw("CASE WHEN state IN ('pending','in_review') THEN 0 ELSE 1 END")
            ->orderByDesc('priority')->orderBy('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 50));

        return response()->json(['success' => true, 'message' => 'Review tasks retrieved.', 'data' => $items->getCollection()->map(fn (ReviewTask $task) => $this->taskData($task))->values(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    public function showReviewTask(ReviewTask $task): JsonResponse
    {
        $task->load(['scholarship', 'cycle', 'assignee', 'processingRun.observation.source', 'proposals.baselineVersion', 'proposals.observation.source', 'duplicateCandidate', 'decisions.actor', 'decisions.proposals']);

        return response()->json(ApiResponse::success('Review task retrieved.', $this->taskData($task, true)));
    }

    public function decide(ReviewDecisionRequest $request, ReviewTask $task, ReviewWorkflowService $service): JsonResponse
    {
        $result = $service->decide(
            $task,
            $request->user(),
            $request->string('outcome')->toString(),
            $request->string('request_key')->toString(),
            $request->input('reason'),
            $request->input('evidence_refs', []),
            $request->integer('assignee_id') ?: null,
        );
        $status = $result['stale'] ? 409 : 200;
        $payload = [
            'task' => $this->taskData($result['task'], true),
            'decision' => $result['decision']->only(['id', 'review_task_id', 'actor_id', 'outcome', 'reason', 'created_at']),
            'version_id' => $result['version']?->id,
        ];

        return response()->json($result['stale']
            ? ApiResponse::failure('The proposal baseline changed. The task was marked stale and must be redetected.', ['baseline' => ['The latest cycle version no longer matches this proposal.']])
            : ApiResponse::success('Review decision recorded.', $payload), $status);
    }

    private function observationData(RawObservation $observation): array
    {
        return [
            'id' => $observation->id,
            'source_id' => $observation->source_id,
            'observed_url' => $observation->observed_url,
            'observed_at' => $observation->observed_at,
            'content_hash' => $observation->content_hash,
            'content_type' => $observation->content_type,
            'content_length' => $observation->content_length,
            'source_access_status' => $observation->source_access_status,
            'producer_type' => $observation->producer_type,
            'producer_ref' => $observation->producer_ref,
            'created_at' => $observation->created_at,
            'source' => $observation->source?->only(['id', 'source_name', 'source_url', 'scholarship_id', 'university_id']),
        ];
    }

    private function taskData(ReviewTask $task, bool $detailed = false): array
    {
        $data = [
            'id' => $task->id,
            'task_type' => $task->task_type,
            'state' => $task->state,
            'priority' => $task->priority,
            'lock_version' => $task->lock_version,
            'scholarship' => $task->scholarship?->only(['id', 'title', 'slug']),
            'cycle' => $task->cycle?->only(['id', 'cycle_key', 'label']),
            'assignee' => $task->assignee?->only(['id', 'name']),
            'duplicate_candidate' => $task->duplicateCandidate?->only(['id', 'classification', 'state', 'signals', 'candidate_scholarship_id', 'candidate_cycle_id']),
            'proposal_count' => $task->proposals?->count() ?? 0,
            'created_at' => $task->created_at,
        ];
        if ($detailed) {
            $data['observation'] = $task->processingRun?->observation ? $this->observationData($task->processingRun->observation) : null;
            $data['baseline_version'] = $task->proposals?->first()?->baselineVersion?->only(['id', 'version_number', 'created_at', 'snapshot']);
            $data['proposals'] = $task->proposals?->map(fn ($proposal) => [
                'id' => $proposal->id,
                'field_path' => $proposal->field_path,
                'old_value' => $proposal->old_value,
                'proposed_value' => $proposal->proposed_value,
                'display_old' => $proposal->display_old,
                'display_new' => $proposal->display_new,
                'evidence_locator' => $proposal->evidence_locator,
                'materiality' => $proposal->materiality,
                'status' => $proposal->status,
            ])->values();
            $data['decisions'] = $task->decisions?->map(fn ($decision) => [
                'id' => $decision->id,
                'actor' => $decision->actor?->only(['id', 'name']),
                'outcome' => $decision->outcome,
                'reason' => $decision->reason,
                'evidence_refs' => $decision->evidence_refs,
                'created_at' => $decision->created_at,
            ])->values();
        }

        return $data;
    }
}
