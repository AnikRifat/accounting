<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DueStatus;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function account(Company $company, string $code): Account
    {
        return $company->accounts()->where('code', $code)->sole();
    }

    private function balance(Company $company, string $code): int
    {
        return $this->ledger->balance($this->account($company, $code));
    }

    /** Records an income (category 4000, method 1000) or expense (category 5100, method 1000) bill. */
    private function bill(Company $company, EntryType $type, int $total, ?int $paid = null, array $extra = []): JournalEntry
    {
        return $this->ledger->record($company, $type, $extra + [
            'entry_date' => '2026-09-01', 'amount' => $total, 'paid_amount' => $paid ?? $total,
            'category_account_id' => $this->account($company, $type === EntryType::Income ? '4000' : '5100')->id,
            'payment_account_id' => $this->account($company, '1000')->id,
        ], $this->owner);
    }

    private function transfer(Company $company, string $to, string $from, int $amount, array $extra = []): JournalEntry
    {
        return $this->ledger->record($company, EntryType::Transfer, $extra + ['entry_date' => '2026-09-01', 'amount' => $amount,
            'debit_account_id' => $this->account($company, $to)->id, 'credit_account_id' => $this->account($company, $from)->id], $this->owner);
    }

    private function settle(JournalEntry $bill, int $amount, string $date = '2026-09-10', string $method = '1010'): JournalEntry
    {
        return $this->ledger->settle($bill, ['entry_date' => $date, 'amount' => $amount,
            'payment_account_id' => $this->account($bill->company, $method)->id], $this->owner);
    }

    private function outstanding(JournalEntry $bill): int
    {
        return JournalEntry::query()->withOutstanding()->findOrFail($bill->id)->outstanding;
    }

    /** @return array<string, array{debit: int, credit: int}> lines keyed by account code */
    private function lines(JournalEntry $entry): array
    {
        return $entry->lines()->with('account')->get()->mapWithKeys(fn ($line): array => [$line->account->code => ['debit' => $line->debit, 'credit' => $line->credit]])->all();
    }

    private function assertValidationError(string $field, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected a validation error on [{$field}].");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_every_new_company_gets_the_default_chart(): void
    {
        $company = Company::factory()->create();
        $accounts = $company->accounts()->orderBy('code')->get()->keyBy('code');

        $this->assertSame(['1000', '1010', '1020', '1200', '2000', '3000', '4000', '4900', '5000', '5100', '5200', '5300', '5400', '5900'], $accounts->pluck('code')->values()->all());
        $this->assertSame([PaymentType::Cash, PaymentType::Bank, PaymentType::MobileBanking], [$accounts['1000']->payment_type, $accounts['1010']->payment_type, $accounts['1020']->payment_type]);
        $this->assertSame(['1000', '1010', '1020'], $company->accounts()->paymentMethods()->orderBy('code')->pluck('code')->all());
        $this->assertSame(['1200', '2000', '3000'], $accounts->where('is_system', true)->pluck('code')->values()->all());
        $this->assertSame([AccountType::Asset, AccountType::Liability], [$accounts['1200']->type, $accounts['2000']->type]);
        $this->assertNull($accounts['4000']->payment_type);
    }

    public function test_next_code_is_the_next_free_number_in_each_range(): void
    {
        $company = Company::factory()->create();

        $this->assertSame('4901', $this->ledger->nextCode($company, AccountType::Income));
        $this->assertSame('5901', $this->ledger->nextCode($company, AccountType::Expense));
        $this->assertSame('1021', $this->ledger->nextCode($company, LedgerService::PAYMENT_METHOD));
        Account::factory()->for($company)->create(['code' => '5999', 'type' => AccountType::Expense]);
        $this->assertSame('5001', $this->ledger->nextCode($company, AccountType::Expense));
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->nextCode($company, AccountType::Asset);
    }

    public function test_bills_post_two_or_three_balanced_lines_by_amount_paid(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create();
        $due = ['party_id' => $party->id, 'due_date' => '2026-09-30'];

        $this->assertSame(['4000' => ['debit' => 0, 'credit' => 1000], '1000' => ['debit' => 1000, 'credit' => 0]],
            $this->lines($this->bill($company, EntryType::Income, 1000)));
        $this->assertSame(['4000' => ['debit' => 0, 'credit' => 1000], '1000' => ['debit' => 400, 'credit' => 0], '1200' => ['debit' => 600, 'credit' => 0]],
            $this->lines($this->bill($company, EntryType::Income, 1000, 400, $due)));
        $this->assertSame(['4000' => ['debit' => 0, 'credit' => 1000], '1200' => ['debit' => 1000, 'credit' => 0]],
            $this->lines($this->bill($company, EntryType::Income, 1000, 0, $due)));
        $this->assertSame(['5100' => ['debit' => 800, 'credit' => 0], '1000' => ['debit' => 0, 'credit' => 300], '2000' => ['debit' => 0, 'credit' => 500]],
            $this->lines($expense = $this->bill($company, EntryType::Expense, 800, 300, $due)));
        $this->assertSame(['2000' => ['debit' => 500, 'credit' => 0], '1010' => ['debit' => 0, 'credit' => 500]], $this->lines($this->settle($expense, 500)));
        $this->assertSame(['1010' => ['debit' => 700, 'credit' => 0], '1000' => ['debit' => 0, 'credit' => 700]], $this->lines($this->transfer($company, '1010', '1000', 700)));
        $opening = $this->ledger->recordOpening($this->account($company, '1020'), 900, '2026-08-31', $this->owner);
        $this->assertSame(['1020' => ['debit' => 900, 'credit' => 0], '3000' => ['debit' => 0, 'credit' => 900]], $this->lines($opening));

        $this->assertSame(3_000, $this->balance($company, '4000'));
        $this->assertSame(1_600, $this->balance($company, '1200'));
        $this->assertSame(0, $this->balance($company, '2000'));
        $this->assertSame(1_000 + 400 - 300 - 700, $this->balance($company, '1000'));
        $this->assertSame($due['party_id'], JournalEntry::where('type', EntryType::Payment)->sole()->party_id);
        $this->assertNull(JournalEntry::where('type', EntryType::Income)->orderBy('id')->first()->due_date);
    }

    public function test_balances_use_each_account_types_normal_side_and_an_optional_date(): void
    {
        $company = Company::factory()->create();
        $this->ledger->recordOpening($this->account($company, '1010'), 50_000_00, '2026-08-31', $this->owner);
        $this->bill($company, EntryType::Income, 10_000_00);
        $this->bill($company, EntryType::Expense, 3_000_00, null, ['entry_date' => '2026-09-02']);
        $this->transfer($company, '1000', '1010', 5_000_00, ['entry_date' => '2026-09-02']);

        $this->assertSame(12_000_00, $this->balance($company, '1000'));
        $this->assertSame(45_000_00, $this->balance($company, '1010'));
        $this->assertSame(10_000_00, $this->balance($company, '4000'));
        $this->assertSame(3_000_00, $this->balance($company, '5100'));
        $this->assertSame(50_000_00, $this->balance($company, '3000'));
        $this->assertSame(10_000_00, $this->ledger->balance($this->account($company, '1000'), Carbon::parse('2026-09-01')));
        $this->assertSame(0, $this->ledger->balance($this->account($company, '4000'), Carbon::parse('2026-08-31')));
    }

    public function test_numbers_are_sequential_within_each_company(): void
    {
        $first = Company::factory()->create(['code' => 'FRA']);
        $second = Company::factory()->create(['code' => 'FRB']);

        $this->assertSame('FRA-000001', $this->bill($first, EntryType::Income, 100)->number);
        $this->assertSame('FRB-000001', $this->bill($second, EntryType::Income, 100)->number);
        $this->assertSame('FRA-000002', $this->bill($first, EntryType::Expense, 100)->number);
    }

    public function test_bill_validation_rejects_wrong_accounts_parties_and_missing_due_details(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $party = Party::factory()->for($company)->create();
        $foreignParty = Party::factory()->for($other)->create();
        $inactiveMethod = Account::factory()->cash()->inactive()->for($company)->create();
        $due = ['party_id' => $party->id, 'due_date' => '2026-09-30'];

        $this->assertValidationError('category_account_id', fn () => $this->bill($company, EntryType::Income, 100, null, ['category_account_id' => $this->account($company, '5100')->id]));
        $this->assertValidationError('category_account_id', fn () => $this->bill($company, EntryType::Income, 100, null, ['category_account_id' => $this->account($other, '4000')->id]));
        $this->assertValidationError('category_account_id', fn () => $this->bill($company, EntryType::Expense, 100, null, ['category_account_id' => $this->account($company, '2000')->id]));
        $this->assertValidationError('payment_account_id', fn () => $this->bill($company, EntryType::Income, 100, null, ['payment_account_id' => $this->account($company, '4900')->id]));
        $this->assertValidationError('payment_account_id', fn () => $this->bill($company, EntryType::Income, 100, null, ['payment_account_id' => $this->account($other, '1000')->id]));
        $this->assertValidationError('payment_account_id', fn () => $this->bill($company, EntryType::Income, 100, null, ['payment_account_id' => $inactiveMethod->id]));
        $this->assertValidationError('payment_account_id', fn () => $this->bill($company, EntryType::Income, 100, null, ['payment_account_id' => null]));
        $this->assertValidationError('paid_amount', fn () => $this->bill($company, EntryType::Income, 100, 101));
        $this->assertValidationError('amount', fn () => $this->bill($company, EntryType::Income, 0, 0));
        $this->assertValidationError('party_id', fn () => $this->bill($company, EntryType::Income, 100, 40, ['due_date' => '2026-09-30']));
        $this->assertValidationError('party_id', fn () => $this->bill($company, EntryType::Income, 100, null, ['party_id' => $foreignParty->id]));
        $this->assertValidationError('due_date', fn () => $this->bill($company, EntryType::Income, 100, 40, ['party_id' => $party->id]));
        $this->assertValidationError('due_date', fn () => $this->bill($company, EntryType::Income, 100, 40, ['due_date' => '2026-08-31'] + $due));
        $this->assertValidationError('credit_account_id', fn () => $this->transfer($company, '1000', '1000', 100));
        $this->assertValidationError('credit_account_id', fn () => $this->transfer($company, '1000', '4000', 100));
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_partial_settlements_move_a_bill_from_due_to_paid(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create();
        $bill = $this->bill($company, EntryType::Income, 1_000_00, 0, ['party_id' => $party->id, 'due_date' => today()->addDays(10)->toDateString(), 'entry_date' => today()->toDateString()]);
        $status = fn (): DueStatus => JournalEntry::query()->withOutstanding()->findOrFail($bill->id)->dueStatus();

        $this->assertSame(DueStatus::Due, $status());
        $this->assertValidationError('amount', fn () => $this->settle($bill, 1_000_01, today()->toDateString()));
        $this->assertValidationError('entry_date', fn () => $this->settle($bill, 100, today()->subDay()->toDateString()));
        $receipt = $this->settle($bill, 400_00, today()->toDateString());
        $this->assertSame([EntryType::Receipt, $party->id, $bill->id], [$receipt->type, $receipt->party_id, $receipt->bill_id]);
        $this->assertSame(600_00, $this->outstanding($bill));
        $this->assertSame(DueStatus::PartlyPaid, $status());
        $this->assertSame([$bill->id], JournalEntry::query()->dueStatus(DueStatus::PartlyPaid)->pluck('id')->all());
        $this->settle($bill, 600_00, today()->toDateString());
        $this->assertSame(0, $this->outstanding($bill));
        $this->assertSame(DueStatus::Paid, $status());
        $this->assertSame(0, $this->balance($company, '1200'));
        $this->assertValidationError('amount', fn () => $this->settle($bill, 1, today()->toDateString()));
        $this->assertCount(0, $this->ledger->dues([$company->id]));
    }

    public function test_an_unpaid_bill_past_its_due_date_is_overdue_and_listed_in_dues(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create();
        $overdue = $this->bill($company, EntryType::Expense, 500_00, 100_00, ['party_id' => $party->id, 'entry_date' => '2026-08-01', 'due_date' => '2026-08-15']);
        $later = $this->bill($company, EntryType::Income, 300_00, 0, ['party_id' => $party->id, 'entry_date' => '2026-09-01', 'due_date' => today()->addMonth()->toDateString()]);
        $this->bill($company, EntryType::Income, 50_00);
        $this->travelTo(Carbon::parse('2026-09-24 10:00', 'Asia/Dhaka'));

        $this->assertSame(DueStatus::Overdue, JournalEntry::query()->withOutstanding()->findOrFail($overdue->id)->dueStatus());
        $this->assertSame([$overdue->id], JournalEntry::query()->dueStatus(DueStatus::Overdue)->pluck('id')->all());
        $this->assertSame([$later->id], JournalEntry::query()->dueStatus(DueStatus::Due)->pluck('id')->all());
        $dues = $this->ledger->dues([$company->id]);
        $this->assertSame([$overdue->id, $later->id], $dues->modelKeys());
        $this->assertSame([400_00, 300_00], $dues->pluck('outstanding')->all());
        $this->assertSame([$later->id], $this->ledger->dues([$company->id], EntryType::Income)->modelKeys());
        $this->assertCount(0, $this->ledger->dues([Company::factory()->create()->id]));
    }

    public function test_bill_edits_respect_settled_amounts_and_record_the_editor(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create();
        $otherParty = Party::factory()->for($company)->create();
        $bill = $this->bill($company, EntryType::Expense, 1_000_00, 0, ['party_id' => $party->id, 'due_date' => '2026-09-30']);
        $this->settle($bill, 600_00);
        $editor = User::factory()->create(['role' => 'accountant']);
        $editor->companies()->attach($company);
        $data = ['entry_date' => '2026-09-01', 'amount' => 1_000_00, 'paid_amount' => 0, 'category_account_id' => $this->account($company, '5200')->id,
            'payment_account_id' => $this->account($company, '1000')->id, 'party_id' => $party->id, 'due_date' => '2026-10-15'];

        $this->assertValidationError('amount', fn () => $this->ledger->update($bill, ['amount' => 500_00] + $data, $editor));
        $this->assertValidationError('amount', fn () => $this->ledger->update($bill, ['paid_amount' => 500_00] + $data, $editor));
        $this->assertValidationError('party_id', fn () => $this->ledger->update($bill, ['party_id' => $otherParty->id] + $data, $editor));
        $this->assertValidationError('entry_date', fn () => $this->ledger->update($bill, ['entry_date' => '2026-09-11', 'due_date' => '2026-10-15'] + $data, $editor));
        $updated = $this->ledger->update($bill, ['amount' => 1_200_00, 'paid_amount' => 200_00] + $data, $editor);

        $this->assertSame([$bill->number, $editor->id, '2026-10-15'], [$updated->number, $updated->updated_by, $updated->fresh()->due_date->toDateString()]);
        $this->assertSame(400_00, $this->outstanding($bill));
        $this->assertSame(1_200_00, $this->balance($company, '5200'));
        $this->assertSame(0, $this->balance($company, '5100'));
        $this->assertSame(400_00, $this->balance($company, '2000'));
    }

    public function test_settlement_edits_stay_within_the_outstanding_amount(): void
    {
        $company = Company::factory()->create();
        $bill = $this->bill($company, EntryType::Income, 1_000_00, 0, ['party_id' => Party::factory()->for($company)->create()->id, 'due_date' => '2026-09-30']);
        $first = $this->settle($bill, 300_00);
        $this->settle($bill, 500_00);
        $data = ['entry_date' => '2026-09-12', 'payment_account_id' => $this->account($company, '1020')->id];

        $this->assertValidationError('amount', fn () => $this->ledger->update($first, ['amount' => 500_01] + $data, $this->owner));
        $this->ledger->update($first, ['amount' => 500_00] + $data, $this->owner);

        $this->assertSame(0, $this->outstanding($bill));
        $this->assertSame(500_00, $this->balance($company, '1020'));
        $this->assertSame($bill->party_id, $first->fresh()->party_id);
    }

    public function test_an_account_deactivated_after_posting_stays_valid_for_its_own_entries(): void
    {
        $company = Company::factory()->create();
        $entry = $this->bill($company, EntryType::Expense, 1_000_00);
        // Both the first (category) and second (payment method) line accounts must stay valid.
        $this->account($company, '5100')->update(['is_active' => false]);
        $this->account($company, '1000')->update(['is_active' => false]);

        $this->ledger->update($entry, ['entry_date' => '2026-09-01', 'amount' => 900_00, 'paid_amount' => 900_00,
            'category_account_id' => $this->account($company, '5100')->id, 'payment_account_id' => $this->account($company, '1000')->id], $this->owner);

        $this->assertSame(900_00, $this->balance($company, '5100'));
    }

    public function test_voiding_rules_for_bills_and_settlements(): void
    {
        $company = Company::factory()->create();
        $bill = $this->bill($company, EntryType::Income, 1_000_00, 0, ['party_id' => Party::factory()->for($company)->create()->id, 'due_date' => '2026-09-30']);
        $receipt = $this->settle($bill, 400_00);

        $this->assertValidationError('reason', fn () => $this->ledger->void($receipt, '   ', $this->owner));
        $this->assertValidationError('reason', fn () => $this->ledger->void($bill, 'Wrong customer', $this->owner));
        $voided = $this->ledger->void($receipt, 'Cheque bounced', $this->owner);

        $this->assertSame([$this->owner->id, 'Cheque bounced'], [$voided->voided_by, $voided->void_reason]);
        $this->assertSame(1_000_00, $this->outstanding($bill));
        $this->assertSame(0, $this->balance($company, '1010'));
        $this->ledger->void($bill, 'Wrong customer', $this->owner);
        $this->assertSame(0, $this->balance($company, '1200'));
        $this->assertSame(0, JournalEntry::posted()->count());
        $this->assertValidationError('entry', fn () => $this->settle($bill, 100));
        $this->assertValidationError('entry', fn () => $this->ledger->update($bill, [], $this->owner));
    }

    public function test_an_inactive_company_rejects_new_entries_but_existing_ones_can_be_corrected(): void
    {
        $company = Company::factory()->create();
        $bill = $this->bill($company, EntryType::Income, 1_000_00, 0, ['party_id' => Party::factory()->for($company)->create()->id, 'due_date' => '2026-09-30']);
        $company->update(['is_active' => false]);

        $this->assertValidationError('company_id', fn () => $this->bill($company, EntryType::Income, 100));
        $this->assertValidationError('company_id', fn () => $this->settle($bill, 100));
        $this->ledger->void($bill, 'Company closed', $this->owner);
        $this->assertTrue($bill->fresh()->isVoided());
    }

    public function test_writes_require_access_to_the_company_and_the_ability(): void
    {
        [$assigned, $other] = Company::factory()->count(2)->create();
        $dataEntry = User::factory()->create(['role' => 'data-entry']);
        $dataEntry->companies()->attach($assigned);
        $data = fn (Company $company): array => ['entry_date' => '2026-09-01', 'amount' => 100, 'paid_amount' => 0, 'due_date' => '2026-09-30',
            'party_id' => Party::factory()->for($company)->create()->id, 'category_account_id' => $this->account($company, '4000')->id];
        $settlement = ['entry_date' => '2026-09-02', 'amount' => 50, 'payment_account_id' => $this->account($assigned, '1000')->id];

        $entry = $this->ledger->record($assigned, EntryType::Income, $data($assigned), $dataEntry);
        $this->ledger->settle($entry, $settlement, $dataEntry);
        $foreign = $this->bill($other, EntryType::Income, 100, 0, ['party_id' => Party::factory()->for($other)->create()->id, 'due_date' => '2026-09-30']);
        foreach ([
            fn () => $this->ledger->record($other, EntryType::Income, $data($other), $dataEntry),
            fn () => $this->ledger->settle($foreign, $settlement, $dataEntry),
            fn () => $this->ledger->update($entry, $data($assigned), $dataEntry),
            fn () => $this->ledger->void($entry, 'Mistake', $dataEntry),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An unauthorised ledger write was accepted.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(3, JournalEntry::count());
    }
}
