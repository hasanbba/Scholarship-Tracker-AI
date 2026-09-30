<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 180);
            $table->string('slug', 180)->unique();
            $table->char('iso2', 2)->nullable()->unique();
            $table->char('iso3', 3)->nullable()->unique();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique('normalized_name');
        });

        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('normalized_name', 220)->index();
            $table->string('slug', 220)->unique();
            $table->string('official_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['country_id', 'normalized_name']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('normalized_name', 180)->unique();
            $table->string('slug', 180)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('degrees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('normalized_name', 140)->unique();
            $table->string('slug', 140)->unique();
            $table->string('level', 40)->nullable()->index();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('scholarships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->string('title', 220);
            $table->string('slug', 240)->unique();
            $table->text('description')->nullable();
            $table->string('official_url', 2048)->nullable();
            $table->string('publication_status', 30)->default('draft')->index();
            $table->string('lifecycle_status', 20)->default('active')->index();
            $table->timestamps();
            $table->index(['publication_status', 'lifecycle_status']);
        });

        Schema::create('scholarship_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
            $table->string('cycle_key', 80);
            $table->string('label', 160);
            $table->date('opening_date')->nullable();
            $table->date('deadline')->nullable()->index();
            $table->string('application_url', 2048)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
            $table->unique(['scholarship_id', 'cycle_key']);
            $table->unique(['id', 'scholarship_id']);
            $table->index(['scholarship_id', 'status']);
        });

        Schema::create('scholarship_subject', function (Blueprint $table) {
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['scholarship_id', 'subject_id']);
            $table->index('subject_id');
        });

        Schema::create('scholarship_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_type', 40);
            $table->string('source_name', 200);
            $table->string('source_url', 2048);
            $table->char('source_url_hash', 64);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['scholarship_id', 'source_url_hash']);
            $table->index(['university_id', 'source_type']);
        });

        Schema::create('scholarship_funding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('scholarship_cycles')->restrictOnDelete()->unique();
            foreach (['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'] as $benefit) {
                $table->decimal($benefit.'_amount', 15, 2)->nullable();
                $table->char($benefit.'_currency', 3)->nullable();
                $table->string($benefit.'_period', 40)->nullable();
            }
            $table->string('classification', 24)->default('unknown')->index();
            $table->text('notes')->nullable();
            $table->string('verification_status', 24)->default('unverified')->index();
            $table->timestamps();
        });

        Schema::create('scholarship_eligibility_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('scholarship_cycles')->restrictOnDelete();
            $table->string('rule_type', 40)->index();
            $table->string('operator', 24);
            $table->json('normalized_value');
            $table->string('unit', 40)->nullable();
            $table->string('display_text', 500)->nullable();
            $table->foreignId('degree_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['cycle_id', 'rule_type']);
        });

        Schema::create('scholarship_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('cycle_id');
            $table->foreign(['cycle_id', 'scholarship_id'])->references(['id', 'scholarship_id'])->on('scholarship_cycles')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->string('change_type', 40);
            $table->string('origin_type', 32)->default('human');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('scholarship_sources')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['cycle_id', 'version_number'], 'schol_ver_cycle_no_uq');
            $table->index(['scholarship_id', 'cycle_id', 'version_number'], 'schol_ver_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_versions');
        Schema::dropIfExists('scholarship_eligibility_rules');
        Schema::dropIfExists('scholarship_funding');
        Schema::dropIfExists('scholarship_sources');
        Schema::dropIfExists('scholarship_subject');
        Schema::dropIfExists('scholarship_cycles');
        Schema::dropIfExists('scholarships');
        Schema::dropIfExists('degrees');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('universities');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('regions');
    }
};
