<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Degree;
use App\Models\Region;
use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipEligibilityRule;
use App\Models\ScholarshipFunding;
use App\Models\ScholarshipSource;
use App\Models\Subject;
use App\Models\University;
use App\Services\Scholarships\CreateScholarshipVersionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevelopmentScholarshipSeeder extends Seeder
{
    public function run(CreateScholarshipVersionService $versions): void
    {
        DB::transaction(function () use ($versions) {
            $region = Region::query()->firstOrCreate(['slug' => 'example-region'], ['name' => 'Example Region', 'status' => 'active']);
            $country = Country::query()->firstOrCreate(['slug' => 'example-country'], ['region_id' => $region->id, 'name' => 'Example Country', 'normalized_name' => 'example country', 'status' => 'active']);
            $university = University::query()->firstOrCreate(['slug' => 'example-university'], ['country_id' => $country->id, 'name' => 'Example University', 'normalized_name' => 'example university', 'official_url' => 'https://example.invalid', 'description' => 'Synthetic development fixture; not a real institution.', 'status' => 'active']);
            $subject = Subject::query()->firstOrCreate(['slug' => 'example-computer-science'], ['name' => 'Example Computer Science', 'normalized_name' => 'example computer science', 'status' => 'active']);
            $degree = Degree::query()->firstOrCreate(['slug' => 'example-masters'], ['name' => 'Example Masters', 'normalized_name' => 'example masters', 'level' => 'master', 'status' => 'active']);
            $scholarship = Scholarship::query()->firstOrCreate(['slug' => 'example-international-graduate-scholarship'], ['university_id' => $university->id, 'title' => 'Example International Graduate Scholarship', 'description' => 'Synthetic development fixture; not a real scholarship.', 'publication_status' => 'draft', 'lifecycle_status' => 'active']);
            $scholarship->subjects()->syncWithoutDetaching([$subject->id]);
            $source = ScholarshipSource::query()->firstOrCreate(['scholarship_id' => $scholarship->id, 'source_url_hash' => hash('sha256', 'https://example.invalid/scholarships/example')], ['source_type' => 'university_page', 'source_name' => 'Example University synthetic source', 'source_url' => 'https://example.invalid/scholarships/example', 'is_primary' => true, 'status' => 'active']);

            foreach ([2026, 2027] as $year) {
                $cycle = ScholarshipCycle::query()->firstOrCreate(['scholarship_id' => $scholarship->id, 'cycle_key' => (string) $year], ['label' => $year.' Example Cycle', 'opening_date' => $year.'-01-01', 'deadline' => $year.'-04-30', 'application_url' => 'https://example.invalid/apply/'.$year, 'status' => 'draft']);
                ScholarshipFunding::query()->firstOrCreate(['cycle_id' => $cycle->id], ['classification' => 'unknown', 'verification_status' => 'unverified', 'notes' => 'Synthetic example: award details are unknown.']);
                ScholarshipEligibilityRule::query()->firstOrCreate(['cycle_id' => $cycle->id, 'rule_type' => 'degree', 'operator' => 'in'], ['normalized_value' => [$degree->name], 'degree_id' => $degree->id, 'display_text' => 'Example eligibility only; replace with verified information.']);
                if (! $cycle->versions()->exists()) {
                    $versions->create($cycle, null, 'development_seed', $source->id);
                }
            }
        });
    }
}
