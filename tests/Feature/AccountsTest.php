<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Livewire\Admin\Accounts\Form;
use App\Livewire\Admin\Accounts\Index;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(Company ...$companies): User
    {
        $user = User::factory()->create(['role' => 'accountant']);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    public function test_index_lists_accounts_of_a_visible_company_with_balances(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $other->accounts()->where('code', '5900')->update(['name' => 'Hidden Expenses']);
        $user = $this->accountant($mine);
        app(LedgerService::class)->recordOpening($mine->accounts()->where('code', '1000')->sole(), 1_25_000_50, '2026-09-01', $user);
        $this->actingAs($user);

        $this->get(route('admin.accounts.index'))->assertOk()->assertSee('Cash in Hand')->assertSee('৳1,25,000.50')->assertDontSee('Hidden Expenses');
        Livewire::test(Index::class)->set('companyId', (string) $other->id)
            ->assertSet('companyId', (string) $mine->id)->assertDontSee('Hidden Expenses');
    }

    public function test_cash_account_can_be_created_with_an_opening_balance(): void
    {
        $company = Company::factory()->create();
        $user = $this->accountant($company);
        $this->actingAs($user);

        Livewire::test(Form::class)->set('companyId', (string) $company->id)->set('code', '1030')->set('name', 'Nagad Wallet')
            ->set('type', 'asset')->set('isCash', true)->set('paymentType', 'mobile_banking')->set('details', '01711-000000')->set('openingBalance', '15,000')->set('openingDate', '2026-07-01')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.accounts.index'));

        $account = $company->accounts()->where('code', '1030')->sole();
        $this->assertSame([true, PaymentType::MobileBanking, '01711-000000'], [$account->is_cash, $account->payment_type, $account->details]);
        $entry = JournalEntry::sole();
        $this->assertSame(EntryType::Opening, $entry->type);
        $this->assertSame('2026-07-01', $entry->entry_date->toDateString());
        $this->assertSame(15_000_00, app(LedgerService::class)->balance($account));
    }

    public function test_codes_and_names_are_unique_per_company_and_cash_needs_an_asset(): void
    {
        [$company, $other] = Company::factory()->count(2)->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        Livewire::test(Form::class)->set('companyId', (string) $company->id)->set('code', '1000')->set('name', 'Cash in Hand')
            ->call('save')->assertHasErrors(['code', 'name']);
        Livewire::test(Form::class)->set('companyId', (string) $other->id)->set('code', '6000')->set('name', 'Marketing')
            ->set('type', 'expense')->set('isCash', true)->call('save')->assertHasNoErrors();
        $this->assertFalse($other->accounts()->where('code', '6000')->sole()->is_cash);
        Livewire::test(Form::class)->set('companyId', (string) $company->id)->set('code', '6000')->set('name', 'Marketing')
            ->set('type', 'expense')->set('openingBalance', '100')->call('save')->assertHasErrors('openingBalance');
    }

    public function test_accounts_of_other_companies_and_system_accounts_are_protected(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->actingAs($this->accountant($mine));

        $this->get(route('admin.accounts.edit', $other->accounts()->where('code', '5100')->sole()))->assertNotFound();
        $this->get(route('admin.accounts.edit', $mine->accounts()->where('code', '3000')->sole()))->assertForbidden();
        Livewire::test(Form::class)->set('companyId', (string) $other->id)->set('code', '6000')->set('name', 'Injected')
            ->call('save')->assertHasErrors('companyId');
        $this->assertDatabaseMissing('accounts', ['name' => 'Injected']);
    }

    public function test_type_of_an_account_with_entries_cannot_change(): void
    {
        $company = Company::factory()->create();
        $user = $this->accountant($company);
        $rent = $company->accounts()->where('code', '5100')->sole();
        app(LedgerService::class)->record($company, EntryType::Expense, ['entry_date' => '2026-09-01', 'amount' => 100, 'paid_amount' => 100,
            'category_account_id' => $rent->id, 'payment_account_id' => $company->accounts()->where('code', '1000')->sole()->id], $user);
        $this->actingAs($user);

        Livewire::test(Form::class, ['account' => $rent])->set('type', 'income')->call('save')->assertHasErrors('type');
        Livewire::test(Form::class, ['account' => $rent])->set('name', 'Office & Warehouse Rent')->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($rent->fresh()->is_active);
        $this->assertSame('Office & Warehouse Rent', $rent->fresh()->name);
    }

    public function test_an_existing_cash_account_gets_one_opening_balance_then_links_to_it(): void
    {
        $company = Company::factory()->create();
        $user = $this->accountant($company);
        $cash = $company->accounts()->where('code', '1000')->sole();
        $this->actingAs($user);

        $this->get(route('admin.accounts.edit', $cash))->assertOk()->assertSee('Opening balance (৳)');
        Livewire::test(Form::class, ['account' => $cash])->set('openingBalance', '50,000')->set('openingDate', '2026-07-01')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.accounts.index'));
        $opening = JournalEntry::sole();
        $this->assertSame(50_000_00, app(LedgerService::class)->balance($cash));

        $this->get(route('admin.accounts.edit', $cash))->assertOk()->assertDontSee('Opening balance (৳)')
            ->assertSee('৳50,000.00')->assertSee(route('admin.entries.edit', $opening));
        Livewire::test(Form::class, ['account' => $cash])->set('openingBalance', '1000')->call('save')->assertHasErrors('openingBalance');
        $this->assertSame(1, JournalEntry::count());

        app(LedgerService::class)->void($opening, 'Wrong amount', $user);
        Livewire::test(Form::class, ['account' => $cash])->set('openingBalance', '45,000')->set('openingDate', '2026-07-01')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(45_000_00, app(LedgerService::class)->balance($cash));
    }

    public function test_padded_codes_and_names_are_trimmed_before_the_unique_check(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->accountant($company));

        Livewire::test(Form::class)->set('companyId', (string) $company->id)->set('code', ' 5100 ')->set('name', ' Office Rent ')
            ->call('save')->assertHasErrors(['code', 'name']);
        Livewire::test(Form::class)->set('companyId', (string) $company->id)->set('code', ' 6100 ')->set('name', ' Marketing ')
            ->call('save')->assertHasNoErrors();
        $this->assertTrue($company->accounts()->where('code', '6100')->where('name', 'Marketing')->exists());
    }

    public function test_a_forged_company_does_not_reveal_whether_its_accounts_exist(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->actingAs($this->accountant($mine));
        $attempt = fn (string $code, string $name) => Livewire::test(Form::class)->set('companyId', (string) $other->id)
            ->set('code', $code)->set('name', $name)->call('save')->errors()->toArray();

        $hit = $attempt('5900', 'Other Expenses');
        $miss = $attempt('7777', 'Nothing Like This');

        $this->assertSame($miss, $hit);
        $this->assertSame(['companyId'], array_keys($hit));
    }

    public function test_accounts_cannot_be_added_to_an_inactive_company(): void
    {
        $active = Company::factory()->create(['name' => 'Active Traders']);
        $inactive = Company::factory()->create(['name' => 'Closed Traders', 'is_active' => false]);
        $this->actingAs($this->accountant($active, $inactive));

        Livewire::test(Form::class)->assertSet('companyId', (string) $active->id)->assertSee('Active Traders')->assertDontSee('Closed Traders')
            ->set('companyId', (string) $inactive->id)->set('code', '6000')->set('name', 'Marketing')->call('save')->assertHasErrors('companyId');
        $this->assertFalse($inactive->accounts()->where('code', '6000')->exists());
        $this->get(route('admin.accounts.index'))->assertOk()->assertSee('Closed Traders');
    }

    public function test_data_entry_can_view_but_not_manage_accounts(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['role' => 'data-entry']);
        $user->companies()->attach($company);
        $this->actingAs($user);

        $this->get(route('admin.accounts.index'))->assertOk()->assertDontSee(route('admin.accounts.create'));
        $this->get(route('admin.accounts.create'))->assertForbidden();
        $this->assertSame(count(LedgerService::DEFAULT_CHART), Account::where('company_id', $company->id)->count());
    }
}
