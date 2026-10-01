<?php

namespace Tests\Feature;

use App\Models\CrawlAttempt;
use App\Models\CrawlerEvent;
use App\Models\CrawlerWorker;
use App\Models\CrawlJob;
use App\Models\Role;
use App\Models\ScholarshipSource;
use App\Models\User;
use App\Services\Crawler\ExpireCrawlLeasesService;
use App\Services\Crawler\WorkerActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrawlerFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ScholarshipSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->source = ScholarshipSource::query()->create([
            'source_type' => 'other_official', 'source_name' => 'Crawler Fixture',
            'source_url' => 'https://example.test/scholarships', 'source_url_hash' => hash('sha256', 'https://example.test/scholarships'),
        ]);
    }

    public function test_activation_tokens_scopes_and_worker_only_authentication(): void
    {
        $issued = $this->actingAs($this->admin)->postJson('/api/v1/admin/crawler/activation-codes', ['worker_label' => 'PC crawler'])->assertCreated()->json('data');
        $this->assertDatabaseMissing('crawler_worker_activation_codes', ['code_hash' => $issued['activation_code']]);

        $response = $this->postJson('/api/v1/crawler/workers/activate', ['activation_code' => $issued['activation_code'], 'software_version' => '0.1.0', 'protocol_version' => 1])->assertCreated()->assertJsonPath('success', true);
        $workerUuid = $response->json('data.worker.uuid');
        $token = $response->json('data.token');
        $worker = CrawlerWorker::query()->where('uuid', $workerUuid)->firstOrFail();
        $credential = $worker->credentials()->firstOrFail();
        $this->assertNotSame($token, $credential->token_hash);
        $this->assertSame(hash('sha256', $token), $credential->token_hash);
        $this->postJson('/api/v1/crawler/workers/activate', ['activation_code' => $issued['activation_code'], 'protocol_version' => 1])->assertUnauthorized()->assertJsonPath('success', false);
        $this->withToken($token)->getJson('/api/v1/crawler/workers/me')->assertOk()->assertJsonPath('data.status', 'idle');
        $this->flushHeaders()->actingAs($this->admin)->postJson("/api/v1/admin/crawler/workers/{$worker->id}/credentials/rotate")->assertOk();
        $this->withToken($token)->getJson('/api/v1/crawler/workers/me')->assertUnauthorized();
        $this->flushHeaders()->actingAs($this->admin)->postJson("/api/v1/admin/crawler/workers/{$worker->id}/disable")->assertOk();
        $this->withToken($token)->getJson('/api/v1/crawler/workers/me')->assertForbidden();
        $this->assertGreaterThanOrEqual(3, CrawlerEvent::query()->count());
    }

    public function test_expired_activation_codes_and_invalid_worker_tokens_are_rejected(): void
    {
        config(['crawler.activation_ttl_minutes' => -1]);
        $issued = $this->actingAs($this->admin)->postJson('/api/v1/admin/crawler/activation-codes', ['worker_label' => 'expired'])->assertCreated()->json('data');
        $this->postJson('/api/v1/crawler/workers/activate', ['activation_code' => $issued['activation_code'], 'protocol_version' => 1])->assertUnauthorized()->assertJsonPath('success', false);
        $this->withToken('invalid-token')->getJson('/api/v1/crawler/workers/me')->assertUnauthorized()->assertJsonPath('success', false);
        $this->postJson('/api/v1/crawler/workers/activate', ['activation_code' => 'bad'])->assertUnprocessable()->assertJsonPath('success', false);
        for ($index = 0; $index < 3; $index++) {
            $this->postJson('/api/v1/crawler/workers/activate', ['activation_code' => str_repeat('0', 64), 'protocol_version' => 1])->assertUnauthorized();
        }
        $this->postJson('/api/v1/crawler/workers/activate', ['activation_code' => str_repeat('1', 64), 'protocol_version' => 1])->assertTooManyRequests()->assertJsonPath('success', false);
    }

    public function test_worker_bearer_token_cannot_authenticate_admin_routes(): void
    {
        $issued = app(WorkerActivationService::class)->issueCode('isolated worker', $this->admin);
        $token = $this->postJson('/api/v1/crawler/workers/activate', ['activation_code' => $issued['activation_code'], 'protocol_version' => 1])->assertCreated()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/admin/foundation')->assertUnauthorized()->assertJsonPath('success', false);
    }

    public function test_admin_source_configuration_and_manual_jobs_obey_permissions_and_schedule(): void
    {
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/crawler/sources/{$this->source->id}/configuration", [
            'crawl_enabled' => true, 'robots_policy' => 'allowed', 'crawl_frequency' => 'daily', 'allowed_path_prefix' => '/scholarships',
        ])->assertOk()->assertJsonPath('data.crawl_enabled', true);
        $future = now()->addHour()->toIso8601String();
        $job = $this->actingAs($this->admin)->postJson("/api/v1/admin/crawler/sources/{$this->source->id}/jobs", ['idempotency_key' => 'future-job-1', 'available_at' => $future])->assertCreated()->json('data');
        $this->assertSame('queued', $job['state']);
        $this->assertNull($this->withToken($this->workerToken())->postJson('/api/v1/crawler/jobs/claim', ['claim_request_key' => 'claim-future'])->json('data.job_id'));

        $this->flushHeaders()->actingAs(User::factory()->create())->postJson("/api/v1/admin/crawler/sources/{$this->source->id}/jobs", ['idempotency_key' => 'unauthorized'])->assertForbidden();
        $this->source->forceFill(['status' => 'inactive'])->save();
        $this->flushHeaders()->actingAs($this->admin)->postJson("/api/v1/admin/crawler/sources/{$this->source->id}/jobs", ['idempotency_key' => 'inactive-source'])->assertUnprocessable();
    }

    public function test_claim_is_idempotent_creates_one_attempt_and_heartbeat_is_fenced(): void
    {
        $this->source->forceFill(['crawl_enabled' => true, 'robots_policy' => 'allowed', 'crawl_method' => 'http'])->save();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/crawler/sources/{$this->source->id}/jobs", ['idempotency_key' => 'job-for-claim'])->assertCreated();
        $token = $this->workerToken();
        $first = $this->withToken($token)->postJson('/api/v1/crawler/jobs/claim', ['claim_request_key' => 'claim-idempotent'])->assertOk()->assertJsonPath('success', true)->json('data');
        $this->assertSame($this->source->id, $first['source_id']);
        $this->assertSame($this->source->source_url, $first['registered_source_url']);
        $this->assertSame($this->source->robots_policy, $first['robots_policy']);
        $this->assertSame(1, $first['source_concurrency_limit']);
        $again = $this->withToken($token)->postJson('/api/v1/crawler/jobs/claim', ['claim_request_key' => 'claim-idempotent'])->assertOk()->json('data');
        $this->assertSame($first['attempt_id'], $again['attempt_id']);
        $this->assertSame(1, $this->source->fresh()->crawlJobs()->count());
        $this->assertDatabaseCount('crawl_attempts', 1);
        $this->assertDatabaseHas('crawl_jobs', ['id' => $first['job_id'], 'current_attempt_id' => $first['attempt_id']]);
        $this->withToken($token)->postJson("/api/v1/crawler/jobs/{$first['job_id']}/attempts/{$first['attempt_id']}/heartbeat", ['lease_token' => str_repeat('0', 64)])->assertConflict();
        $this->withToken($token)->postJson("/api/v1/crawler/jobs/{$first['job_id']}/attempts/{$first['attempt_id']}/heartbeat", ['lease_token' => $first['lease_token']])->assertOk();
        $this->assertDatabaseHas('crawler_events', ['event_type' => 'heartbeat', 'job_id' => $first['job_id']]);
        $secondToken = $this->workerToken();
        $this->withToken($secondToken)->postJson('/api/v1/crawler/jobs/claim', ['claim_request_key' => 'second-worker'])->assertOk()->assertJsonPath('data', null);
        $this->withToken($token)->postJson('/api/v1/crawler/jobs/claim', [])->assertUnprocessable()->assertJsonPath('success', false);
    }

    public function test_expired_lease_is_detected_and_requeued_or_failed_at_attempt_limit(): void
    {
        $worker = CrawlerWorker::query()->create(['label' => 'fixture', 'status' => 'active', 'activated_at' => now(), 'last_heartbeat_at' => now()]);
        $this->source->forceFill(['crawl_enabled' => true, 'robots_policy' => 'allowed'])->save();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/crawler/sources/{$this->source->id}/jobs", ['idempotency_key' => 'expire-job'])->assertCreated();
        $job = CrawlJob::query()->firstOrFail();
        $job->forceFill(['state' => 'leased', 'assigned_worker_id' => $worker->id, 'lease_generation' => 1, 'attempt_count' => 1, 'lease_expires_at' => now()->subSecond()])->save();
        CrawlAttempt::query()->create(['job_id' => $job->id, 'worker_id' => $worker->id, 'attempt_number' => 1, 'lease_generation' => 1, 'lease_token_hash' => hash('sha256', 'x'), 'claim_request_key' => 'expired-attempt', 'state' => 'leased', 'requested_url' => $job->requested_url, 'started_at' => now()->subMinute(), 'lease_expires_at' => now()->subSecond()]);
        $this->assertSame(1, app(ExpireCrawlLeasesService::class)->reap());
        $this->assertSame('retry_pending', $job->fresh()->state);
        $this->assertDatabaseHas('crawler_events', ['event_type' => 'lease_expired', 'job_id' => $job->id]);
    }

    public function test_crawler_artifact_and_pdf_result_handoff_is_private_idempotent_and_completes_job(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->source->forceFill(['crawl_enabled' => true, 'robots_policy' => 'allowed', 'crawl_method' => 'http'])->save();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/crawler/sources/{$this->source->id}/jobs", ['idempotency_key' => 'phase5c-pdf'])->assertCreated();
        $token = $this->workerToken();
        $claim = $this->withToken($token)->postJson('/api/v1/crawler/jobs/claim', ['claim_request_key' => 'phase5c-claim'])->assertOk()->json('data');
        $bytes = "%PDF-1.4\nphase 5c evidence";
        $hash = hash('sha256', $bytes);
        $artifactId = (string) \Illuminate\Support\Str::uuid();
        $metadata = ['lease_generation' => $claim['lease_generation'], 'artifact_id' => $artifactId, 'sha256' => $hash,
            'content_type' => 'application/pdf', 'content_length' => strlen($bytes), 'requested_url' => $claim['requested_url'],
            'final_url' => $claim['requested_url'], 'fetched_at' => now()->toIso8601String()];
        $path = "/api/v1/crawler/jobs/{$claim['job_id']}/attempts/{$claim['attempt_id']}/artifacts";
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/pdf',
            'HTTP_X_CRAWLER_METADATA' => rtrim(strtr(base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)), '+/', '-_'), '='), 'HTTP_IDEMPOTENCY_KEY' => 'upload:phase5c:'.$hash];
        $upload = $this->call('POST', $path, [], [], [], $headers, $bytes)->assertCreated()->json('data');
        $this->assertSame($hash, $upload['sha256']);
        $this->assertTrue(Storage::disk('local')->exists($upload['artifact_ref']));
        Storage::disk('public')->assertMissing($upload['artifact_ref']);
        $result = ['lease_generation' => $claim['lease_generation'], 'outcome' => 'fetched', 'requested_url' => $claim['requested_url'],
            'final_url' => $claim['requested_url'], 'fetched_at' => $metadata['fetched_at'], 'status_code' => 200,
            'content_type' => 'application/pdf', 'content_length' => strlen($bytes), 'sha256' => $hash, 'artifact_id' => $artifactId,
            'artifact_reference' => $claim['attempt_id'].'/payload.bin', 'redirects' => [], 'robots_result' => 'allowed',
            'duration_ms' => 12, 'failure_code' => null, 'retryable' => false, 'response_metadata' => []];
        $resultPath = "/api/v1/crawler/jobs/{$claim['job_id']}/attempts/{$claim['attempt_id']}/results";
        $receipt = $this->withToken($token)->withHeader('Idempotency-Key', 'result:phase5c:'.$claim['attempt_id'])->postJson($resultPath, $result)->assertOk()->json('data');
        $this->assertSame('completed', $receipt['state']);
        $this->assertDatabaseHas('raw_observations', ['id' => $receipt['observation_id'], 'producer_type' => 'crawler', 'content_hash' => $hash]);
        $this->assertDatabaseHas('processing_runs', ['observation_id' => $receipt['observation_id'], 'last_error_code' => 'unsupported_parser']);
        $this->assertDatabaseHas('crawl_jobs', ['id' => $claim['job_id'], 'state' => 'completed']);
        $again = $this->withToken($token)->withHeader('Idempotency-Key', 'result:phase5c:'.$claim['attempt_id'])->postJson($resultPath, $result)->assertOk()->json('data');
        $this->assertSame($receipt['observation_id'], $again['observation_id']);
        $this->assertDatabaseCount('raw_observations', 1);
    }

    public function test_failed_fetch_result_is_audited_retryable_and_rejects_stale_generation(): void
    {
        $this->source->forceFill(['crawl_enabled' => true, 'robots_policy' => 'allowed', 'crawl_method' => 'http'])->save();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/crawler/sources/{$this->source->id}/jobs", ['idempotency_key' => 'phase5c-failure'])->assertCreated();
        $token = $this->workerToken();
        $claim = $this->withToken($token)->postJson('/api/v1/crawler/jobs/claim', ['claim_request_key' => 'phase5c-failed-claim'])->assertOk()->json('data');
        $result = ['lease_generation' => $claim['lease_generation'] + 1, 'outcome' => 'failed', 'requested_url' => $claim['requested_url'],
            'final_url' => null, 'fetched_at' => now()->toIso8601String(), 'status_code' => 429, 'content_type' => null,
            'content_length' => 0, 'sha256' => null, 'artifact_id' => null, 'artifact_reference' => null,
            'redirects' => [], 'robots_result' => null, 'duration_ms' => 10, 'failure_code' => 'HTTP_429',
            'retryable' => true, 'response_metadata' => []];
        $path = "/api/v1/crawler/jobs/{$claim['job_id']}/attempts/{$claim['attempt_id']}/results";
        $this->withToken($token)->withHeader('Idempotency-Key', 'phase5c-stale')->postJson($path, $result)->assertConflict();
        $result['lease_generation']--;
        $receipt = $this->withToken($token)->withHeader('Idempotency-Key', 'phase5c-failure-result')->postJson($path, $result)->assertOk()->json('data');
        $this->assertSame('failed', $receipt['state']);
        $this->assertDatabaseHas('crawl_jobs', ['id' => $claim['job_id'], 'state' => 'retry_pending', 'last_error_category' => 'HTTP_429']);
        $this->assertDatabaseCount('raw_observations', 0);
        $this->assertDatabaseHas('crawler_events', ['event_type' => 'job.retry_pending', 'attempt_id' => $claim['attempt_id']]);
    }

    private function workerToken(): string
    {
        $issued = $this->flushHeaders()->actingAs($this->admin)->postJson('/api/v1/admin/crawler/activation-codes', ['worker_label' => 'claim fixture'])->assertCreated()->json('data');

        return $this->postJson('/api/v1/crawler/workers/activate', ['activation_code' => $issued['activation_code'], 'protocol_version' => 1])->assertCreated()->json('data.token');
    }
}
