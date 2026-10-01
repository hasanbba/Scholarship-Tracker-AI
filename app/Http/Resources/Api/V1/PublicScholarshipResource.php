<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicScholarshipResource extends JsonResource
{
    private const BENEFITS = ['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'];

    public function toArray(Request $request): array
    {
        $cycle = $this->resource;
        $version = $cycle->publishedVersion;
        $snapshot = $version->snapshot;
        $funding = $snapshot['funding'] ?? null;
        $decision = $version->verificationRecords->first();
        $publicFunding = null;

        if ($funding !== null) {
            $publicFunding = ['classification' => $funding['classification'] ?? 'unknown'];
            foreach (self::BENEFITS as $benefit) {
                $publicFunding[$benefit] = [
                    'amount' => $funding[$benefit.'_amount'] ?? null,
                    'currency' => $funding[$benefit.'_currency'] ?? null,
                    'period' => $funding[$benefit.'_period'] ?? null,
                ];
            }
        }

        return [
            'slug' => $snapshot['scholarship']['slug'] ?? null,
            'title' => $snapshot['scholarship']['title'] ?? null,
            'description' => $snapshot['scholarship']['description'] ?? null,
            'official_url' => $snapshot['scholarship']['official_url'] ?? null,
            'university' => $snapshot['university'] ?? null,
            'subjects' => $snapshot['subjects'] ?? [],
            'cycle' => $snapshot['cycle'] ?? [],
            'version_number' => $version->version_number,
            'funding' => $publicFunding,
            'eligibility' => collect($snapshot['eligibility_rules'] ?? [])->map(fn (array $rule): array => [
                'type' => $rule['rule_type'] ?? null,
                'operator' => $rule['operator'] ?? null,
                'value' => $rule['normalized_value'] ?? null,
                'unit' => $rule['unit'] ?? null,
                'text' => $rule['display_text'] ?? null,
                'degree' => $rule['degree']['name'] ?? null,
                'degree_slug' => $rule['degree']['slug'] ?? null,
                'subject' => $rule['subject']['name'] ?? null,
                'subject_slug' => $rule['subject']['slug'] ?? null,
                'country' => $rule['country']['name'] ?? null,
            ])->values()->all(),
            'application_url' => $snapshot['cycle']['application_url'] ?? $snapshot['scholarship']['official_url'] ?? null,
            'verified_at' => $decision?->decided_at?->toISOString(),
            'official_sources' => $cycle->getAttribute('public_official_sources') ?? [],
        ];
    }
}
