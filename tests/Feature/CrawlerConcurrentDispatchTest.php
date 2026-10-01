<?php

namespace Tests\Feature;

use App\Models\ScholarshipSource;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrawlerConcurrentDispatchTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_dispatchers_create_only_one_source_job(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent dispatch requires MySQL row locking.');
        }

        $url = 'https://scheduler-race.example.test/current';
        $source = ScholarshipSource::query()->create([
            'source_type' => 'other_official', 'source_name' => 'Concurrent dispatch fixture',
            'source_url' => $url, 'source_url_hash' => hash('sha256', $url), 'status' => 'active',
            'crawl_enabled' => true, 'crawl_method' => 'http', 'crawl_frequency' => 'daily',
            'crawl_priority' => 20, 'allowed_path_prefix' => '/', 'robots_policy' => 'allowed', 'source_concurrency_limit' => 1,
        ]);

        config(['database.connections.crawler_due_lock_holder' => DB::connection()->getConfig()]);
        DB::purge('crawler_due_lock_holder');
        $holder = DB::connection('crawler_due_lock_holder');
        $holder->beginTransaction();
        $holder->table('scholarship_sources')->where('id', $source->id)->lock('for update')->first();

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'crawler-dispatch-race-'.bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        $processes = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $log = fopen($directory.DIRECTORY_SEPARATOR.'dispatch-'.$index.'.log', 'ab');
                $process = proc_open([PHP_BINARY, base_path('artisan'), 'crawler:dispatch-due', '--limit=200'], [['pipe', 'r'], $log, $log], $pipes, base_path());
                if (! is_resource($process)) $this->fail('Could not start concurrent due dispatcher process.');
                if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
                $processes[] = [$process, $log];
            }

            usleep(300_000);
            foreach ($processes as [$process]) {
                $this->assertTrue(proc_get_status($process)['running'], 'Both dispatchers should contend on the locked source row.');
            }
            $holder->commit();

            foreach ($processes as [$process, $log]) {
                $this->assertSame(0, proc_close($process));
                fclose($log);
            }
            $this->assertSame(1, DB::table('crawl_jobs')->where('source_id', $source->id)->count());
            $this->assertSame(1, DB::table('crawler_events')->where('source_id', $source->id)->where('event_type', 'job_scheduled')->count());
        } finally {
            if ($holder->transactionLevel() > 0) $holder->rollBack();
            foreach ($processes as [$process, $log]) {
                if (is_resource($process)) @proc_terminate($process);
                if (is_resource($process)) @proc_close($process);
                if (is_resource($log)) @fclose($log);
            }
            DB::purge('crawler_due_lock_holder');
            \Illuminate\Support\Facades\File::deleteDirectory($directory);
        }
    }
}
