<?php

namespace Database\Factories;

use App\Enums\CallType;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeadCall> */
class LeadCallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'company_id' => fn (array $attributes): int => Lead::query()->whereKey($attributes['lead_id'])->value('company_id'),
            'lead_status_id' => fn (array $attributes): int => Lead::query()->whereKey($attributes['lead_id'])->value('crm_status_id'),
            'user_id' => User::factory(),
            'type' => CallType::Call,
            'called_at' => now(),
            'summary' => fake()->sentence(),
        ];
    }
}
