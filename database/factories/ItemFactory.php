<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Item> */
class ItemFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->unique()->words(2, true), 'description' => null, 'unit' => 'pcs',
            'price' => fake()->numberBetween(1, 5000) * 100, 'tax_rate' => 1500, 'account_id' => null, 'is_active' => true];
    }
}
