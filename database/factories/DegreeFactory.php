<?php

namespace Database\Factories;

use App\Models\Degree;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DegreeFactory extends Factory
{
    protected $model = Degree::class;

    public function definition(): array
    {
        $name = fake()->unique()->word().' degree';

        return ['name' => $name, 'normalized_name' => mb_strtolower($name), 'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'), 'level' => 'graduate', 'status' => 'active'];
    }
}
