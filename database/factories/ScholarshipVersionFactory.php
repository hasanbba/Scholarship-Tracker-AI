<?php

namespace Database\Factories;

use App\Models\ScholarshipCycle;
use App\Models\ScholarshipVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipVersionFactory extends Factory
{
    protected $model = ScholarshipVersion::class;

    public function definition(): array
    {
        $cycle = ScholarshipCycle::factory()->create();

        return ['scholarship_id' => $cycle->scholarship_id, 'cycle_id' => $cycle->id, 'version_number' => 1, 'snapshot' => ['cycle' => ['cycle_key' => $cycle->cycle_key], 'funding' => null, 'eligibility_rules' => []], 'change_type' => 'test_snapshot', 'origin_type' => 'human'];
    }
}
