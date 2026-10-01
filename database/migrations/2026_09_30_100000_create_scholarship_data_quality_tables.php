<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_versions', function (Blueprint $table): void {
            $table->unique(['id', 'cycle_id', 'scholarship_id'], 'schol_ver_owner_triplet_uq');
        });

        Schema::create('raw_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained('scholarship_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->string('observed_url', 2048);
            $table->char('observed_url_hash', 64);
            $table->timestamp('observed_at');
            $table->char('content_hash', 64);
            $table->string('artifact_ref', 1024);
            $table->string('content_type', 160)->nullable();
            $table->unsignedBigInteger('content_length')->nullable();
            $table->enum('source_access_status', ['success', 'blocked', 'not_found', 'rate_limited', 'error', 'unknown'])->default('success');
            $table->string('producer_type', 32);
            $table->string('producer_ref', 191)->nullable();
            $table->string('idempotency_key', 191);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_id', 'idempotency_key'], 'observation_source_idempotency_uq');
            $table->index(['source_id', 'content_hash', 'observed_at'], 'observation_source_hash_time_idx');
            $table->index(['source_id', 'observed_url_hash', 'content_hash', 'observed_at'], 'observation_source_url_hash_idx');
            $table->index(['source_id', 'observed_at'], 'observation_source_time_idx');
        });

        Schema::create('processing_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->constrained('raw_observations')->restrictOnDelete()->restrictOnUpdate();
            $table->string('run_key', 191);
            $table->string('parser_name', 80);
            $table->string('parser_version', 80);
            $table->string('normalization_version', 80);
            $table->string('validation_version', 80);
            $table->enum('status', ['queued', 'running', 'succeeded', 'invalid', 'failed'])->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->json('extracted_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('validation_warnings')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['observation_id', 'run_key'], 'processing_observation_run_key_uq');
            $table->index(['status', 'created_at'], 'processing_status_created_idx');
            $table->index(['observation_id', 'created_at'], 'processing_observation_created_idx');
        });

        Schema::create('duplicate_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('processing_run_id')->constrained('processing_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('candidate_scholarship_id')->constrained('scholarships')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('candidate_cycle_id')->nullable()->constrained('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('classification', ['program_identity', 'cycle_identity']);
            $table->enum('state', ['possible', 'confirmed', 'rejected', 'resolved'])->default('possible');
            $table->json('signals');
            $table->decimal('score', 7, 6)->nullable();
            $table->foreignId('resolved_scholarship_id')->nullable()->constrained('scholarships')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('resolved_cycle_id')->nullable()->constrained('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('candidate_cycle_scope')->storedAs('COALESCE(candidate_cycle_id, 0)');
            $table->timestamps();
            $table->unique(['processing_run_id', 'candidate_scholarship_id', 'candidate_cycle_scope'], 'duplicate_run_target_uq');
            $table->foreign(['candidate_cycle_id', 'candidate_scholarship_id'], 'duplicate_candidate_cycle_owner_fk')
                ->references(['id', 'scholarship_id'])->on('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['resolved_cycle_id', 'resolved_scholarship_id'], 'duplicate_resolved_cycle_owner_fk')
                ->references(['id', 'scholarship_id'])->on('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['state', 'created_at'], 'duplicate_state_created_idx');
            $table->index(['candidate_scholarship_id', 'candidate_cycle_id'], 'duplicate_candidate_owner_idx');
        });

        Schema::create('proposed_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('processing_run_id')->constrained('processing_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('raw_observation_id')->constrained('raw_observations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('target_scholarship_id')->nullable()->constrained('scholarships')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('target_cycle_id')->nullable()->constrained('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('baseline_version_id')->nullable()->constrained('scholarship_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->string('field_path', 191);
            $table->json('old_value')->nullable();
            $table->json('extracted_value')->nullable();
            $table->json('proposed_value');
            $table->text('display_old')->nullable();
            $table->text('display_new')->nullable();
            $table->string('normalization_version', 80);
            $table->json('evidence_locator');
            $table->string('detection_reason', 80);
            $table->enum('materiality', ['material', 'non_material', 'identity']);
            $table->enum('status', ['pending', 'approved', 'rejected', 'needs_evidence', 'cancelled', 'stale'])->default('pending');
            $table->unsignedBigInteger('target_cycle_scope')->storedAs('COALESCE(target_cycle_id, 0)');
            $table->unsignedBigInteger('baseline_version_scope')->storedAs('COALESCE(baseline_version_id, 0)');
            $table->timestamps();
            $table->foreign(['target_cycle_id', 'target_scholarship_id'], 'proposed_cycle_owner_fk')
                ->references(['id', 'scholarship_id'])->on('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['baseline_version_id', 'target_cycle_id', 'target_scholarship_id'], 'proposed_baseline_owner_fk')
                ->references(['id', 'cycle_id', 'scholarship_id'])->on('scholarship_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['processing_run_id', 'target_cycle_scope', 'baseline_version_scope', 'field_path'], 'proposal_run_baseline_field_uq');
            $table->index(['target_cycle_id', 'status', 'created_at'], 'proposal_cycle_state_created_idx');
            $table->index('baseline_version_id', 'proposal_baseline_idx');
            $table->index(['processing_run_id', 'field_path'], 'proposal_run_field_idx');
        });

        Schema::create('review_tasks', function (Blueprint $table): void {
            $table->id();
            $table->enum('task_type', ['change_review', 'new_record', 'duplicate_resolution', 'diagnostic']);
            $table->foreignId('target_scholarship_id')->nullable()->constrained('scholarships')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('target_cycle_id')->nullable()->constrained('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('processing_run_id')->nullable()->constrained('processing_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('duplicate_candidate_id')->nullable()->constrained('duplicate_candidates')->restrictOnDelete()->restrictOnUpdate();
            $table->smallInteger('priority')->default(0);
            $table->enum('state', ['pending', 'in_review', 'approved', 'rejected', 'needs_evidence', 'cancelled', 'stale'])->default('pending');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedBigInteger('target_cycle_scope')->storedAs('COALESCE(target_cycle_id, 0)');
            $table->unsignedBigInteger('active_slot')->storedAs("CASE WHEN state IN ('pending','in_review') THEN 1 ELSE NULL END");
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->foreign(['target_cycle_id', 'target_scholarship_id'], 'review_task_cycle_owner_fk')
                ->references(['id', 'scholarship_id'])->on('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['processing_run_id', 'target_cycle_scope', 'active_slot'], 'review_task_open_run_target_uq');
            $table->index(['state', 'priority', 'created_at'], 'review_task_state_priority_idx');
            $table->index(['assignee_id', 'state', 'created_at'], 'review_task_assignee_state_idx');
            $table->index(['target_cycle_id', 'state'], 'review_task_cycle_state_idx');
        });

        Schema::create('review_task_proposed_change', function (Blueprint $table): void {
            $table->foreignId('review_task_id')->constrained('review_tasks')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('proposed_change_id')->constrained('proposed_changes')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['review_task_id', 'proposed_change_id'], 'review_task_proposal_pk');
            $table->index('proposed_change_id', 'review_proposal_reverse_idx');
        });

        Schema::create('review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_task_id')->constrained('review_tasks')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()->restrictOnUpdate();
            $table->enum('outcome', ['claim', 'approve', 'reject', 'needs_evidence', 'cancel', 'mark_stale', 'assign', 'unassign']);
            $table->text('reason')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->foreignId('baseline_version_id')->nullable()->constrained('scholarship_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->string('request_key', 191);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['review_task_id', 'request_key'], 'review_decision_request_uq');
            $table->index(['review_task_id', 'id'], 'review_decision_history_idx');
            $table->index(['actor_id', 'created_at'], 'review_decision_actor_idx');
        });

        Schema::create('review_decision_proposals', function (Blueprint $table): void {
            $table->foreignId('review_decision_id')->constrained('review_decisions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('proposed_change_id')->constrained('proposed_changes')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['review_decision_id', 'proposed_change_id'], 'review_decision_proposal_pk');
            $table->index('proposed_change_id', 'review_decision_proposal_reverse_idx');
        });

        Schema::create('field_provenance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained('scholarships')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('version_id');
            $table->string('field_path', 191);
            $table->foreignId('source_id')->nullable()->constrained('scholarship_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->string('source_url_snapshot', 2048)->nullable();
            $table->foreignId('raw_observation_id')->nullable()->constrained('raw_observations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('processing_run_id')->nullable()->constrained('processing_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('observed_at')->nullable();
            $table->string('extraction_method', 80);
            $table->string('parser_version', 80)->nullable();
            $table->enum('review_state', ['approved', 'manual_recorded', 'carried_forward']);
            $table->char('provenance_key', 64);
            $table->json('evidence_locator')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['version_id', 'cycle_id', 'scholarship_id'], 'provenance_version_owner_fk')
                ->references(['id', 'cycle_id', 'scholarship_id'])->on('scholarship_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['version_id', 'field_path', 'provenance_key'], 'provenance_version_field_key_uq');
            $table->index(['scholarship_id', 'cycle_id', 'field_path', 'version_id'], 'provenance_field_history_idx');
            $table->index('raw_observation_id', 'provenance_observation_idx');
            $table->index('source_id', 'provenance_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_provenance');
        Schema::dropIfExists('review_decision_proposals');
        Schema::dropIfExists('review_decisions');
        Schema::dropIfExists('review_task_proposed_change');
        Schema::dropIfExists('review_tasks');
        Schema::dropIfExists('proposed_changes');
        Schema::dropIfExists('duplicate_candidates');
        Schema::dropIfExists('processing_runs');
        Schema::dropIfExists('raw_observations');

        Schema::table('scholarship_versions', function (Blueprint $table): void {
            $table->dropUnique('schol_ver_owner_triplet_uq');
        });
    }
};
