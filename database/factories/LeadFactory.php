<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Support\Crm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('017########'),
            // The company's first lead status (every company gets the defaults on creation).
            'crm_status_id' => fn (array $attributes): int => Crm::defaultLeadStatusId((int) $attributes['company_id'])
                ?? CrmStatus::factory()->create(['company_id' => $attributes['company_id']])->id,
        ];
    }
}
