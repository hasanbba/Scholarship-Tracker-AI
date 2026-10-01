<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_attempts', function (Blueprint $table): void {
            $table->uuid('artifact_id')->nullable()->unique();
            $table->string('artifact_ref', 1024)->nullable();
            $table->string('artifact_content_type', 160)->nullable();
            $table->json('artifact_metadata')->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->string('result_idempotency_key', 191)->nullable();
            $table->json('fetch_result')->nullable();
            $table->unsignedBigInteger('observation_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_attempts', function (Blueprint $table): void {
            $table->dropIndex(['observation_id']);
            $table->dropUnique(['artifact_id']);
            $table->dropColumn(['artifact_id', 'artifact_ref', 'artifact_content_type', 'artifact_metadata', 'result_hash', 'result_idempotency_key', 'fetch_result', 'observation_id']);
        });
    }
};
