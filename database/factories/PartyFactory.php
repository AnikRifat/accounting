<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Party> */
class PartyFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->company(), 'phone' => fake()->numerify('017########'),
            'address' => null, 'notes' => null, 'is_active' => true];
    }
}
