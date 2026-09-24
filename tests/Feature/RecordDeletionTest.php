<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\DeleteRecordDrawer;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\RecordDeletion;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

class RecordDeletionTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->company = Company::factory()->create(['code' => 'FT']);
    }

    private function account(string $code, ?Company $company = null): Account
    {
        return ($company ?? $this->company)->accounts()->where('code', $code)->sole();
    }

    private function balance(string $code): int
    {
        return app(LedgerService::class)->balance($this->account($code));
    }

    /** Every entry keeps 2–3 one-sided lines on distinct accounts, and the books as a whole balance. */
    private function assertBooksBalance(): void
    {
        $this->assertSame((int) DB::table('journal_lines')->sum('debit'), (int) DB::table('journal_lines')->sum('credit'));
        $broken = DB::table('journal_lines')->groupBy('journal_entry_id')
            ->havingRaw('SUM(debit) <> SUM(credit) OR COUNT(*) < 2 OR COUNT(*) > 3 OR COUNT(DISTINCT account_id) <> COUNT(*)')->count();
        $this->assertSame(0, $broken);
    }

    private function assertRefused(string $field, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected a validation error on [{$field}].");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_category_transfer_moves_every_line_including_trashed_entries(): void
    {
        $rent = $this->bill($this->company, EntryType::Expense, '5100', 100_000, '2026-09-01');
        $trashed = $this->bill($this->company, EntryType::Expense, '5100', 20_000, '2026-09-02');
        $trashed->delete();
        $this->bill($this->company, EntryType::Expense, '5200', 5_000, '2026-09-03');
        $from = $this->account('5100');
        $to = $this->account('5200');
        $accountant = $this->user('accountant', $this->company);
        $this->actingAs($accountant);

        Livewire::test(DeleteRecordDrawer::class)->call('load', 'category', $from->id)->assertSet('open', true)
            ->assertSee(__('Used in :count transactions totalling :total.', ['count' => 2, 'total' => '৳1,200.00']))
            ->assertDontSee(__('Or delete everything'))
            ->set('targetId', (string) $to->id)->call('transfer')->assertHasNoErrors()->assertRedirect();

        $this->assertModelMissing($from);
        $this->assertSame(105_000, $this->balance('5200'));
        $this->assertSame($to->id, $rent->fresh()->categoryAccount()->id);
        $this->assertSame(2, $trashed->lines()->count());
        $this->assertTrue(JournalEntry::withTrashed()->find($trashed->id)->lines()->where('account_id', $to->id)->exists());
        $this->assertBooksBalance();
    }

    public function test_transfer_targets_must_be_active_records_of_the_same_company_and_kind(): void
    {
        $this->bill($this->company, EntryType::Expense, '5100', 1_000, '2026-09-01');
        $other = Company::factory()->create();
        $this->account('5300')->update(['is_active' => false]);
        $service = app(RecordDeletion::class);
        $from = $this->account('5100');

        foreach ([$this->account('4000'), $this->account('5300'), $this->account('5200', $other), $from, $this->account('1000')] as $target) {
            $this->assertRefused('target', fn () => $service->transfer($from, $target, $this->owner));
        }
        $party = Party::factory()->for($this->company)->create();
        $this->assertRefused('target', fn () => $service->transfer($from, $party, $this->owner));
        $this->assertModelExists($from);
    }

    public function test_payment_method_transfer_moves_balances_and_refuses_self_transfers(): void
    {
        $this->opening($this->company, '1020', 50_000, '2026-07-01');
        $this->bill($this->company, EntryType::Income, '4000', 30_000, '2026-09-01', ['method' => '1020']);
        $this->transfer($this->company, '1010', '1000', 1_000, '2026-09-02');
        $service = app(RecordDeletion::class);

        $this->assertRefused('target', fn () => $service->transfer($this->account('1010'), $this->account('1000'), $this->owner));
        $this->assertSame(1_000, $this->balance('1010'));

        $service->transfer($this->account('1020'), $this->account('1010'), $this->owner);
        $this->assertNull($this->company->accounts()->where('code', '1020')->first());
        $this->assertSame(81_000, $this->balance('1010'));
        $this->assertBooksBalance();
    }

    public function test_party_transfer_reassigns_bills_and_their_settlements(): void
    {
        [$from, $to] = Party::factory()->count(2)->for($this->company)->create();
        $bill = $this->bill($this->company, EntryType::Expense, '5100', 10_000, '2026-09-01', ['paid' => 0, 'party' => $from, 'due' => '2026-09-30']);
        $payment = $this->settle($bill, 4_000, '2026-09-05');
        $this->actingAs($this->user('accountant', $this->company));

        Livewire::test(DeleteRecordDrawer::class)->call('load', 'party', $from->id)->set('targetId', (string) $to->id)->call('transfer')->assertHasNoErrors();

        $this->assertModelMissing($from);
        $this->assertSame([$to->id, $to->id], [$bill->fresh()->party_id, $payment->fresh()->party_id]);
        $this->assertSame(6_000, app(LedgerService::class)->outstanding($bill->fresh()));
        $this->assertBooksBalance();
    }

    public function test_hard_delete_purges_related_entries_and_settlements_only(): void
    {
        $bill = $this->bill($this->company, EntryType::Expense, '5100', 10_000, '2026-09-01', ['paid' => 2_000, 'party' => Party::factory()->for($this->company)->create(), 'due' => '2026-09-30']);
        $payment = $this->settle($bill, 3_000, '2026-09-05', '1010');
        $trashed = $this->bill($this->company, EntryType::Expense, '5100', 700, '2026-09-06');
        $trashed->delete();
        $kept = $this->bill($this->company, EntryType::Expense, '5200', 900, '2026-09-07');
        $this->actingAs($this->owner);

        Livewire::test(DeleteRecordDrawer::class)->call('load', 'category', $this->account('5100')->id)
            ->assertSee(trans_choice('Delete permanently with :count transaction|Delete permanently with :count transactions', 2, ['count' => 2]))
            ->call('hardDelete')->assertHasNoErrors()->assertRedirect();

        foreach ([$bill, $payment, $trashed] as $entry) {
            $this->assertNull(JournalEntry::withTrashed()->find($entry->id));
        }
        $this->assertNull($this->company->accounts()->where('code', '5100')->first());
        $this->assertModelExists($kept);
        $this->assertSame(-900, $this->balance('1000'));
        $this->assertSame(0, $this->balance('1010'));
        $this->assertBooksBalance();
    }

    public function test_unused_records_are_deleted_and_used_ones_need_a_transfer_or_hard_delete(): void
    {
        $party = Party::factory()->for($this->company)->create();
        $this->actingAs($this->owner);
        Livewire::test(DeleteRecordDrawer::class)->call('load', 'party', $party->id)->assertSee(__('No transaction uses this record, so it can simply be deleted.'))
            ->call('deleteUnused')->assertHasNoErrors()->assertRedirect();
        $this->assertModelMissing($party);

        $this->bill($this->company, EntryType::Expense, '5100', 1_000, '2026-09-01');
        Livewire::test(DeleteRecordDrawer::class)->call('load', 'category', $this->account('5100')->id)->call('deleteUnused')->assertHasErrors('record');
        $this->assertModelExists($this->account('5100'));
    }

    public function test_system_accounts_employee_parties_and_the_last_active_payment_method_are_blocked(): void
    {
        $service = app(RecordDeletion::class);
        $clerk = $this->user('data-entry', $this->company);
        $clerk->syncParties();
        $employeeParty = $clerk->parties()->sole();
        $this->actingAs($this->owner);

        foreach (['1200', '2000', '3000'] as $code) {
            Livewire::test(DeleteRecordDrawer::class)->call('load', 'account', $this->account($code)->id)
                ->assertSee(__('System accounts are maintained by the application and cannot be deleted.'))->assertDontSee(__('Transfer and delete'));
            $this->assertRefused('record', fn () => $service->deleteUnused($this->account($code), $this->owner));
            $this->assertRefused('record', fn () => $service->hardDelete($this->account($code), $this->owner));
        }
        $this->assertRefused('record', fn () => $service->deleteUnused($employeeParty, $this->owner));
        $this->assertRefused('record', fn () => $service->transfer($employeeParty, Party::factory()->for($this->company)->create(), $this->owner));

        $this->account('1010')->update(['is_active' => false]);
        $this->account('1020')->update(['is_active' => false]);
        $this->assertRefused('record', fn () => $service->deleteUnused($this->account('1000'), $this->owner));
        $service->deleteUnused($this->account('1020'), $this->owner);
        $this->assertModelExists($this->account('1000'));
        $this->assertModelExists($employeeParty);
    }

    public function test_company_delete_needs_the_typed_code_and_erases_only_that_companys_books(): void
    {
        $other = Company::factory()->create();
        $keptEntry = $this->bill($other, EntryType::Income, '4000', 1_000, '2026-09-01');
        $bill = $this->bill($this->company, EntryType::Expense, '5100', 5_000, '2026-09-01', ['paid' => 0, 'party' => Party::factory()->for($this->company)->create(), 'due' => '2026-09-30']);
        $this->settle($bill, 1_000, '2026-09-02');
        $staff = $this->user('accountant', $this->company, $other);
        $staff->syncParties();
        $this->actingAs($this->owner);
        session([CompanyContext::SESSION_KEY => $this->company->id]);

        $drawer = Livewire::test(DeleteRecordDrawer::class)->call('load', 'company', $this->company->id)->set('confirmCode', 'XX')->call('hardDelete')->assertHasErrors('confirmCode');
        $this->assertModelExists($this->company);
        $drawer->set('confirmCode', ' ft ')->call('hardDelete')->assertHasNoErrors()->assertRedirect();

        $this->assertModelMissing($this->company);
        foreach (['journal_entries' => 'company_id', 'accounts' => 'company_id', 'parties' => 'company_id', 'company_user' => 'company_id'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, $this->company->id)->count(), $table);
        }
        $this->assertModelExists($keptEntry);
        $this->assertSame([$other->id], $staff->fresh()->companies()->pluck('companies.id')->all());
        $this->assertNull(session(CompanyContext::SESSION_KEY));
        $this->assertBooksBalance();
    }

    public function test_roles_limit_transfer_hard_delete_and_company_delete(): void
    {
        $this->bill($this->company, EntryType::Expense, '5100', 1_000, '2026-09-01');
        $category = $this->account('5100');
        $accountant = $this->user('accountant', $this->company);
        $this->actingAs($accountant);

        Livewire::test(DeleteRecordDrawer::class)->call('load', 'category', $category->id)->call('hardDelete')->assertForbidden();
        Livewire::test(DeleteRecordDrawer::class)->call('load', 'company', $this->company->id)->assertForbidden();
        try {
            app(RecordDeletion::class)->hardDelete($category, $accountant);
            $this->fail('An accountant must not delete transactions permanently.');
        } catch (AuthorizationException) {
        }
        $this->assertModelExists($category);
        Livewire::test(DeleteRecordDrawer::class)->call('load', 'category', $this->account('5400')->id)->call('hardDelete')->assertForbidden();
        $this->assertModelExists($this->account('5400'));

        $this->actingAs($this->user('data-entry', $this->company));
        foreach (['category' => $category->id, 'party' => Party::factory()->for($this->company)->create()->id, 'payment-method' => $this->account('1010')->id] as $kind => $id) {
            Livewire::test(DeleteRecordDrawer::class)->call('load', $kind, $id)->assertForbidden();
        }
    }

    public function test_company_with_entries_needs_permanent_delete_permission(): void
    {
        RolePermission::factory()->create(['role' => 'company_admin', 'permissions' => ['admin.access', 'companies.view', 'companies.delete']]);
        $this->bill($this->company, EntryType::Expense, '5100', 1_000, '2026-09-01');
        $empty = Company::factory()->create(['code' => 'EMPTY']);
        $admin = $this->user('company_admin', $this->company, $empty);
        $this->actingAs($admin);

        Livewire::test(DeleteRecordDrawer::class)->call('load', 'company', $this->company->id)
            ->assertSee(__('Deleting a company with transactions needs permission to delete transactions permanently.'))
            ->set('confirmCode', 'FT')->call('hardDelete')->assertForbidden();
        $this->assertModelExists($this->company);
        Livewire::test(DeleteRecordDrawer::class)->call('load', 'company', $empty->id)->set('confirmCode', 'EMPTY')->call('hardDelete')->assertHasNoErrors();
        $this->assertModelMissing($empty);
    }

    public function test_records_of_other_companies_and_crafted_kinds_are_not_found(): void
    {
        $other = Company::factory()->create();
        $otherParty = Party::factory()->for($other)->create();
        $this->bill($this->company, EntryType::Expense, '5100', 1_000, '2026-09-01');
        $accountant = $this->user('accountant', $this->company);
        $this->actingAs($accountant);

        foreach ([['category', $this->account('5100', $other)->id], ['party', $otherParty->id], ['payment-method', $this->account('1000', $other)->id],
            ['account', $this->account('5100', $other)->id], ['payment-method', $this->account('5100')->id], ['category', $this->account('1000')->id], ['bogus', $this->account('5100')->id]] as [$kind, $id]) {
            Livewire::test(DeleteRecordDrawer::class)->call('load', $kind, $id)->assertNotFound();
        }

        $drawer = Livewire::test(DeleteRecordDrawer::class)->call('load', 'category', $this->account('5100')->id);
        try {
            $drawer->set('recordId', $this->account('5100', $other)->id);
            $this->fail('The record must not be settable from the client.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        $drawer->set('targetId', (string) $this->account('5200', $other)->id)->call('transfer')->assertHasErrors('targetId');
        $this->assertModelExists($this->account('5100'));
        $this->assertModelExists($this->account('5200', $other));

        $this->expectException(AuthorizationException::class);
        app(RecordDeletion::class)->deleteUnused($otherParty, $accountant);
    }
}
