<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeadEmail> */
class LeadEmailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'company_id' => fn (array $attributes): int => Lead::query()->whereKey($attributes['lead_id'])->value('company_id'),
            'user_id' => User::factory(),
            'to' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'message' => fake()->paragraph(),
            'status' => LeadEmail::SENT,
            'sent_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(['status' => LeadEmail::FAILED, 'error' => 'Connection could not be established with host "mail.example.test".']);
    }
}
