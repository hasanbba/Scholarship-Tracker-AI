<?php

namespace Database\Factories;

use App\Models\Scholarship;
use App\Models\University;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ScholarshipFactory extends Factory
{
    protected $model = Scholarship::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return ['university_id' => University::factory(), 'title' => $title, 'slug' => Str::slug($title).'-'.fake()->unique()->numerify('###'), 'description' => fake()->paragraph(), 'publication_status' => 'draft', 'lifecycle_status' => 'active'];
    }
}
