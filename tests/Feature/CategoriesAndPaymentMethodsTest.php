<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\Categories\Index as CategoryIndex;
use App\Livewire\Admin\PaymentMethods\Form as PaymentMethodForm;
use App\Livewire\Admin\PaymentMethods\Index as PaymentMethodIndex;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoriesAndPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    public function test_categories_get_an_automatic_code_in_their_range(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $company));
        Livewire::test(CategoryForm::class)->set('name', ' Consulting Fees ')->set('type', 'income')->call('save')->assertHasNoErrors()
            ->assertRedirect(route('admin.categories.index'));
        Livewire::test(CategoryForm::class)->set('name', 'Internet Bill')->set('type', 'expense')->call('save')->assertHasNoErrors();

        $income = Account::where('name', 'Consulting Fees')->sole();
        $expense = Account::where('name', 'Internet Bill')->sole();
        $this->assertSame([$company->id, AccountType::Income, false, false], [$income->company_id, $income->type, $income->is_cash, $income->is_system]);
        $this->assertTrue((int) $income->code >= 4000 && (int) $income->code <= 4999);
        $this->assertTrue((int) $expense->code >= 5000 && (int) $expense->code <= 5999);
        $this->get('/admin/categories')->assertOk()->assertSeeInOrder([__('Income categories'), 'Consulting Fees', __('Expense categories'), 'Internet Bill']);
    }

    public function test_category_names_are_unique_per_company(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs($this->userFor('accountant', $first, $second));
        Livewire::test(CategoryForm::class)->set('companyId', (string) $first->id)->set('name', 'Office Rent')->call('save')->assertHasErrors(['name' => 'unique']);
        Livewire::test(CategoryForm::class)->set('companyId', (string) $second->id)->set('name', 'Brand New')->call('save')->assertHasNoErrors();
        Livewire::test(CategoryForm::class)->set('companyId', (string) $first->id)->set('name', 'Brand New')->call('save')->assertHasNoErrors();
        $this->assertSame(2, Account::where('name', 'Brand New')->count());
    }

    public function test_category_type_is_locked_once_it_has_entries(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $company));
        $category = $company->accounts()->where('name', 'Office Rent')->sole();
        $unused = $company->accounts()->where('name', 'Utilities')->sole();

        Livewire::test(CategoryForm::class, ['category' => $unused])->set('type', 'income')->call('save')->assertHasNoErrors();
        $unused->refresh();
        $this->assertSame(AccountType::Income, $unused->type);
        $this->assertTrue((int) $unused->code >= 4000 && (int) $unused->code <= 4999);

        JournalLine::factory()->create(['journal_entry_id' => JournalEntry::factory()->for($company), 'account_id' => $category->id, 'debit' => 100]);
        Livewire::test(CategoryForm::class, ['category' => $category])->assertSet('hasEntries', true)->set('type', 'income')->set('isActive', false)
            ->call('save')->assertHasErrors('type');
        $this->assertSame([AccountType::Expense, true], [$category->fresh()->type, $category->fresh()->is_active]);
    }

    public function test_category_screen_rejects_hidden_companies_and_non_category_accounts(): void
    {
        [$assigned, $hidden] = Company::factory()->count(2)->create();
        $this->actingAs($this->userFor('accountant', $assigned));
        $existingName = $hidden->accounts()->where('name', 'Office Rent')->sole()->name;

        $hit = Livewire::test(CategoryForm::class)->set('companyId', (string) $hidden->id)->set('name', $existingName)->call('save');
        $miss = Livewire::test(CategoryForm::class)->set('companyId', (string) $hidden->id)->set('name', 'Nobody Uses This')->call('save');
        $this->assertSame(['companyId'], array_keys($hit->errors()->toArray()));
        $this->assertSame($miss->errors()->toArray(), $hit->errors()->toArray());

        $this->get('/admin/categories/'.$hidden->accounts()->where('name', 'Office Rent')->sole()->id.'/edit')->assertNotFound();
        foreach (['Cash in Hand', 'Opening Balance Equity'] as $name) {
            $this->get('/admin/categories/'.$assigned->accounts()->where('name', $name)->sole()->id.'/edit')->assertNotFound();
        }
        Livewire::test(CategoryIndex::class)->set('companyId', (string) $hidden->id)->assertSet('companyId', (string) $assigned->id);
    }

    public function test_payment_method_is_created_with_automatic_code_and_opening_balance(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $company));
        Livewire::test(PaymentMethodForm::class)->set('name', ' City Bank ')->set('paymentType', 'bank')->set('details', ' 0123-456 ')
            ->set('openingBalance', '50,000')->set('openingDate', '2026-07-01')->call('save')->assertHasNoErrors()
            ->assertRedirect(route('admin.payment-methods.index'));

        $method = Account::where('name', 'City Bank')->sole();
        $this->assertSame([AccountType::Asset, true, PaymentType::Bank, '0123-456'], [$method->type, $method->is_cash, $method->payment_type, $method->details]);
        $this->assertTrue((int) $method->code >= 1000 && (int) $method->code <= 1199);
        $this->assertSame(5_000_000, app(LedgerService::class)->balance($method));
        $this->assertSame(1, JournalEntry::where('type', EntryType::Opening)->count());
        $this->get('/admin/payment-methods')->assertOk()->assertSee('City Bank')->assertSee('৳50,000.00');

        Livewire::test(PaymentMethodForm::class, ['paymentMethod' => $method])->set('openingBalance', '100')->call('save')->assertHasErrors('openingBalance');
        $this->assertSame(1, JournalEntry::where('type', EntryType::Opening)->count());
    }

    public function test_opening_balance_can_be_added_on_edit_and_payment_type_can_change(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $company));
        $cash = $company->accounts()->where('name', 'Cash in Hand')->sole();

        Livewire::test(PaymentMethodForm::class, ['paymentMethod' => $cash])->assertSet('paymentType', 'cash')->set('paymentType', 'other')
            ->set('openingBalance', '1,250.50')->set('openingDate', '2026-07-01')->call('save')->assertHasNoErrors();
        $cash->refresh();
        $this->assertSame([PaymentType::Other, true, AccountType::Asset], [$cash->payment_type, $cash->is_cash, $cash->type]);
        $this->assertSame(125_050, app(LedgerService::class)->balance($cash));
    }

    public function test_payment_method_names_are_unique_and_screen_is_company_scoped(): void
    {
        [$assigned, $hidden] = Company::factory()->count(2)->create();
        $this->actingAs($this->userFor('accountant', $assigned));
        Livewire::test(PaymentMethodForm::class)->set('name', 'Cash in Hand')->call('save')->assertHasErrors(['name' => 'unique']);

        $hit = Livewire::test(PaymentMethodForm::class)->set('companyId', (string) $hidden->id)->set('name', 'Cash in Hand')->call('save');
        $miss = Livewire::test(PaymentMethodForm::class)->set('companyId', (string) $hidden->id)->set('name', 'Nobody Uses This')->call('save');
        $this->assertSame(['companyId'], array_keys($hit->errors()->toArray()));
        $this->assertSame($miss->errors()->toArray(), $hit->errors()->toArray());

        $this->get('/admin/payment-methods/'.$hidden->accounts()->where('name', 'Cash in Hand')->sole()->id.'/edit')->assertNotFound();
        $this->get('/admin/payment-methods/'.$assigned->accounts()->where('name', 'Office Rent')->sole()->id.'/edit')->assertNotFound();
        Livewire::test(PaymentMethodIndex::class)->set('companyId', (string) $hidden->id)->assertSet('companyId', (string) $assigned->id);
    }

    public function test_viewing_needs_accounts_view_and_managing_needs_accounts_manage(): void
    {
        $company = Company::factory()->create();
        $cash = $company->accounts()->where('name', 'Cash in Hand')->sole();
        $category = $company->accounts()->where('name', 'Office Rent')->sole();
        $this->actingAs($this->userFor('data-entry', $company));

        foreach (['/admin/categories', '/admin/payment-methods'] as $path) {
            $this->get($path)->assertOk()->assertDontSee(route('admin.categories.create'))->assertDontSee(route('admin.payment-methods.create'));
        }
        foreach (['/admin/categories/create', '/admin/categories/'.$category->id.'/edit', '/admin/payment-methods/create', '/admin/payment-methods/'.$cash->id.'/edit'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_owned_pages_render_for_owner_and_accountant(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create();
        $category = $company->accounts()->where('name', 'Office Rent')->sole();
        $cash = $company->accounts()->where('name', 'Cash in Hand')->sole();
        $paths = ['/admin/parties', '/admin/parties/create', '/admin/parties/'.$party->id.'/edit', '/admin/categories', '/admin/categories/create',
            '/admin/categories/'.$category->id.'/edit', '/admin/payment-methods', '/admin/payment-methods/create', '/admin/payment-methods/'.$cash->id.'/edit'];
        foreach ([User::factory()->create(['role' => 'owner']), $this->userFor('accountant', $company)] as $user) {
            foreach ($paths as $path) {
                $this->actingAs($user)->get($path)->assertOk();
            }
        }
    }

    public function test_navigation_shows_parties_categories_and_payment_methods_by_role(): void
    {
        $company = Company::factory()->create();
        $links = ['parties.index', 'categories.index', 'payment-methods.index'];
        foreach (['owner' => $links, 'accountant' => $links, 'data-entry' => $links, 'custom' => ['parties.index']] as $role => $visible) {
            if ($role === 'custom') {
                RolePermission::factory()->create(['role' => 'custom', 'permissions' => ['admin.access', 'dashboard.view', 'parties.view']]);
            }
            $response = $this->actingAs($this->userFor($role, $company))->get('/admin/parties')->assertOk();
            foreach ($links as $link) {
                $href = 'href="'.route('admin.'.$link).'"';
                in_array($link, $visible, true) ? $response->assertSee($href, false) : $response->assertDontSee($href, false);
            }
        }
    }
}
