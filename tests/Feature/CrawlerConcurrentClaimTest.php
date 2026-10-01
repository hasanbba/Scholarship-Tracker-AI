<?php

namespace Tests\Feature;

use App\Models\CrawlerWorker;
use App\Models\ScholarshipSource;
use App\Models\User;
use App\Services\Crawler\CrawlJobClaimService;
use App\Services\Crawler\CrawlJobService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrawlerConcurrentClaimTest extends TestCase
{
    use DatabaseMigrations;

    public function test_locked_job_is_skipped_and_only_one_worker_gets_the_source_slot(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The claim-lock test requires MySQL row locking.');
        }

        $source = ScholarshipSource::query()->create([
            'source_type' => 'other_official', 'source_name' => 'Lock fixture',
            'source_url' => 'https://example.test/lock', 'source_url_hash' => hash('sha256', 'https://example.test/lock'),
            'crawl_enabled' => true, 'robots_policy' => 'allowed', 'source_concurrency_limit' => 1,
        ]);
        $actor = User::factory()->create();
        $firstWorker = CrawlerWorker::query()->create(['label' => 'worker one', 'status' => 'active']);
        $secondWorker = CrawlerWorker::query()->create(['label' => 'worker two', 'status' => 'active']);
        $job = app(CrawlJobService::class)->createManual($source, $actor, 'concurrent-job');

        config(['database.connections.crawler_lock_holder' => DB::connection()->getConfig()]);
        DB::purge('crawler_lock_holder');
        $holder = DB::connection('crawler_lock_holder');
        $holder->beginTransaction();
        try {
            $holder->table('crawl_jobs')->where('id', $job->id)->lock('for update')->first();
            $this->assertNull(app(CrawlJobClaimService::class)->claim($firstWorker, 'locked-first-claim'));
        } finally {
            $holder->rollBack();
            DB::purge('crawler_lock_holder');
        }

        $claim = app(CrawlJobClaimService::class)->claim($firstWorker, 'first-claim');
        $this->assertNotNull($claim);
        $this->assertNull(app(CrawlJobClaimService::class)->claim($secondWorker, 'second-claim'));
        $this->assertDatabaseCount('crawl_attempts', 1);
        $this->assertDatabaseHas('crawl_jobs', ['id' => $job->id, 'assigned_worker_id' => $firstWorker->id, 'current_attempt_id' => $claim['attempt_id'], 'lease_generation' => 1]);
    }
}
