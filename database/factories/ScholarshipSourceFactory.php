<?php

namespace Database\Factories;

use App\Models\Scholarship;
use App\Models\ScholarshipSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipSourceFactory extends Factory
{
    protected $model = ScholarshipSource::class;

    public function definition(): array
    {
        return ['scholarship_id' => Scholarship::factory(), 'source_type' => 'university_page', 'source_name' => 'Example University official page', 'source_url' => fake()->unique()->url(), 'is_primary' => true, 'status' => 'active'];
    }
}
