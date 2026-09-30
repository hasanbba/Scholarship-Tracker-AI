<?php

namespace Database\Factories;

use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipCycleFactory extends Factory
{
    protected $model = ScholarshipCycle::class;

    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2026, 2099);

        return ['scholarship_id' => Scholarship::factory(), 'cycle_key' => (string) $year, 'label' => $year.' Cycle', 'opening_date' => $year.'-01-01', 'deadline' => $year.'-06-30', 'status' => 'draft'];
    }
}
