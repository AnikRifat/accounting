<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw lines bypass LedgerService's balance checks; use only to build deliberately broken data in tests.
 *
 * @extends Factory<JournalLine>
 */
class JournalLineFactory extends Factory
{
    public function definition(): array
    {
        return ['journal_entry_id' => JournalEntry::factory(), 'account_id' => Account::factory(), 'debit' => 0, 'credit' => 0];
    }
}
