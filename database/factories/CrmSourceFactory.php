<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrmSource> */
class CrmSourceFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->unique()->word(), 'is_active' => true];
    }
}
