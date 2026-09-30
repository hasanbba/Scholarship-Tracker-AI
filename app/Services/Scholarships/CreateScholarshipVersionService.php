<?php

namespace App\Services\Scholarships;

use App\Models\ScholarshipCycle;
use App\Models\ScholarshipVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateScholarshipVersionService
{
    public function create(ScholarshipCycle $cycle, ?User $actor, string $changeType, ?int $sourceId = null): ScholarshipVersion
    {
        return DB::transaction(function () use ($cycle, $actor, $changeType, $sourceId) {
            $cycle = ScholarshipCycle::query()->with([
                'scholarship.university.country.region',
                'scholarship.subjects',
                'scholarship.sources',
                'funding',
                'eligibilityRules.degree',
                'eligibilityRules.subject',
                'eligibilityRules.country',
            ])->lockForUpdate()->findOrFail($cycle->id);

            $number = ((int) $cycle->versions()->max('version_number')) + 1;
            $fundingSnapshot = $cycle->funding?->getAttributes();
            if ($fundingSnapshot !== null) {
                foreach (['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'] as $benefit) {
                    $key = $benefit.'_amount';
                    if ($fundingSnapshot[$key] !== null) {
                        $fundingSnapshot[$key] = $this->canonicalDecimal((string) $fundingSnapshot[$key]);
                    }
                }
            }

            $snapshot = [
                'scholarship' => $cycle->scholarship->only(['id', 'university_id', 'title', 'slug', 'description', 'official_url', 'publication_status', 'lifecycle_status']),
                'university' => [
                    ...$cycle->scholarship->university->only(['id', 'country_id', 'name', 'slug', 'official_url']),
                    'country' => $cycle->scholarship->university->country->only(['id', 'region_id', 'name', 'normalized_name', 'slug', 'iso2', 'iso3']),
                    'region' => $cycle->scholarship->university->country->region?->only(['id', 'name', 'slug']),
                ],
                'cycle' => $cycle->only(['id', 'cycle_key', 'label', 'opening_date', 'deadline', 'application_url', 'status']),
                'subjects' => $cycle->scholarship->subjects->map(fn ($subject) => $subject->only(['id', 'name', 'slug']))->values()->all(),
                'funding' => $fundingSnapshot,
                'eligibility_rules' => $cycle->eligibilityRules->map(fn ($rule) => [
                    ...$rule->only(['id', 'rule_type', 'operator', 'normalized_value', 'unit', 'display_text', 'degree_id', 'subject_id', 'country_id', 'metadata']),
                    'degree' => $rule->degree?->only(['id', 'name', 'slug']),
                    'subject' => $rule->subject?->only(['id', 'name', 'slug']),
                    'country' => $rule->country?->only(['id', 'name', 'iso2']),
                ])->values()->all(),
                'sources' => $cycle->scholarship->sources->map(fn ($source) => $source->only(['id', 'source_type', 'source_name', 'source_url', 'is_primary', 'status']))->values()->all(),
            ];

            return ScholarshipVersion::query()->create([
                'scholarship_id' => $cycle->scholarship_id,
                'cycle_id' => $cycle->id,
                'version_number' => $number,
                'snapshot' => $snapshot,
                'change_type' => $changeType,
                'origin_type' => $actor === null ? 'system' : 'human',
                'actor_id' => $actor?->id,
                'source_id' => $sourceId,
            ]);
        });
    }

    private function canonicalDecimal(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
