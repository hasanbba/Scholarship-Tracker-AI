<?php

namespace Database\Factories;

use App\Models\ScholarshipCycle;
use App\Models\ScholarshipFunding;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipFundingFactory extends Factory
{
    protected $model = ScholarshipFunding::class;

    public function definition(): array
    {
        return ['cycle_id' => ScholarshipCycle::factory(), 'classification' => 'unknown', 'verification_status' => 'unverified'];
    }
}
