<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\FieldProvenance;
use App\Models\Permission;
use App\Models\ProcessingRun;
use App\Models\RawObservation;
use App\Models\Region;
use App\Models\ReviewTask;
use App\Models\Role;
use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipFunding;
use App\Models\ScholarshipSource;
use App\Models\ScholarshipVersion;
use App\Models\University;
use App\Models\User;
use App\Services\DataQuality\ChangeDetectionService;
use App\Services\DataQuality\ObservationIngestionService;
use App\Services\DataQuality\ObservationProcessor;
use App\Services\Scholarships\CreateScholarshipVersionService;
use App\Services\Scholarships\PublicationService;
use App\Services\Scholarships\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ScholarshipDataQualityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private University $university;

    private Scholarship $scholarship;

    private ScholarshipCycle $cycle;

    private ScholarshipSource $source;

    private ScholarshipVersion $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $region = Region::query()->create(['name' => 'Review Region', 'slug' => 'review-region']);
        $country = Country::query()->create(['region_id' => $region->id, 'name' => 'Review Country', 'normalized_name' => 'review country', 'slug' => 'review-country']);
        $this->university = University::query()->create(['country_id' => $country->id, 'name' => 'Review University', 'normalized_name' => 'review university', 'slug' => 'review-university', 'official_url' => 'https://review.example.edu']);
        $this->scholarship = Scholarship::query()->create(['university_id' => $this->university->id, 'title' => 'Review Fellowship', 'slug' => 'review-fellowship', 'description' => 'Current description', 'official_url' => 'https://review.example.edu/fellowship']);
        $this->cycle = $this->scholarship->cycles()->create(['cycle_key' => '2027', 'label' => '2027 Cycle', 'opening_date' => '2026-10-01', 'deadline' => '2027-04-01', 'application_url' => 'https://review.example.edu/apply/2027', 'status' => 'open']);
        ScholarshipFunding::query()->create(['cycle_id' => $this->cycle->id, 'classification' => 'unknown', 'verification_status' => 'unverified']);
        $this->source = $this->makeSource($this->scholarship, '/fellowship');
        $this->baseline = app(CreateScholarshipVersionService::class)->create($this->cycle, $this->admin, 'test_baseline', $this->source->id);
        app(VerificationService::class)->decide($this->baseline, $this->admin, 'verified', $this->source->id, 'Baseline fixture verified.');
        app(PublicationService::class)->publish($this->cycle, $this->baseline, $this->admin, 'Baseline fixture publication.');
    }

    public function test_observation_idempotency_processing_approval_provenance_and_publication_safety(): void
    {
        $this->actingAs($this->admin);
        $payload = json_encode($this->candidate(['deadline' => '2027-05-01']), JSON_THROW_ON_ERROR);
        $request = [
            'source_id' => $this->source->id,
            'observed_url' => $this->source->source_url,
            'raw_payload' => $payload,
            'content_type' => 'application/json',
            'producer_type' => 'fixture',
            'idempotency_key' => 'receipt-001',
        ];

        $observationId = $this->postJson('/api/v1/admin/data-quality/observations', $request)
            ->assertCreated()->json('data.id');
        $this->assertSame($observationId, $this->postJson('/api/v1/admin/data-quality/observations', $request)->assertOk()->json('data.id'));
        $this->postJson('/api/v1/admin/data-quality/observations', [...$request, 'raw_payload' => str_replace('2027-05-01', '2027-05-02', $payload)])->assertStatus(409);

        $taskId = $this->postJson("/api/v1/admin/data-quality/observations/{$observationId}/processing-runs", ['run_key' => 'json-v1-run-1'])
            ->assertCreated()->assertJsonPath('data.processing_run.status', 'succeeded')->json('data.review_task_id');
        $this->assertNotNull($taskId);
        $this->getJson("/api/v1/admin/review-tasks/{$taskId}")
            ->assertOk()->assertJsonPath('data.proposals.0.field_path', 'cycle.deadline');

        $this->postJson("/api/v1/admin/review-tasks/{$taskId}/decisions", [
            'outcome' => 'approve',
            'request_key' => 'decision-001',
            'reason' => 'Official deadline date confirmed in the captured source.',
            'evidence_refs' => ["observation:{$observationId}#/cycle/deadline"],
        ])->assertOk()->assertJsonPath('data.task.state', 'approved');

        $versions = $this->cycle->versions()->orderBy('version_number')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('2027-04-01', $versions[0]->snapshot['cycle']['deadline']);
        $this->assertSame('2027-05-01', $versions[1]->snapshot['cycle']['deadline']);
        $this->assertSame($this->baseline->id, $this->cycle->fresh()->published_version_id);
        $this->assertSame('pending', $versions[1]->effectiveVerificationStatus());
        $this->assertDatabaseHas('field_provenance', [
            'version_id' => $versions[1]->id,
            'field_path' => 'cycle.deadline',
            'raw_observation_id' => $observationId,
            'review_state' => 'approved',
        ]);
        $this->assertGreaterThan(0, FieldProvenance::query()->where('version_id', $versions[1]->id)->where('review_state', 'manual_recorded')->count());

        $observation = RawObservation::query()->findOrFail($observationId);
        $this->expectException(LogicException::class);
        $observation->update(['content_hash' => str_repeat('a', 64)]);
    }

    public function test_stale_proposals_are_marked_stale_and_cannot_apply_to_a_newer_version(): void
    {
        $observation = $this->ingestAndProcess($this->source, $this->candidate(['deadline' => '2027-05-02']), 'stale-source-1', 'stale-run-1');
        $task = ReviewTask::query()->where('processing_run_id', $observation['run']->id)->firstOrFail();

        $this->cycle->forceFill(['deadline' => '2027-04-15'])->save();
        $newer = app(CreateScholarshipVersionService::class)->create($this->cycle, $this->admin, 'intervening_admin_change', $this->source->id);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/review-tasks/{$task->id}/decisions", [
            'outcome' => 'approve',
            'request_key' => 'stale-approval-1',
            'evidence_refs' => ["observation:{$observation['observation']->id}#/cycle/deadline"],
        ])->assertStatus(409);

        $this->assertSame('stale', $task->fresh()->state);
        $this->assertSame('stale', $task->proposals()->firstOrFail()->fresh()->status);
        $this->assertSame($newer->id, $this->cycle->versions()->orderByDesc('version_number')->firstOrFail()->id);
        $this->assertSame('2027-04-15', $newer->snapshot['cycle']['deadline']);
    }

    public function test_new_source_for_same_program_creates_duplicate_candidate_but_approval_does_not_auto_merge_or_create_version(): void
    {
        $secondSource = $this->makeSource($this->scholarship, '/fellowship-alt');
        $result = $this->ingestAndProcess($secondSource, $this->candidate(), 'second-source-receipt', 'second-source-run');
        $task = ReviewTask::query()->where('processing_run_id', $result['run']->id)->firstOrFail();
        $candidate = $task->duplicateCandidate;

        $this->assertNotNull($candidate);
        $this->assertSame('program_identity', $candidate->classification);
        $this->assertSame('possible', $candidate->state);
        $this->assertSame(1, $this->cycle->versions()->count());

        $this->actingAs($this->admin)->postJson("/api/v1/admin/review-tasks/{$task->id}/decisions", [
            'outcome' => 'approve',
            'request_key' => 'resolve-duplicate-1',
            'reason' => 'Confirmed as the existing program from a second official page.',
            'evidence_refs' => ["observation:{$result['observation']->id}"],
        ])->assertOk();

        $this->assertSame('resolved', $candidate->fresh()->state);
        $this->assertSame($this->scholarship->id, $candidate->fresh()->resolved_scholarship_id);
        $this->assertSame(1, $this->cycle->versions()->count());
    }

    public function test_new_cycle_is_not_classified_as_a_duplicate_scholarship(): void
    {
        $candidate = $this->candidate(['cycle_key' => '2028', 'label' => '2028 Cycle', 'deadline' => '2028-04-01', 'application_url' => 'https://review.example.edu/apply/2028']);
        $result = $this->ingestAndProcess($this->source, $candidate, 'new-cycle-receipt', 'new-cycle-run');
        $task = ReviewTask::query()->where('processing_run_id', $result['run']->id)->firstOrFail();

        $this->assertSame('new_record', $task->task_type);
        $this->assertNull($task->duplicate_candidate_id);
        $this->assertNull($task->target_cycle_id);
        $this->assertSame($this->scholarship->id, $task->target_scholarship_id);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/review-tasks/{$task->id}/decisions", [
            'outcome' => 'approve',
            'request_key' => 'new-cycle-approval',
            'reason' => 'This is the next annual application cycle for the existing program.',
            'evidence_refs' => ["observation:{$result['observation']->id}"],
        ])->assertOk();

        $newCycle = $this->scholarship->cycles()->where('cycle_key', '2028')->firstOrFail();
        $this->assertSame('draft', $newCycle->status);
        $this->assertSame(1, $newCycle->versions()->count());
        $this->assertSame('pending', $newCycle->versions()->firstOrFail()->effectiveVerificationStatus());
        $this->assertNull($newCycle->published_version_id);
    }

    public function test_first_discovery_creates_new_program_and_cycle_only_after_review_approval(): void
    {
        $candidate = $this->candidate(['title' => 'A New Award', 'cycle_key' => '2027']);
        $candidate['scholarship']['title'] = 'A New Award';
        $candidate['scholarship']['official_url'] = 'https://review.example.edu/new-award';
        $payload = json_encode($candidate, JSON_THROW_ON_ERROR);
        $universitySource = ScholarshipSource::query()->create([
            'university_id' => $this->university->id,
            'source_type' => 'university_page',
            'source_name' => 'University awards page',
            'source_url' => 'https://review.example.edu/awards',
            'source_url_hash' => hash('sha256', 'https://review.example.edu/awards'),
            'status' => 'active',
        ]);
        $result = $this->ingestAndProcess($universitySource, $candidate, 'first-discovery-receipt', 'first-discovery-run');
        $task = ReviewTask::query()->where('processing_run_id', $result['run']->id)->firstOrFail();
        $this->assertSame('new_record', $task->task_type);
        $this->assertDatabaseMissing('scholarships', ['title' => 'A New Award']);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/review-tasks/{$task->id}/decisions", [
            'outcome' => 'approve',
            'request_key' => 'first-discovery-approval',
            'reason' => 'The official university source confirms this new program identity.',
            'evidence_refs' => ["observation:{$result['observation']->id}"],
        ])->assertOk();

        $newScholarship = Scholarship::query()->where('title', 'A New Award')->firstOrFail();
        $this->assertSame($this->university->id, $newScholarship->university_id);
        $newCycle = $newScholarship->cycles()->firstOrFail();
        $this->assertSame(1, $newCycle->versions()->count());
        $this->assertSame('unknown', $newCycle->funding->classification);
        $this->assertNull($newCycle->funding->tuition_amount);
        $this->assertSame('pending', $newCycle->versions()->firstOrFail()->effectiveVerificationStatus());
        $this->assertNull($newCycle->published_version_id);
    }

    public function test_processing_replay_and_reprocessing_preserve_immutable_run_history(): void
    {
        $result = $this->ingestAndProcess($this->source, $this->candidate(), 'processing-receipt', 'parser-one');
        $first = $result['run'];
        $processor = app(ObservationProcessor::class);
        $replay = $processor->process($result['observation'], 'parser-one', 'json-v1');
        $reprocessed = $processor->process($result['observation'], 'parser-two', 'json-v2');

        $this->assertSame($first->id, $replay->id);
        $this->assertNotSame($first->id, $reprocessed->id);
        $this->assertSame(2, $result['observation']->processingRuns()->count());
        $this->assertSame('json-v2', $reprocessed->parser_version);
        $this->expectException(LogicException::class);
        $reprocessed->update(['status' => 'failed']);
    }

    public function test_validation_failure_is_traced_and_cannot_create_a_proposal(): void
    {
        $candidate = $this->candidate(['deadline' => '2027-02-01', 'opening_date' => '2027-03-01']);
        $result = $this->ingestAndProcess($this->source, $candidate, 'invalid-date-range', 'invalid-run');

        $this->assertSame('invalid', $result['run']->status);
        $this->assertContains('deadline_before_opening', collect($result['run']->validation_errors)->pluck('code')->all());
        $this->assertSame(0, $result['run']->proposedChanges()->count());
    }

    public function test_guest_student_and_admin_without_data_quality_permissions_are_denied(): void
    {
        $url = '/api/v1/admin/data-quality/observations';
        $body = ['source_id' => $this->source->id, 'observed_url' => $this->source->source_url, 'raw_payload' => '{}', 'idempotency_key' => 'auth-test'];
        $this->postJson($url, $body)->assertUnauthorized();

        $student = User::factory()->create();
        $student->roles()->attach(Role::query()->where('name', 'student')->firstOrFail());
        $this->actingAs($student)->postJson($url, $body)->assertForbidden();

        $limitedRole = Role::query()->create(['name' => 'catalog_only', 'label' => 'Catalog Only']);
        $limitedRole->permissions()->attach(Permission::query()->where('name', 'admin.access')->firstOrFail());
        $limitedAdmin = User::factory()->create();
        $limitedAdmin->roles()->attach($limitedRole);
        $this->actingAs($limitedAdmin)->postJson($url, $body)->assertForbidden();
    }

    public function test_same_idempotency_key_cannot_be_reused_for_different_observation_content(): void
    {
        $service = app(ObservationIngestionService::class);
        $first = $service->ingest($this->source, $this->source->source_url, '{"x":1}', 'same-key');
        try {
            $service->ingest($this->source, $this->source->source_url, '{"x":2}', 'same-key');
            $this->fail('Receipt reuse with different content must conflict.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, $this->source->observations()->count());
            $this->assertSame(hash('sha256', '{"x":1}'), $first->content_hash);
        }
    }

    public function test_later_capture_of_identical_bytes_is_retained_but_does_not_create_a_change_task(): void
    {
        $candidate = $this->candidate();
        $first = $this->ingestAndProcess($this->source, $candidate, 'capture-one', 'capture-one-run');
        $payload = json_encode($candidate, JSON_THROW_ON_ERROR);
        $secondObservation = app(ObservationIngestionService::class)->ingest($this->source, $this->source->source_url, $payload, 'capture-two');
        $secondRun = app(ObservationProcessor::class)->process($secondObservation, 'capture-two-run');
        $task = app(ChangeDetectionService::class)->detect($secondRun);

        $this->assertNotSame($first['observation']->id, $secondObservation->id);
        $this->assertSame($first['observation']->content_hash, $secondObservation->content_hash);
        $this->assertSame(2, $this->source->observations()->count());
        $this->assertNull($task);
        $this->assertSame(0, $secondRun->proposedChanges()->count());
    }

    private function makeSource(Scholarship $scholarship, string $path): ScholarshipSource
    {
        $url = 'https://review.example.edu'.$path;

        return $scholarship->sources()->create([
            'source_type' => 'university_page',
            'source_name' => 'Official scholarship page',
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
            'is_primary' => true,
            'status' => 'active',
        ]);
    }

    private function candidate(array $cycleOverrides = []): array
    {
        return [
            'scholarship' => [
                'university_id' => $this->university->id,
                'title' => $this->scholarship->title,
                'description' => $this->scholarship->description,
                'official_url' => $this->scholarship->official_url,
            ],
            'cycle' => array_replace([
                'cycle_key' => '2027',
                'label' => '2027 Cycle',
                'opening_date' => '2026-10-01',
                'deadline' => '2027-04-01',
                'application_url' => 'https://review.example.edu/apply/2027',
            ], $cycleOverrides),
            'funding' => ['classification' => 'unknown'],
        ];
    }

    /** @return array{observation: RawObservation, run: ProcessingRun} */
    private function ingestAndProcess(ScholarshipSource $source, array $candidate, string $receiptKey, string $runKey): array
    {
        $observation = app(ObservationIngestionService::class)->ingest(
            $source,
            $source->source_url,
            json_encode($candidate, JSON_THROW_ON_ERROR),
            $receiptKey,
            'fixture',
        );
        $run = app(ObservationProcessor::class)->process($observation, $runKey);
        if ($run->status === 'succeeded') {
            app(ChangeDetectionService::class)->detect($run);
        }

        return ['observation' => $observation, 'run' => $run];
    }
}
