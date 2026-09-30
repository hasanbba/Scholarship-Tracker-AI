<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\University;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UniversityFactory extends Factory
{
    protected $model = University::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' University';

        return ['country_id' => Country::factory(), 'name' => $name, 'normalized_name' => mb_strtolower($name), 'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'), 'official_url' => fake()->url(), 'status' => 'active'];
    }
}
