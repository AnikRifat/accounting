<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\Categories\Index as CategoryIndex;
use App\Livewire\Admin\PaymentMethods\Form as PaymentMethodForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

    public function test_category_names_are_unique_within_the_header_company(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs($this->userFor('accountant', $first, $second));
        session([CompanyContext::SESSION_KEY => $first->id]);
        Livewire::test(CategoryForm::class)->set('name', 'Office Rent')->call('save')->assertHasErrors(['name' => 'unique']);
        Livewire::test(CategoryForm::class)->set('name', 'Brand New')->call('save')->assertHasNoErrors();
        session([CompanyContext::SESSION_KEY => $second->id]);
        Livewire::test(CategoryForm::class)->set('name', 'Brand New')->call('save')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], Account::where('name', 'Brand New')->pluck('company_id')->all());
    }

    public function test_on_all_companies_a_new_category_is_added_to_every_active_company_that_lacks_it(): void
    {
        [$first, $second, $third] = Company::factory()->count(3)->sequence(['name' => 'Alpha Ltd'], ['name' => 'Beta Ltd'], ['name' => 'Gamma Ltd'])->create();
        $inactive = Company::factory()->create(['is_active' => false]);
        $hidden = Company::factory()->create();
        $second->accounts()->create(['code' => '4500', 'name' => 'Consulting', 'type' => AccountType::Income]);
        $this->actingAs($this->userFor('accountant', $first, $second, $third, $inactive));

        Livewire::test(CategoryForm::class)->assertSet('companyId', null)->set('name', 'Consulting')->set('type', 'income')->call('save')
            ->assertHasNoErrors()->assertRedirect(route('admin.categories.index'));
        $this->assertStringContainsString('Beta Ltd', session('success'));
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $third->id], Account::where('name', 'Consulting')->pluck('company_id')->all());
        foreach ([$first, $third] as $company) {
            $code = (int) $company->accounts()->where('name', 'Consulting')->value('code');
            $this->assertTrue($code >= 4000 && $code <= 4999);
        }
        $this->assertFalse($inactive->accounts()->where('name', 'Consulting')->exists());
        $this->assertFalse($hidden->accounts()->where('name', 'Consulting')->exists());

        Livewire::test(CategoryForm::class)->set('name', 'Consulting')->set('type', 'income')->call('save')->assertHasErrors('name');
    }

    public function test_all_companies_view_combines_categories_by_name_and_edit_switches_the_company(): void
    {
        [$first, $second] = Company::factory()->count(2)->sequence(['name' => 'Alpha Ltd'], ['name' => 'Beta Ltd'])->create();
        $hidden = Company::factory()->create(['name' => 'Hidden Ltd']);
        $second->accounts()->where('name', 'Utilities')->update(['is_active' => false]);
        $this->actingAs($this->userFor('accountant', $first, $second));

        $html = $this->get('/admin/categories')->assertOk()->assertSeeInOrder(['Utilities', 'Alpha Ltd', __('Active'), 'Beta Ltd', __('Inactive')])->getContent();
        $this->assertSame(1, substr_count($html, '<strong>Office Rent</strong>'));
        $this->assertStringNotContainsString('Hidden Ltd', $html);

        $rent = $second->accounts()->where('name', 'Office Rent')->sole();
        Livewire::test(CategoryIndex::class)->call('editIn', $rent->id)->assertRedirect(route('admin.categories.index', ['sheet' => 'edit:'.$rent->id]));
        $this->assertSame($second->id, app(CompanyContext::class)->selectedId());
        $this->get('/admin/categories')->assertOk()->assertSee('<strong>Office Rent</strong>', false)->assertDontSee('<th>'.__('Companies').'</th>', false);

        foreach ([$hidden->accounts()->where('name', 'Office Rent')->sole(), $first->accounts()->where('name', 'Cash in Hand')->sole()] as $account) {
            try {
                Livewire::test(CategoryIndex::class)->call('editIn', $account->id);
                $this->fail('editIn must refuse accounts that are not visible categories.');
            } catch (ModelNotFoundException) {
            }
        }
        $this->assertSame($second->id, app(CompanyContext::class)->selectedId());
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
        [$assigned, $other] = Company::factory()->count(2)->create();
        $hidden = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $assigned, $other));

        // A crafted session value for an invisible company falls back to All-visible.
        session([CompanyContext::SESSION_KEY => $hidden->id]);
        Livewire::test(CategoryForm::class)->assertSet('companyId', null)->set('name', 'Fresh Category')->call('save')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing([$assigned->id, $other->id], Account::where('name', 'Fresh Category')->pluck('company_id')->all());

        session([CompanyContext::SESSION_KEY => $assigned->id]);
        $component = Livewire::test(CategoryForm::class);
        try {
            $component->set('companyId', $hidden->id);
            $this->fail('The company of the form must not be settable from the client.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        session([CompanyContext::SESSION_KEY => $other->id]);
        $component->set('name', 'Too Late')->call('save')->assertHasErrors('company');
        $this->assertDatabaseMissing('accounts', ['name' => 'Too Late']);

        $this->get('/admin/categories/'.$hidden->accounts()->where('name', 'Office Rent')->sole()->id.'/edit')->assertNotFound();
        foreach (['Cash in Hand', 'Opening Balance Equity'] as $name) {
            $this->get('/admin/categories/'.$assigned->accounts()->where('name', $name)->sole()->id.'/edit')->assertNotFound();
        }
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
        [$assigned, $other, $third] = Company::factory()->count(3)->create();
        $hidden = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $assigned, $other, $third));
        $choose = route('admin.choose-company', ['next' => '/admin/payment-methods/create']);

        $this->get('/admin/payment-methods/create')->assertRedirect($choose);
        session([CompanyContext::SESSION_KEY => $hidden->id]);
        $this->get('/admin/payment-methods/create')->assertRedirect($choose);
        $other->update(['is_active' => false]);
        session([CompanyContext::SESSION_KEY => $other->id]);
        $this->get('/admin/payment-methods/create')->assertRedirect($choose);

        session([CompanyContext::SESSION_KEY => $assigned->id]);
        $this->get('/admin/payment-methods/create')->assertOk();
        Livewire::test(PaymentMethodForm::class)->set('name', 'Cash in Hand')->call('save')->assertHasErrors(['name' => 'unique']);
        $component = Livewire::test(PaymentMethodForm::class)->assertSet('companyId', $assigned->id);
        try {
            $component->set('companyId', $hidden->id);
            $this->fail('The company of the form must not be settable from the client.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        session([CompanyContext::SESSION_KEY => $third->id]);
        $component->set('name', 'Too Late')->call('save')->assertHasErrors('company');
        $this->assertDatabaseMissing('accounts', ['name' => 'Too Late']);

        $this->get('/admin/payment-methods/'.$hidden->accounts()->where('name', 'Cash in Hand')->sole()->id.'/edit')->assertNotFound();
        $this->get('/admin/payment-methods/'.$assigned->accounts()->where('name', 'Office Rent')->sole()->id.'/edit')->assertNotFound();
    }

    public function test_all_companies_view_groups_payment_methods_by_company_with_totals(): void
    {
        [$first, $second] = Company::factory()->count(2)->sequence(['name' => 'Alpha Ltd'], ['name' => 'Beta Ltd'])->create();
        $actor = $this->userFor('accountant', $first, $second);
        $this->actingAs($actor);
        $ledger = app(LedgerService::class);
        $ledger->recordOpening($first->accounts()->where('name', 'Cash in Hand')->sole(), 100_000, '2026-07-01', $actor);
        $ledger->recordOpening($second->accounts()->where('name', 'bKash')->sole(), 25_050, '2026-07-01', $actor);

        $this->get('/admin/payment-methods')->assertOk()->assertSeeInOrder([
            'Alpha Ltd', 'Cash in Hand', '৳1,000.00', __('Total for :company', ['company' => 'Alpha Ltd']), '৳1,000.00',
            'Beta Ltd', 'bKash', '৳250.50', __('Total for :company', ['company' => 'Beta Ltd']), '৳250.50',
            __('Grand total: :amount', ['amount' => '৳1,250.50']),
        ]);
        session([CompanyContext::SESSION_KEY => $second->id]);
        $this->get('/admin/payment-methods')->assertOk()->assertSee('৳250.50')->assertDontSee('৳1,000.00')->assertDontSee('Grand total');
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
        Livewire::test(CategoryIndex::class)->call('editIn', $category->id)->assertForbidden();
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
