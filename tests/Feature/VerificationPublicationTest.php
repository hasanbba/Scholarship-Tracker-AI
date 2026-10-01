<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PublicationEvent;
use App\Models\Role;
use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipSource;
use App\Models\ScholarshipVersion;
use App\Models\User;
use App\Models\VerificationRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class VerificationPublicationTest extends TestCase
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

    public function test_authorized_admin_records_verification_decisions_append_only_and_latest_is_deterministic(): void
    {
        [$cycle, $version, $source] = $this->makeVersion();

        $first = $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarship-versions/{$version->id}/reject", [
            'source_id' => $source->id,
            'notes' => 'The listed deadline conflicts with the official page.',
        ])->assertCreated()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.actor_id', $this->admin->id)->json('data');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarship-versions/{$version->id}/verify", [
            'source_id' => $source->id,
            'verified_by' => 999,
            'verified_at' => '2000-01-01T00:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors(['verified_by', 'verified_at']);

        $second = $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarship-versions/{$version->id}/verify", [
            'source_id' => $source->id,
            'notes' => 'Confirmed against the current official application page.',
        ])->assertCreated()->assertJsonPath('data.status', 'verified')->json('data');

        $this->assertSame(3, VerificationRecord::query()->where('version_id', $version->id)->count());
        $this->assertSame('rejected', VerificationRecord::query()->findOrFail($first['id'])->status);
        $this->assertSame('verified', $version->fresh()->effectiveVerificationStatus());
        $this->assertGreaterThanOrEqual($first['id'], $second['id']);

        $record = VerificationRecord::query()->findOrFail($first['id']);
        try {
            $record->update(['status' => 'verified']);
            $this->fail('Verification history must not be mutable.');
        } catch (LogicException) {
            $this->assertSame('rejected', $record->fresh()->status);
        }
        $this->assertSame($cycle->id, $version->fresh()->cycle_id);
    }

    public function test_guest_student_and_admin_without_permissions_cannot_verify_or_publish(): void
    {
        [$cycle, $version, $source] = $this->makeVersion();
        $verifyUrl = "/api/v1/admin/scholarship-versions/{$version->id}/verify";
        $publishUrl = "/api/v1/admin/cycles/{$cycle->id}/publish";

        $this->postJson($verifyUrl, ['source_id' => $source->id])->assertUnauthorized();

        $student = User::factory()->create();
        $student->roles()->attach(Role::query()->where('name', 'student')->firstOrFail());
        $this->actingAs($student)->postJson($verifyUrl, ['source_id' => $source->id])->assertForbidden();
        $this->actingAs($student)->postJson($publishUrl, ['version_id' => $version->id])->assertForbidden();

        $limitedRole = Role::query()->create(['name' => 'catalog_limited', 'label' => 'Catalog Limited']);
        $limitedRole->permissions()->attach(Permission::query()->where('name', 'admin.access')->firstOrFail());
        $limitedAdmin = User::factory()->create();
        $limitedAdmin->roles()->attach($limitedRole);
        $this->actingAs($limitedAdmin)->postJson($verifyUrl, ['source_id' => $source->id])->assertForbidden();
        $this->actingAs($limitedAdmin)->postJson($publishUrl, ['version_id' => $version->id])->assertForbidden();

        $this->assertSame(0, VerificationRecord::query()->where('version_id', $version->id)->whereIn('status', ['verified', 'rejected'])->count());
    }

    public function test_only_verified_version_can_publish_and_replacement_preserves_history(): void
    {
        [$cycle, $version1, $source] = $this->makeVersion();
        [$sameCycle, $version2] = $this->makeVersion($cycle, $source, 2);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle->id}/publish", ['version_id' => $version1->id])->assertUnprocessable();
        $this->assertNull($cycle->fresh()->published_version_id);
        $this->assertSame(0, PublicationEvent::query()->count());

        $this->verify($version1, $source);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle->id}/publish", ['version_id' => $version1->id])
            ->assertCreated()->assertJsonPath('data.event.action', 'published')->assertJsonPath('data.published_version_id', $version1->id);

        $this->assertSame($version1->id, $cycle->fresh()->publishedVersion->id);
        $this->assertSame('pending', $version2->fresh()->effectiveVerificationStatus());

        $snapshot1 = $version1->fresh()->snapshot;
        $this->verify($version2, $source);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$sameCycle->id}/publish", ['version_id' => $version2->id])
            ->assertCreated()->assertJsonPath('data.published_version_id', $version2->id);

        $this->assertSame($version2->id, $cycle->fresh()->published_version_id);
        $this->assertSame($snapshot1, $version1->fresh()->snapshot);
        $this->assertSame(2, PublicationEvent::query()->where('cycle_id', $cycle->id)->count());
        $this->assertSame($version1->id, PublicationEvent::query()->where('cycle_id', $cycle->id)->where('action', 'published')->oldest('id')->value('version_id'));
    }

    public function test_unpublish_clears_pointer_and_appends_event_without_changing_version_or_decisions(): void
    {
        [$cycle, $version, $source] = $this->makeVersion();
        $this->verify($version, $source);
        $snapshot = $version->snapshot;
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle->id}/publish", ['version_id' => $version->id])->assertCreated();

        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle->id}/unpublish", ['reason' => 'The cycle has been withdrawn.'])
            ->assertOk()->assertJsonPath('data.event.action', 'unpublished')->assertJsonPath('data.published_version_id', null);

        $this->assertNull($cycle->fresh()->published_version_id);
        $this->assertSame(2, PublicationEvent::query()->where('cycle_id', $cycle->id)->count());
        $this->assertSame(2, VerificationRecord::query()->where('version_id', $version->id)->count());
        $this->assertEquals($snapshot, $version->fresh()->snapshot);
        $this->assertSame('verified', $version->fresh()->effectiveVerificationStatus());
    }

    public function test_database_enforces_same_cycle_pointer_and_publication_event_ownership(): void
    {
        [$cycleA, $versionA] = $this->makeVersion();
        [$cycleB] = $this->makeVersion();

        try {
            DB::table('scholarship_cycles')->where('id', $cycleB->id)->update(['published_version_id' => $versionA->id]);
            $this->fail('A cycle must not point to a version from another cycle.');
        } catch (QueryException) {
            $this->assertNull($cycleB->fresh()->published_version_id);
        }

        try {
            PublicationEvent::query()->create(['cycle_id' => $cycleB->id, 'version_id' => $versionA->id, 'action' => 'published', 'actor_id' => $this->admin->id]);
            $this->fail('Publication history must not cross cycle ownership.');
        } catch (QueryException) {
            $this->assertSame(0, PublicationEvent::query()->count());
        }

        $this->assertNotSame($cycleA->id, $cycleB->id);
    }

    public function test_version_from_another_cycle_cannot_be_published_through_the_api(): void
    {
        [$cycleA, $versionA] = $this->makeVersion();
        [$cycleB] = $this->makeVersion();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycleB->id}/publish", ['version_id' => $versionA->id])
            ->assertNotFound();

        $this->assertNull($cycleA->fresh()->published_version_id);
        $this->assertNull($cycleB->fresh()->published_version_id);
        $this->assertSame(0, PublicationEvent::query()->count());
    }

    public function test_archived_cycle_or_scholarship_and_missing_official_source_cannot_be_published(): void
    {
        [$cycle, $version, $source] = $this->makeVersion();
        $this->verify($version, $source);

        $cycle->forceFill(['status' => 'draft'])->save();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle->id}/publish", ['version_id' => $version->id])->assertUnprocessable();
        $this->assertSame(0, PublicationEvent::query()->count());

        $cycle->forceFill(['status' => 'archived'])->save();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle->id}/publish", ['version_id' => $version->id])->assertUnprocessable();
        $this->assertSame(0, PublicationEvent::query()->count());

        $cycle->forceFill(['status' => 'draft'])->save();
        $source->forceFill(['status' => 'inactive'])->save();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle->id}/publish", ['version_id' => $version->id])->assertUnprocessable();
        $this->assertSame(0, PublicationEvent::query()->count());
    }

    public function test_new_cycles_do_not_inherit_verification_or_publication_state(): void
    {
        [$cycle2026, $version2026, $source] = $this->makeVersion();
        $cycle2027 = ScholarshipCycle::factory()->create(['scholarship_id' => $cycle2026->scholarship_id, 'cycle_key' => '2027']);
        [$cycle2027, $version2027] = $this->makeVersion($cycle2027);
        $this->verify($version2026, $source);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/cycles/{$cycle2026->id}/publish", ['version_id' => $version2026->id])->assertCreated();

        $this->assertSame($version2026->id, $cycle2026->fresh()->published_version_id);
        $this->assertNull($cycle2027->fresh()->published_version_id);
        $this->assertSame('pending', $version2027->fresh()->effectiveVerificationStatus());
    }

    private function verify(ScholarshipVersion $version, ScholarshipSource $source): void
    {
        $this->actingAs($this->admin)->postJson("/api/v1/admin/scholarship-versions/{$version->id}/verify", ['source_id' => $source->id])->assertCreated();
    }

    /** @return array{ScholarshipCycle, ScholarshipVersion, ScholarshipSource} */
    private function makeVersion(?ScholarshipCycle $cycle = null, ?ScholarshipSource $source = null, int $versionNumber = 1): array
    {
        if ($cycle === null) {
            $scholarship = Scholarship::factory()->create(['official_url' => 'https://example.edu/scholarships']);
            $cycle = ScholarshipCycle::factory()->create(['scholarship_id' => $scholarship->id, 'cycle_key' => 'cycle-'.$scholarship->id, 'status' => 'open']);
        } else {
            $scholarship = $cycle->scholarship;
        }

        $sourceUrl = 'https://example.edu/scholarships/'.$scholarship->id.'/'.$cycle->cycle_key;
        $source ??= ScholarshipSource::factory()->create(['scholarship_id' => $scholarship->id, 'source_url' => $sourceUrl, 'source_url_hash' => hash('sha256', $sourceUrl)]);
        $sourceSnapshot = ['id' => $source->id, 'source_type' => $source->source_type, 'source_name' => $source->source_name, 'source_url' => $source->source_url, 'is_primary' => true, 'status' => 'active'];
        $version = ScholarshipVersion::query()->create([
            'scholarship_id' => $scholarship->id,
            'cycle_id' => $cycle->id,
            'version_number' => $versionNumber,
            'snapshot' => [
                'scholarship' => ['id' => $scholarship->id, 'official_url' => $scholarship->official_url],
                'cycle' => ['id' => $cycle->id, 'cycle_key' => $cycle->cycle_key, 'application_url' => null, 'status' => $cycle->status],
                'sources' => [$sourceSnapshot],
            ],
            'change_type' => 'test_snapshot',
            'origin_type' => 'human',
            'source_id' => $source->id,
            'created_at' => now(),
        ]);
        $version->verificationRecords()->create(['status' => 'pending', 'actor_id' => $this->admin->id, 'source_id' => $source->id, 'decided_at' => now()]);

        return [$cycle, $version, $source];
    }
}
