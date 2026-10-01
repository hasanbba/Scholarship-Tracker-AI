<?php

namespace App\Services\Scholarships;

use App\Models\ScholarshipSource;
use App\Models\ScholarshipVersion;
use App\Models\User;
use App\Models\VerificationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerificationService
{
    public function decide(
        ScholarshipVersion $version,
        User $actor,
        string $status,
        ?int $sourceId = null,
        ?string $notes = null,
    ): VerificationRecord {
        return DB::transaction(function () use ($version, $actor, $status, $sourceId, $notes) {
            $version = ScholarshipVersion::query()->lockForUpdate()->findOrFail($version->id);
            $source = null;

            if ($sourceId !== null) {
                $source = ScholarshipSource::query()
                    ->whereKey($sourceId)
                    ->where('scholarship_id', $version->scholarship_id)
                    ->where('status', 'active')
                    ->first();

                if ($source === null || ! $this->snapshotContainsSource($version, $source->id)) {
                    throw ValidationException::withMessages(['source_id' => 'The evidence source must be active and included in this version snapshot.']);
                }
            }

            return $version->verificationRecords()->create([
                'status' => $status,
                'actor_id' => $actor->id,
                'source_id' => $source?->id,
                'notes' => $notes,
                'decided_at' => now(),
            ]);
        });
    }

    private function snapshotContainsSource(ScholarshipVersion $version, int $sourceId): bool
    {
        return collect($version->snapshot['sources'] ?? [])->contains(
            fn (array $source): bool => (int) ($source['id'] ?? 0) === $sourceId
                && ($source['status'] ?? null) === 'active',
        );
    }
}
