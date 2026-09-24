<?php

namespace Tests\Feature\Concerns;

use App\Enums\EntryType;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\User;
use App\Services\LedgerService;

/**
 * Posts entries through LedgerService by account code, as the owner in $this->owner. Used by the report tests.
 *
 * @property User $owner
 */
trait PostsLedgerEntries
{
    protected function user(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    protected function accountId(Company $company, string $code): int
    {
        return (int) $company->accounts()->where('code', $code)->value('id');
    }

    /**
     * An income or expense bill. Extras: paid (default: the total), method (code, default 1000), party (Party),
     * due (Y-m-d), description.
     *
     * @param  array{paid?: int, method?: string, party?: ?Party, due?: string, description?: string}  $extra
     */
    protected function bill(Company $company, EntryType $type, string $category, int $amount, string $date, array $extra = []): JournalEntry
    {
        $paid = $extra['paid'] ?? $amount;

        return app(LedgerService::class)->record($company, $type, [
            'entry_date' => $date, 'amount' => $amount, 'paid_amount' => $paid, 'category_account_id' => $this->accountId($company, $category),
            'payment_account_id' => $paid > 0 ? $this->accountId($company, $extra['method'] ?? '1000') : null,
            'party_id' => ($extra['party'] ?? null)?->id, 'due_date' => $extra['due'] ?? null, 'description' => $extra['description'] ?? null,
        ], $this->owner);
    }

    protected function settle(JournalEntry $bill, int $amount, string $date, string $method = '1000'): JournalEntry
    {
        return app(LedgerService::class)->settle($bill, ['entry_date' => $date, 'amount' => $amount,
            'payment_account_id' => $this->accountId($bill->company, $method)], $this->owner);
    }

    protected function transfer(Company $company, string $to, string $from, int $amount, string $date): JournalEntry
    {
        return app(LedgerService::class)->record($company, EntryType::Transfer, ['entry_date' => $date, 'amount' => $amount,
            'debit_account_id' => $this->accountId($company, $to), 'credit_account_id' => $this->accountId($company, $from)], $this->owner);
    }

    protected function opening(Company $company, string $method, int $amount, string $date): JournalEntry
    {
        return app(LedgerService::class)->recordOpening($company->accounts()->where('code', $method)->sole(), $amount, $date, $this->owner);
    }

    protected function void(JournalEntry $entry, string $reason = 'Mistake'): JournalEntry
    {
        return app(LedgerService::class)->void($entry, $reason, $this->owner);
    }
}
