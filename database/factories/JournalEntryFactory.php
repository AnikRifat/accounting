<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates a balanced expense entry against the company's default chart. Application code must
 * record entries through App\Services\LedgerService instead.
 *
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'number' => fn (): string => 'TST-'.fake()->unique()->numerify('######'),
            'entry_date' => now()->toDateString(), 'type' => EntryType::Expense, 'amount' => fake()->numberBetween(100, 1_000_000),
            'description' => fake()->sentence(3), 'reference' => null, 'created_by' => User::factory()];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (JournalEntry $entry): void {
            $accounts = $entry->company->accounts;
            $entry->lines()->createMany([
                ['account_id' => $accounts->firstWhere('type', AccountType::Expense)->id, 'debit' => $entry->amount, 'credit' => 0],
                ['account_id' => $accounts->firstWhere('is_cash', true)->id, 'debit' => 0, 'credit' => $entry->amount],
            ]);
        });
    }
}
