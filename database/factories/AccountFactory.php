<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'code' => fake()->unique()->numerify('6###'), 'name' => fake()->unique()->words(3, true),
            'type' => AccountType::Expense, 'is_cash' => false, 'payment_type' => null, 'details' => null, 'is_system' => false, 'is_active' => true];
    }

    public function cash(): static
    {
        return $this->state(['type' => AccountType::Asset, 'is_cash' => true, 'payment_type' => PaymentType::Cash]);
    }

    public function income(): static
    {
        return $this->state(['type' => AccountType::Income, 'code' => fake()->unique()->numerify('46##')]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
