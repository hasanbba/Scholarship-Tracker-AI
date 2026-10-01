<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Degree;
use App\Models\Region;
use App\Models\ScholarshipSource;
use App\Models\Subject;
use App\Models\University;
use App\Services\DataQuality\ChangeDetectionService;
use App\Services\DataQuality\ObservationIngestionService;
use App\Services\DataQuality\ObservationProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase6HtmlExtractionTest extends TestCase
{
    use RefreshDatabase;

    private ScholarshipSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
        $region = Region::query()->create(['name' => 'Review Region', 'slug' => 'phase6-review-region']);
        $country = Country::query()->create(['region_id' => $region->id, 'name' => 'Bangladesh', 'normalized_name' => 'bangladesh', 'slug' => 'phase6-bangladesh']);
        $university = University::query()->create(['country_id' => $country->id, 'name' => 'Review University', 'normalized_name' => 'review university', 'slug' => 'phase6-review-university', 'official_url' => 'https://review.example.edu', 'status' => 'active']);
        Subject::query()->create(['name' => 'Computer Science', 'normalized_name' => 'computer science', 'slug' => 'phase6-computer-science', 'status' => 'active']);
        Degree::query()->create(['name' => 'Master', 'normalized_name' => 'master', 'slug' => 'phase6-master', 'level' => 'master', 'status' => 'active']);
        $url = 'https://review.example.edu/scholarships/review-fellowship';
        $this->source = ScholarshipSource::query()->create([
            'university_id' => $university->id, 'source_type' => 'university_page', 'source_name' => 'Phase 6 HTML fixture',
            'source_url' => $url, 'source_url_hash' => hash('sha256', $url), 'status' => 'active', 'is_primary' => true,
        ]);
    }

    public function test_complete_html_extracts_normalized_fields_with_traceable_evidence_and_phase4_review_handoff(): void
    {
        $run = $this->processFixture('complete.html', 'complete-html');
        $this->assertSame('succeeded', $run->status, json_encode(['errors' => $run->validation_errors, 'normalized' => $run->normalized_payload, 'warnings' => $run->validation_warnings]));
        $this->assertSame('deterministic-html-scholarship', $run->parser_name);
        $this->assertSame('html-v1', $run->parser_version);
        $this->assertSame('Review Fellowship', $run->normalized_payload['scholarship']['title']);
        $this->assertSame('2027-01-15', $run->normalized_payload['cycle']['deadline']);
        $this->assertSame('2026-10-01', $run->normalized_payload['cycle']['opening_date']);
        $this->assertSame('https://review.example.edu/apply/2027', $run->normalized_payload['cycle']['application_url']);
        $this->assertSame('12000.00', $run->normalized_payload['funding']['stipend_amount']);
        $this->assertSame('GBP', $run->normalized_payload['funding']['stipend_currency']);
        $this->assertSame('year', $run->normalized_payload['funding']['stipend_period']);
        $this->assertContains('ielts', collect($run->normalized_payload['eligibility_rules'])->pluck('rule_type')->all());
        $this->assertContains('gpa', collect($run->normalized_payload['eligibility_rules'])->pluck('rule_type')->all());
        $this->assertContains('nationality', collect($run->normalized_payload['eligibility_rules'])->pluck('rule_type')->all());
        $this->assertSame(1, count($run->normalized_payload['subjects']));
        $this->assertContains('degree', collect($run->normalized_payload['eligibility_rules'])->pluck('rule_type')->all());
        $this->assertSame('normalized', collect($run->normalized_payload['_phase6']['fields'])->firstWhere('path', 'scholarship.subject')['status']);
        $this->assertSame('normalized', collect($run->normalized_payload['_phase6']['fields'])->firstWhere('path', 'scholarship.degree')['status']);
        $deadline = collect($run->normalized_payload['_phase6']['fields'])->firstWhere('path', 'scholarship.deadline');
        $this->assertSame('normalized', $deadline['status']);
        $this->assertSame($run->observation_id, $deadline['evidence']['observation_id']);
        $this->assertSame($run->id, $deadline['evidence']['processing_run_id']);
        $this->assertSame('html-v1', $deadline['evidence']['parser_version']);
        $this->assertNotEmpty($deadline['evidence']['locator']);

        $task = app(ChangeDetectionService::class)->detect($run);
        $this->assertNotNull($task);
        $this->assertSame('new_record', $task->task_type);
        $this->assertSame(0, \App\Models\Scholarship::query()->count());
        $this->assertSame(0, \App\Models\VerificationRecord::query()->count());
        $this->assertSame(0, \App\Models\PublicationEvent::query()->count());
        $deadlineProposal = $task->proposals()->where('field_path', 'cycle.deadline')->firstOrFail();
        $this->assertArrayHasKey('phase6_fields', $deadlineProposal->evidence_locator);
    }

    public function test_missing_funding_is_unknown_not_zero_and_remains_missing(): void
    {
        $run = $this->processFixture('partial.html', 'partial-html');
        $this->assertSame('succeeded', $run->status);
        $this->assertSame([], $run->normalized_payload['funding']);
        $this->assertArrayNotHasKey('stipend_amount', $run->normalized_payload['funding']);
        $stipend = collect($run->normalized_payload['_phase6']['fields'])->firstWhere('path', 'funding.stipend');
        $this->assertSame('missing', $stipend['status']);
        $this->assertNull($stipend['normalized_value']);
    }

    public function test_funding_table_preserves_currency_period_and_maximum_semantics(): void
    {
        $run = $this->processFixture('funding-table.html', 'funding-table');
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('10000.00', $run->normalized_payload['funding']['stipend_amount']);
        $this->assertSame('EUR', $run->normalized_payload['funding']['stipend_currency']);
        $this->assertSame('year', $run->normalized_payload['funding']['stipend_period']);
        $this->assertStringContainsString('maximum; not guaranteed', $run->normalized_payload['funding']['notes']);
        $stipend = collect($run->normalized_payload['_phase6']['fields'])->firstWhere('path', 'funding.stipend');
        $this->assertTrue($stipend['normalized_value']['maximum']);
    }

    public function test_eligibility_table_extracts_toefl_and_gpa_requirements(): void
    {
        $run = $this->processFixture('eligibility-table.html', 'eligibility-table');
        $this->assertSame('succeeded', $run->status);
        $rules = collect($run->normalized_payload['eligibility_rules'])->keyBy('rule_type');
        $this->assertSame('90', $rules['toefl']['normalized_value']);
        $this->assertSame('3.2', $rules['gpa']['normalized_value']);
        $this->assertSame('Bangladesh', $rules['nationality']['normalized_value']);
    }

    public function test_ambiguous_dates_are_uncertain_and_not_proposed_as_a_date(): void
    {
        $run = $this->processFixture('ambiguous-date.html', 'ambiguous-date');
        $this->assertSame('succeeded', $run->status);
        $deadline = collect($run->normalized_payload['_phase6']['fields'])->firstWhere('path', 'scholarship.deadline');
        $this->assertSame('uncertain', $deadline['status']);
        $this->assertNull($deadline['normalized_value']);
        $task = app(ChangeDetectionService::class)->detect($run);
        $this->assertNotNull($task);
        $this->assertDatabaseMissing('proposed_changes', ['processing_run_id' => $run->id, 'field_path' => 'cycle.deadline']);
    }

    public function test_impossible_date_is_invalid_with_original_evidence_retained(): void
    {
        $run = $this->processFixture('invalid-date.html', 'invalid-calendar-date');
        $this->assertSame('invalid', $run->status);
        $this->assertContains('invalid_date', collect($run->validation_errors)->pluck('code')->all());
        $deadline = collect($run->normalized_payload['_phase6']['fields'])->firstWhere('path', 'scholarship.deadline');
        $this->assertSame('31 February 2027', $deadline['raw_value']);
        $this->assertSame('invalid', $deadline['status']);
        $this->assertNotNull($deadline['evidence']['artifact_ref']);
    }

    public function test_unambiguous_numeric_date_normalizes_and_relative_date_stays_uncertain(): void
    {
        $numeric = $this->processFixture('numeric-date.html', 'numeric-date');
        $this->assertSame('succeeded', $numeric->status);
        $this->assertSame('2027-01-15', $numeric->normalized_payload['cycle']['deadline']);

        $relative = $this->processFixture('relative-date.html', 'relative-date');
        $deadline = collect($relative->normalized_payload['_phase6']['fields'])->firstWhere('path', 'scholarship.deadline');
        $this->assertSame('uncertain', $deadline['status']);
        $this->assertSame('next Friday', $deadline['raw_value']);
        $this->assertNull($relative->normalized_payload['cycle']['deadline']);
    }

    public function test_conflicting_deadlines_and_jsonld_visible_conflicts_are_retained_for_review(): void
    {
        $deadlineRun = $this->processFixture('contradictory-deadline.html', 'conflicting-deadline');
        $issue = collect($deadlineRun->normalized_payload['_phase6']['issues'])->firstWhere('code', 'conflicting_extractions');
        $this->assertNotNull($issue, json_encode(['extracted' => $deadlineRun->extracted_payload, 'normalized' => $deadlineRun->normalized_payload, 'errors' => $deadlineRun->validation_errors]));
        $this->assertSame('scholarship.deadline', $issue['field']);
        $this->assertCount(2, $issue['competing_values']);
        $this->assertSame('succeeded', $deadlineRun->status, json_encode(['errors' => $deadlineRun->validation_errors, 'normalized' => $deadlineRun->normalized_payload]));
        app(ChangeDetectionService::class)->detect($deadlineRun);
        $this->assertDatabaseMissing('proposed_changes', ['processing_run_id' => $deadlineRun->id, 'field_path' => 'cycle.deadline']);

        $jsonLdRun = $this->processFixture('jsonld-visible.html', 'jsonld-visible-conflict');
        $jsonLdIssue = collect($jsonLdRun->validation_warnings)->firstWhere('code', 'conflicting_extractions');
        $this->assertSame('scholarship.title', $jsonLdIssue['field']);
        $this->assertContains('Different Fellowship', $jsonLdIssue['competing_values']);
        $this->assertSame('invalid', $jsonLdRun->status);
    }

    public function test_invalid_gpa_is_preserved_as_invalid_and_cannot_reach_review_detection(): void
    {
        $run = $this->processFixture('invalid-gpa.html', 'invalid-gpa');
        $this->assertSame('invalid', $run->status);
        $this->assertContains('gpa_exceeds_scale', collect($run->validation_errors)->pluck('code')->all());
        $gpa = collect($run->normalized_payload['_phase6']['fields'])->firstWhere('path', 'eligibility.gpa');
        $this->assertSame('Minimum GPA 7.5/4.0', $gpa['raw_value']);
        $this->assertSame('invalid', $gpa['status']);
        $this->assertSame(0, $run->proposedChanges()->count());
    }

    public function test_repeated_and_changed_observations_keep_distinct_processing_history(): void
    {
        $observation = $this->ingestFixture('complete.html', 'repeat-receipt-one');
        $processor = app(ObservationProcessor::class);
        $first = $processor->process($observation, 'repeat-run-one');
        $replay = $processor->process($observation, 'repeat-run-one');
        $reprocess = $processor->process($observation, 'repeat-run-two');
        $changedObservation = $this->ingestFixture('changed.html', 'changed-receipt');
        $changed = $processor->process($changedObservation, 'changed-run');

        $this->assertSame($first->id, $replay->id);
        $this->assertNotSame($first->id, $reprocess->id);
        $this->assertNotSame($observation->content_hash, $changedObservation->content_hash);
        $this->assertSame('2027-01-31', $changed->normalized_payload['cycle']['deadline']);
        $this->assertSame(3, \App\Models\ProcessingRun::query()->whereIn('observation_id', [$observation->id, $changedObservation->id])->count());
    }

    public function test_pdf_is_preserved_with_explicit_unsupported_parser_state(): void
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/phase6/unsupported-pdf.txt'));
        $observation = app(ObservationIngestionService::class)->ingest($this->source, $this->source->source_url, $bytes, 'unsupported-pdf', 'fixture', null, 'success', 'application/pdf');
        $run = app(ObservationProcessor::class)->process($observation, 'unsupported-pdf-run');
        $this->assertSame('failed', $run->status);
        $this->assertSame('unsupported', $run->parser_name);
        $this->assertSame('unsupported_parser', $run->last_error_code);
        $this->assertTrue(Storage::disk('local')->exists($observation->artifact_ref));
        $this->assertDatabaseHas('raw_observations', ['id' => $observation->id, 'content_type' => 'application/pdf']);
    }

    private function processFixture(string $fixture, string $key): \App\Models\ProcessingRun
    {
        $observation = $this->ingestFixture($fixture, $key.'-receipt');
        return app(ObservationProcessor::class)->process($observation, $key.'-run');
    }

    private function ingestFixture(string $fixture, string $key): \App\Models\RawObservation
    {
        $html = file_get_contents(base_path('tests/Fixtures/phase6/'.$fixture));
        $this->assertIsString($html);
        return app(ObservationIngestionService::class)->ingest($this->source, $this->source->source_url, $html, $key, 'fixture', null, 'success', 'text/html');
    }
}
