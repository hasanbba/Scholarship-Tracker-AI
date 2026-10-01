<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_versions', function (Blueprint $table) {
            $table->unique(['id', 'cycle_id'], 'schol_ver_id_cycle_uq');
        });

        Schema::table('scholarship_cycles', function (Blueprint $table) {
            $table->unsignedBigInteger('published_version_id')->nullable()->after('status');
            $table->index('published_version_id', 'sch_cycle_pub_ver_idx');
            $table->foreign(['published_version_id', 'id'], 'sch_cycle_pub_same_cycle_fk')
                ->references(['id', 'cycle_id'])
                ->on('scholarship_versions')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        Schema::create('verification_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('scholarship_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('status', ['pending', 'verified', 'rejected']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()->restrictOnUpdate();
            $table->foreignId('source_id')->nullable()->constrained('scholarship_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->text('notes')->nullable();
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['version_id', 'decided_at', 'id'], 'ver_record_effective_idx');
            $table->index(['source_id', 'status'], 'ver_record_source_status_idx');
        });

        Schema::create('publication_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('version_id');
            $table->enum('action', ['published', 'unpublished']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()->restrictOnUpdate();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('cycle_id', 'pub_event_cycle_fk')->references('id')->on('scholarship_cycles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['version_id', 'cycle_id'], 'pub_event_same_cycle_fk')
                ->references(['id', 'cycle_id'])
                ->on('scholarship_versions')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->index(['cycle_id', 'id'], 'pub_event_cycle_history_idx');
            $table->index(['version_id', 'action'], 'pub_event_version_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_events');
        Schema::dropIfExists('verification_records');

        Schema::table('scholarship_cycles', function (Blueprint $table) {
            $table->dropForeign('sch_cycle_pub_same_cycle_fk');
            $table->dropIndex('sch_cycle_pub_ver_idx');
            $table->dropColumn('published_version_id');
        });

        Schema::table('scholarship_versions', function (Blueprint $table) {
            $table->dropUnique('schol_ver_id_cycle_uq');
        });
    }
};
