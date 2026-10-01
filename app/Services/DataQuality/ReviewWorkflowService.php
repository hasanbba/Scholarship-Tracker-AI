<?php

namespace App\Services\DataQuality;

use App\Models\ReviewDecision;
use App\Models\ReviewTask;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReviewWorkflowService
{
    public function __construct(private readonly ApprovedChangeService $approvedChanges) {}

    /** @return array{task: ReviewTask, decision: ReviewDecision, version: ?ScholarshipVersion, stale: bool} */
    public function decide(
        ReviewTask $task,
        User $actor,
        string $outcome,
        string $requestKey,
        ?string $reason = null,
        array $evidenceRefs = [],
        ?int $assigneeId = null,
    ): array {
        $permission = $outcome === 'approve' ? 'scholarships.changes.approve' : 'scholarships.review.manage';
        if (! $actor->hasPermission($permission)) {
            abort(403, 'You are not authorized to perform this review action.');
        }

        return DB::transaction(function () use ($task, $actor, $outcome, $requestKey, $reason, $evidenceRefs, $assigneeId): array {
            $task = ReviewTask::query()->lockForUpdate()->findOrFail($task->id);
            $existing = $task->decisions()->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                if ((int) $existing->actor_id !== (int) $actor->id || $existing->outcome !== $outcome) {
                    throw new ConflictHttpException('That idempotency key was already used for a different review action.');
                }

                return ['task' => $task->load('proposals', 'decisions'), 'decision' => $existing, 'version' => $task->cycle?->versions()->orderByDesc('version_number')->first(), 'stale' => $existing->outcome === 'mark_stale'];
            }

            if (in_array($task->state, ['approved', 'rejected', 'cancelled', 'stale'], true)) {
                throw new ConflictHttpException('This review task is already closed.');
            }
            if ($outcome === 'approve' && ($reason === null || trim($reason) === '')
                && ($task->task_type === 'duplicate_resolution' || $task->proposals()->where('materiality', 'identity')->exists())) {
                throw ValidationException::withMessages(['reason' => 'Identity resolution requires a reviewer reason.']);
            }
            if ($outcome === 'approve' && $task->proposals()->exists() && $evidenceRefs === []) {
                throw ValidationException::withMessages(['evidence_refs' => 'Approval must record the evidence reviewed.']);
            }
            if ($outcome === 'assign') {
                if ($assigneeId === null) {
                    throw ValidationException::withMessages(['assignee_id' => 'An authorized reviewer must be selected.']);
                }
                $assignee = User::query()->with('roles.permissions')->findOrFail($assigneeId);
                if (! $assignee->hasPermission('scholarships.review.manage')) {
                    throw ValidationException::withMessages(['assignee_id' => 'The selected user cannot review data-quality tasks.']);
                }
            }
            if (in_array($outcome, ['reject', 'needs_evidence'], true) && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A reason is required for this decision.']);
            }
            if (! in_array($outcome, ['claim', 'assign', 'unassign', 'approve', 'reject', 'needs_evidence', 'cancel'], true)) {
                throw ValidationException::withMessages(['outcome' => 'Unsupported review action.']);
            }

            $task->loadMissing('processingRun.observation.source', 'proposals', 'duplicateCandidate');
            if ($outcome === 'approve' && $task->processingRun?->observation_id !== null) {
                $requiredEvidence = 'observation:'.$task->processingRun->observation_id;
                if (! collect($evidenceRefs)->contains(fn ($reference) => str_starts_with($reference, $requiredEvidence))) {
                    throw ValidationException::withMessages(['evidence_refs' => 'Approval evidence must reference this task observation.']);
                }
            }
            if ($outcome === 'claim' && ! in_array($task->state, ['pending', 'in_review'], true)) {
                throw new ConflictHttpException('This task cannot be claimed in its current state.');
            }

            $stale = false;
            $version = null;
            $decisionOutcome = $outcome;
            if ($outcome === 'approve' && $task->target_cycle_id !== null && $task->proposals->isNotEmpty()) {
                ScholarshipCycle::query()->lockForUpdate()->findOrFail($task->target_cycle_id);
                $latest = ScholarshipVersion::query()->where('cycle_id', $task->target_cycle_id)->orderByDesc('version_number')->first();
                $baselineId = $task->proposals->first()->baseline_version_id;
                if ((int) ($latest?->id ?? 0) !== (int) ($baselineId ?? 0)) {
                    $stale = true;
                    $decisionOutcome = 'mark_stale';
                    $task->proposals->each(fn ($proposal) => $proposal->forceFill(['status' => 'stale'])->save());
                    $task->forceFill(['state' => 'stale', 'closed_at' => now(), 'lock_version' => $task->lock_version + 1])->save();
                }
            }

            $decision = ReviewDecision::query()->create([
                'review_task_id' => $task->id,
                'actor_id' => $actor->id,
                'outcome' => $decisionOutcome,
                'reason' => $reason,
                'evidence_refs' => $evidenceRefs,
                'baseline_version_id' => $task->proposals->first()?->baseline_version_id,
                'request_key' => $requestKey,
            ]);
            if ($task->proposals->isNotEmpty()) {
                $decision->proposals()->attach($task->proposals->pluck('id')->all());
            }

            if (! $stale) {
                switch ($outcome) {
                    case 'claim':
                        if ($task->assignee_id !== null && (int) $task->assignee_id !== (int) $actor->id) {
                            throw new ConflictHttpException('This task is assigned to another reviewer.');
                        }
                        $task->assignee_id = $actor->id;
                        $task->state = 'in_review';
                        break;
                    case 'assign':
                        $task->assignee_id = $assigneeId;
                        if ($task->state === 'pending') {
                            $task->state = 'in_review';
                        }
                        break;
                    case 'unassign':
                        $task->assignee_id = null;
                        break;
                    case 'approve':
                        $version = $this->approvedChanges->apply($task, $actor);
                        $task->proposals->each(fn ($proposal) => $proposal->forceFill(['status' => 'approved'])->save());
                        if ($task->duplicateCandidate !== null) {
                            $task->duplicateCandidate->forceFill([
                                'state' => 'resolved',
                                'resolved_scholarship_id' => $task->target_scholarship_id ?? $task->duplicateCandidate->candidate_scholarship_id,
                                'resolved_cycle_id' => $task->target_cycle_id ?? $version?->cycle_id ?? $task->duplicateCandidate->candidate_cycle_id,
                            ])->save();
                        }
                        $task->state = 'approved';
                        $task->closed_at = now();
                        break;
                    case 'reject':
                        $task->proposals->each(fn ($proposal) => $proposal->forceFill(['status' => 'rejected'])->save());
                        $task->duplicateCandidate?->forceFill(['state' => 'rejected'])->save();
                        $task->state = 'rejected';
                        $task->closed_at = now();
                        break;
                    case 'needs_evidence':
                        $task->proposals->each(fn ($proposal) => $proposal->forceFill(['status' => 'needs_evidence'])->save());
                        $task->state = 'needs_evidence';
                        $task->closed_at = now();
                        break;
                    case 'cancel':
                        $task->proposals->each(fn ($proposal) => $proposal->forceFill(['status' => 'cancelled'])->save());
                        $task->state = 'cancelled';
                        $task->closed_at = now();
                        break;
                }
                $task->lock_version++;
                $task->save();
            }

            return ['task' => $task->refresh()->load('proposals', 'decisions'), 'decision' => $decision, 'version' => $version, 'stale' => $stale];
        }, attempts: 3);
    }
}
