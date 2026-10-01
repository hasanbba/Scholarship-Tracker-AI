<?php

namespace App\Services\Scholarships;

use App\Models\PublicationEvent;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipSource;
use App\Models\ScholarshipVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicationService
{
    private const OFFICIAL_SOURCE_TYPES = [
        'university_page', 'graduate_school', 'faculty_page', 'official_pdf', 'government', 'official_agency', 'other_official',
    ];

    public function publish(ScholarshipCycle $cycle, ScholarshipVersion $version, User $actor, ?string $reason = null): PublicationEvent
    {
        return DB::transaction(function () use ($cycle, $version, $actor, $reason) {
            $cycle = ScholarshipCycle::query()->with('scholarship')->lockForUpdate()->findOrFail($cycle->id);
            $version = ScholarshipVersion::query()->lockForUpdate()->findOrFail($version->id);

            if ((int) $version->cycle_id !== (int) $cycle->id) {
                throw ValidationException::withMessages(['version_id' => 'The version must belong to this cycle.']);
            }

            if (! in_array($cycle->status, ['open', 'closed', 'expired'], true) || $cycle->scholarship->lifecycle_status === 'archived') {
                throw ValidationException::withMessages(['cycle' => 'Only open, closed, or expired cycles of a non-archived scholarship can be published.']);
            }

            if ($version->effectiveVerificationStatus() !== 'verified') {
                throw ValidationException::withMessages(['version_id' => 'Only a version with an effective verified decision can be published.']);
            }

            $this->assertOfficialSourceAvailable($version);

            if ((int) $cycle->published_version_id === (int) $version->id) {
                throw ValidationException::withMessages(['version_id' => 'This version is already published for the cycle.']);
            }

            $cycle->published_version_id = $version->id;
            $cycle->save();

            return $cycle->publicationEvents()->create([
                'version_id' => $version->id,
                'action' => 'published',
                'actor_id' => $actor->id,
                'reason' => $reason,
            ]);
        });
    }

    public function unpublish(ScholarshipCycle $cycle, User $actor, ?string $reason = null): PublicationEvent
    {
        return DB::transaction(function () use ($cycle, $actor, $reason) {
            $cycle = ScholarshipCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            $versionId = $cycle->published_version_id;

            if ($versionId === null) {
                throw ValidationException::withMessages(['cycle' => 'This cycle has no currently published version.']);
            }

            $cycle->published_version_id = null;
            $cycle->save();

            return $cycle->publicationEvents()->create([
                'version_id' => $versionId,
                'action' => 'unpublished',
                'actor_id' => $actor->id,
                'reason' => $reason,
            ]);
        });
    }

    private function assertOfficialSourceAvailable(ScholarshipVersion $version): void
    {
        $sourceIds = collect($version->snapshot['sources'] ?? [])
            ->filter(fn (array $source): bool => ($source['status'] ?? null) === 'active' && in_array($source['source_type'] ?? null, self::OFFICIAL_SOURCE_TYPES, true))
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id);

        $hasCurrentOfficialSource = $sourceIds->isNotEmpty() && ScholarshipSource::query()
            ->where('scholarship_id', $version->scholarship_id)
            ->where('status', 'active')
            ->whereIn('source_type', self::OFFICIAL_SOURCE_TYPES)
            ->whereIn('id', $sourceIds)
            ->exists();

        if (! $hasCurrentOfficialSource) {
            throw ValidationException::withMessages(['version_id' => 'The version must retain at least one active official source.']);
        }

        // The application URL is optional under Phase 0; if one is recorded, it is part of the immutable snapshot.
        $snapshot = $version->snapshot;
        $applicationUrl = $snapshot['cycle']['application_url'] ?? $snapshot['scholarship']['official_url'] ?? null;
        if ($applicationUrl !== null && ! filter_var($applicationUrl, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(['version_id' => 'The snapshot application URL is invalid.']);
        }
    }
}
