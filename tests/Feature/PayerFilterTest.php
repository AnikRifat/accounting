<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\Admin\Entries\Index;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

/** "Paid / received by" on the transactions list: recorded on bills and settlements, filterable, exported, and scoped. */
class PayerFilterTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    protected User $owner;

    public function test_payer_is_recorded_filterable_exported_and_scoped(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->owner = User::factory()->create(['role' => 'owner', 'name' => 'Owner Person']);
        $accountant = $this->user('accountant', $mine);
        $outsider = $this->user('accountant', $other);
        $party = Party::factory()->for($mine)->create();
        $ledger = app(LedgerService::class);

        $ownerBill = $this->bill($mine, EntryType::Expense, '5100', 10_000_00, '2026-09-01', ['paid' => 4_000_00, 'party' => $party, 'due' => '2026-09-30']);
        $accountantBill = $ledger->record($mine, EntryType::Income, ['entry_date' => '2026-09-02', 'amount' => 5_000_00,
            'category_account_id' => $this->accountId($mine, '4000'), 'payments' => [['account_id' => $this->accountId($mine, '1000'), 'amount' => 5_000_00]]], $accountant);
        $payment = $ledger->settle($ownerBill, ['entry_date' => '2026-09-03', 'amount' => 1_000_00, 'payment_account_id' => $this->accountId($mine, '1000')], $accountant);
        $ledger->record($other, EntryType::Income, ['entry_date' => '2026-09-02', 'amount' => 1_00,
            'category_account_id' => $this->accountId($other, '4000'), 'payments' => [['account_id' => $this->accountId($other, '1000'), 'amount' => 1_00]]], $outsider);

        $this->assertSame([$this->owner->id, $accountant->id, $accountant->id], [$ownerBill->paid_by, $accountantBill->paid_by, $payment->paid_by]);

        $this->actingAs($accountant);
        Livewire::test(Index::class)
            ->assertViewHas('payers', fn (array $payers): bool => array_keys($payers) === ['', $accountant->id, $this->owner->id] || array_keys($payers) === ['', $this->owner->id, $accountant->id])
            ->set('payer', (string) $accountant->id)
            ->assertViewHas('entries', fn ($entries): bool => $entries->pluck('id')->sort()->values()->all() === collect([$accountantBill->id, $payment->id])->sort()->values()->all());

        $csv = $this->get(route('admin.entries.export', ['payer' => $this->owner->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString($ownerBill->number, $csv);
        $this->assertStringNotContainsString($payment->number, $csv);
        $this->assertStringContainsString('Owner Person', $csv);
    }
}
