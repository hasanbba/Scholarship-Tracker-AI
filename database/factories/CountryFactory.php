<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CountryFactory extends Factory
{
    protected $model = Country::class;

    public function definition(): array
    {
        $name = fake()->unique()->country();

        return ['region_id' => Region::factory(), 'name' => $name, 'normalized_name' => mb_strtolower($name), 'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'), 'iso2' => null, 'iso3' => null, 'status' => 'active'];
    }
}
