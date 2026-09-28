<?php

namespace Database\Factories;

use App\Enums\CrmStatusType;
use App\Models\Company;
use App\Models\CrmStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrmStatus> */
class CrmStatusFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'type' => CrmStatusType::Lead, 'name' => fake()->unique()->word(),
            'tone' => 'neutral', 'is_closed' => false, 'position' => 50, 'is_active' => true];
    }

    public function call(): static
    {
        return $this->state(['type' => CrmStatusType::Call]);
    }

    public function closed(): static
    {
        return $this->state(['is_closed' => true]);
    }
}
