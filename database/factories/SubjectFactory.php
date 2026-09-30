<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['name' => $name, 'normalized_name' => mb_strtolower($name), 'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'), 'status' => 'active'];
    }
}
