<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_sources', function (Blueprint $table) {
            $table->boolean('crawl_enabled')->default(false)->after('status');
            $table->string('crawl_method', 24)->default('http')->after('crawl_enabled');
            $table->string('crawl_frequency', 24)->default('manual')->after('crawl_method');
            $table->unsignedSmallInteger('crawl_priority')->default(0)->after('crawl_frequency');
            $table->string('allowed_path_prefix', 2048)->nullable()->after('crawl_priority');
            $table->string('robots_policy', 24)->default('unknown')->after('allowed_path_prefix');
            $table->unsignedTinyInteger('source_concurrency_limit')->default(1)->after('robots_policy');
            $table->index(['crawl_enabled', 'status', 'robots_policy'], 'source_crawl_eligibility_idx');
        });

        Schema::create('crawler_workers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('label', 120);
            $table->string('software_version', 40)->nullable();
            $table->unsignedSmallInteger('protocol_version')->default(1);
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crawler_worker_activation_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->string('worker_label', 120);
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('crawler_workers')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crawler_worker_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('crawler_workers')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->json('scopes');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rotated_from_id')->nullable()->constrained('crawler_worker_credentials')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['worker_id', 'revoked_at']);
        });

        Schema::create('crawl_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('scholarship_sources')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->string('state', 24)->default('queued')->index();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('requested_url', 2048);
            $table->timestamp('available_at')->index();
            $table->foreignId('assigned_worker_id')->nullable()->constrained('crawler_workers')->nullOnDelete();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->unsignedInteger('lease_generation')->default(0);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->string('last_error_category', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at', 'priority', 'id'], 'crawl_jobs_claim_idx');
            $table->index(['source_id', 'state', 'lease_expires_at'], 'crawl_jobs_source_slots_idx');
        });

        Schema::create('crawl_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('crawl_jobs')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('crawler_workers')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->unsignedInteger('lease_generation');
            $table->char('lease_token_hash', 64);
            $table->string('claim_request_key', 191);
            $table->string('state', 24)->default('leased');
            $table->string('requested_url', 2048);
            $table->string('final_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_duration_ms')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedBigInteger('content_length')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->unique(['job_id', 'attempt_number']);
            $table->unique(['worker_id', 'claim_request_key']);
            $table->unique('lease_token_hash');
            $table->index(['worker_id', 'state', 'lease_expires_at']);
        });

        Schema::table('crawl_jobs', function (Blueprint $table) {
            $table->foreignId('current_attempt_id')->nullable()->after('assigned_worker_id')->constrained('crawl_attempts')->nullOnDelete();
        });

        Schema::create('crawler_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64)->index();
            $table->foreignId('source_id')->nullable()->constrained('scholarship_sources')->restrictOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('crawl_jobs')->restrictOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('crawl_attempts')->restrictOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('crawler_workers')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->index(['job_id', 'occurred_at']);
            $table->index(['worker_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawler_events');
        Schema::table('crawl_jobs', function (Blueprint $table) {
            $table->dropForeign(['current_attempt_id']);
        });
        Schema::dropIfExists('crawl_attempts');
        Schema::dropIfExists('crawl_jobs');
        Schema::dropIfExists('crawler_worker_credentials');
        Schema::dropIfExists('crawler_worker_activation_codes');
        Schema::dropIfExists('crawler_workers');
        Schema::table('scholarship_sources', function (Blueprint $table) {
            $table->dropIndex('source_crawl_eligibility_idx');
            $table->dropColumn(['crawl_enabled', 'crawl_method', 'crawl_frequency', 'crawl_priority', 'allowed_path_prefix', 'robots_policy', 'source_concurrency_limit']);
        });
    }
};
