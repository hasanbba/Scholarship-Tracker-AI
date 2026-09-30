<?php

namespace Database\Factories;

use App\Models\ScholarshipCycle;
use App\Models\ScholarshipEligibilityRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipEligibilityRuleFactory extends Factory
{
    protected $model = ScholarshipEligibilityRule::class;

    public function definition(): array
    {
        return ['cycle_id' => ScholarshipCycle::factory(), 'rule_type' => 'gpa', 'operator' => '>=', 'normalized_value' => 3, 'unit' => '4.0 scale', 'display_text' => 'Minimum GPA 3.0'];
    }
}
