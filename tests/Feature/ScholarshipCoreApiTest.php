<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipVersion;
use App\Models\Subject;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class ScholarshipCoreApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_catalog_entities_are_unique_and_public_catalog_is_paginated(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/catalog/subjects', ['name' => 'Computer Science', 'slug' => 'computer-science']);
        $response->assertCreated()->assertJsonPath('data.slug', 'computer-science');
        $this->actingAs($this->admin)->postJson('/api/v1/admin/catalog/subjects', ['name' => 'Duplicate', 'slug' => 'computer-science'])->assertUnprocessable();
        $this->getJson('/api/v1/catalog/subjects')->assertOk()->assertJsonPath('data.0.name', 'Computer Science')->assertJsonStructure(['meta' => ['current_page', 'last_page', 'total']]);
    }

    public function test_country_university_catalog_relationship_and_normalized_identity(): void
    {
        $region = $this->actingAs($this->admin)->postJson('/api/v1/admin/catalog/regions', ['name' => 'South Asia', 'slug' => 'south-asia'])->assertCreated()->json('data');
        $country = $this->actingAs($this->admin)->postJson('/api/v1/admin/catalog/countries', ['name' => 'Bangladesh', 'slug' => 'bangladesh', 'iso2' => 'BD', 'region_id' => $region['id']])->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson('/api/v1/admin/catalog/countries', ['name' => 'Bangladesh', 'slug' => 'bd-again'])->assertUnprocessable();
        $university = $this->actingAs($this->admin)->postJson('/api/v1/admin/catalog/universities', ['country_id' => $country['id'], 'name' => 'Example University', 'slug' => 'example-university', 'official_url' => 'https://example.edu'])->assertCreated();
        $this->assertDatabaseHas('universities', ['id' => $university->json('data.id'), 'country_id' => $country['id']]);
        $this->getJson('/api/v1/catalog/universities')->assertOk()->assertJsonPath('data.0.country.id', $country['id']);
    }

    public function test_guest_and_student_cannot_mutate_admin_catalog(): void
    {
        $payload = ['name' => 'Physics', 'slug' => 'physics'];
        $this->postJson('/api/v1/admin/catalog/subjects', $payload)->assertUnauthorized();
        $student = User::factory()->create();
        $student->roles()->attach(Role::query()->where('name', 'student')->firstOrFail());
        $this->actingAs($student)->postJson('/api/v1/admin/catalog/subjects', $payload)->assertForbidden();
    }

    public function test_scholarship_has_stable_identity_and_multiple_distinct_cycles(): void
    {
        $university = University::factory()->create();
        $subject = Subject::factory()->create(['name' => 'Data Science', 'slug' => 'data-science']);
        $scholarship = $this->actingAs($this->admin)->postJson('/api/v1/admin/scholarships', ['university_id' => $university->id, 'title' => 'Example Graduate Scholarship', 'slug' => 'example-graduate-scholarship', 'subject_ids' => [$subject->id]])
            ->assertCreated()->assertJsonPath('data.subjects.0.id', $subject->id)->json('data');

        $first = $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship['id']}/cycles", ['cycle_key' => '2026', 'label' => '2026 Cycle', 'opening_date' => '2026-01-01', 'deadline' => '2026-04-30'])->assertCreated()->json('data');
        $second = $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship['id']}/cycles", ['cycle_key' => '2027', 'label' => '2027 Cycle', 'opening_date' => '2027-01-01', 'deadline' => '2027-04-30'])->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship['id']}/cycles", ['cycle_key' => '2026', 'label' => 'duplicate', 'deadline' => '2026-04-30'])->assertUnprocessable();
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(1, ScholarshipVersion::query()->where('cycle_id', $first['id'])->value('version_number'));
        $this->assertSame(1, ScholarshipVersion::query()->where('cycle_id', $second['id'])->value('version_number'));
        $this->assertSame(1, Scholarship::query()->findOrFail($scholarship['id'])->subjects()->count());
    }

    public function test_scholarship_rejects_duplicate_subject_pairs_and_stable_slug(): void
    {
        $university = University::factory()->create();
        $subject = Subject::factory()->create();
        $payload = ['university_id' => $university->id, 'title' => 'Example Stable Program', 'slug' => 'example-stable-program', 'subject_ids' => [$subject->id, $subject->id]];
        $this->actingAs($this->admin)->postJson('/api/v1/admin/scholarships', $payload)->assertUnprocessable()->assertJsonValidationErrors('subject_ids.1');

        $payload['subject_ids'] = [$subject->id];
        $this->actingAs($this->admin)->postJson('/api/v1/admin/scholarships', $payload)->assertCreated();
        $payload['title'] = 'Another program identity';
        $this->actingAs($this->admin)->postJson('/api/v1/admin/scholarships', $payload)->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_cycle_deadline_cannot_precede_opening_date(): void
    {
        $scholarship = Scholarship::factory()->create();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship->id}/cycles", ['cycle_key' => '2026', 'label' => '2026 Cycle', 'opening_date' => '2026-05-02', 'deadline' => '2026-05-01'])->assertUnprocessable()->assertJsonValidationErrors('deadline');
    }

    public function test_funding_unknown_is_null_and_version_snapshots_preserve_cycle_history(): void
    {
        $cycle = ScholarshipCycle::factory()->create();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/cycles/{$cycle->id}/funding", ['classification' => 'unknown'])->assertOk()->assertJsonPath('data.tuition.amount', null);
        $this->actingAs($this->admin)->putJson("/api/v1/admin/cycles/{$cycle->id}/funding", ['classification' => 'partially_funded', 'tuition_amount' => '10000.00', 'tuition_currency' => 'USD', 'tuition_period' => 'year'])->assertOk()->assertJsonPath('data.tuition.amount', '10000.00');
        $this->assertSame('10000.00', $cycle->versions()->orderByDesc('version_number')->firstOrFail()->snapshot['funding']['tuition_amount']);
        $this->actingAs($this->admin)->putJson("/api/v1/admin/cycles/{$cycle->id}/funding", ['classification' => 'tuition_only', 'tuition_amount' => '0.00', 'tuition_currency' => 'USD', 'tuition_period' => 'year'])->assertOk()->assertJsonPath('data.tuition.amount', '0.00');
        $versions = $cycle->versions()->orderBy('version_number')->get();
        $this->assertCount(3, $versions);
        $this->assertSame('10000.00', $versions[1]->snapshot['funding']['tuition_amount']);
        $this->assertSame('0.00', $versions[2]->snapshot['funding']['tuition_amount']);
        $this->assertNull($versions[0]->snapshot['funding']['tuition_amount']);
        $this->expectException(LogicException::class);
        $versions[1]->update(['change_type' => 'tampered']);
    }

    public function test_eligibility_rules_are_typed_and_each_change_creates_an_immutable_cycle_snapshot(): void
    {
        $university = University::factory()->create();
        $scholarship = $this->actingAs($this->admin)->postJson('/api/v1/admin/scholarships', ['university_id' => $university->id, 'title' => 'Example IELTS Scholarship', 'slug' => 'example-ielts-scholarship'])->assertCreated()->json('data');
        $cycle = $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship['id']}/cycles", ['cycle_key' => '2026', 'label' => '2026 Cycle'])->assertCreated()->json('data');
        $response = $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle['id']}/eligibility", ['rule_type' => 'ielts', 'operator' => '>=', 'normalized_value' => 6.5, 'unit' => 'band', 'display_text' => 'IELTS 6.5 or higher']);
        $response->assertCreated()->assertJsonPath('data.cycle_id', $cycle['id'])->assertJsonPath('data.normalized_value', 6.5);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle['id']}/eligibility", ['rule_type' => 'gpa', 'operator' => '>=', 'normalized_value' => 3, 'unit' => '4.0 scale'])->assertCreated();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle['id']}/eligibility", ['rule_type' => 'gpa', 'operator' => 'in', 'normalized_value' => 3])->assertUnprocessable()->assertJsonValidationErrors('normalized_value');
        $cycleModel = ScholarshipCycle::query()->findOrFail($cycle['id']);
        $this->assertCount(3, $cycleModel->versions()->get());
        $this->assertCount(2, $cycleModel->versions()->latest('version_number')->firstOrFail()->snapshot['eligibility_rules']);
    }

    public function test_source_url_is_canonicalized_and_duplicate_source_is_rejected(): void
    {
        $scholarship = Scholarship::factory()->create();
        $payload = ['source_type' => 'university_page', 'source_name' => 'Official page', 'source_url' => 'HTTPS://EXAMPLE.EDU/scholarship/'];
        $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship->id}/sources", $payload)->assertCreated()->assertJsonPath('data.source_url', 'https://example.edu/scholarship');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship->id}/sources", [...$payload, 'source_url' => 'https://example.edu/scholarship'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship->id}/sources", [...$payload, 'source_url' => 'not a url'])->assertUnprocessable()->assertJsonValidationErrors('source_url');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarships/{$scholarship->id}/sources", [...$payload, 'source_type' => 'social_media'])->assertUnprocessable()->assertJsonValidationErrors('source_type');
    }

    public function test_funding_and_eligibility_have_only_cycle_ownership_and_versions_are_cycle_scoped(): void
    {
        $fundingColumns = Schema::getColumnListing('scholarship_funding');
        $eligibilityColumns = Schema::getColumnListing('scholarship_eligibility_rules');
        $versionColumns = Schema::getColumnListing('scholarship_versions');
        $this->assertContains('cycle_id', $fundingColumns);
        $this->assertNotContains('version_id', $fundingColumns);
        $this->assertContains('cycle_id', $eligibilityColumns);
        $this->assertNotContains('version_id', $eligibilityColumns);
        $this->assertContains('scholarship_id', $versionColumns);
        $this->assertContains('cycle_id', $versionColumns);
    }

    public function test_database_rejects_a_version_linked_to_a_different_scholarship_than_its_cycle(): void
    {
        $cycle = ScholarshipCycle::factory()->create();
        $other = Scholarship::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('scholarship_versions')->insert([
            'scholarship_id' => $other->id,
            'cycle_id' => $cycle->id,
            'version_number' => 1,
            'snapshot' => json_encode(['cycle' => ['cycle_key' => $cycle->cycle_key]], JSON_THROW_ON_ERROR),
            'change_type' => 'test',
            'origin_type' => 'system',
            'created_at' => now(),
        ]);
    }

    public function test_admin_cannot_bypass_future_review_workflow_by_publishing_or_verifying(): void
    {
        $scholarship = Scholarship::factory()->create();
        $cycle = ScholarshipCycle::factory()->create(['scholarship_id' => $scholarship->id]);

        $this->actingAs($this->admin)->patchJson('/api/v1/admin/scholarships/'.$scholarship->id, ['publication_status' => 'published'])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson('/api/v1/admin/cycles/'.$cycle->id.'/funding', ['classification' => 'fully_funded', 'verification_status' => 'verified'])->assertUnprocessable();
    }
}
