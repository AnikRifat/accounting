<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->company(), 'code' => fake()->unique()->bothify('??##'),
            'address' => null, 'phone' => null, 'is_active' => true];
    }
}
