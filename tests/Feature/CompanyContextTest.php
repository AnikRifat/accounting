<?php

namespace Tests\Feature;

use App\Livewire\Admin\ChooseCompany;
use App\Livewire\CompanySwitcher;
use App\Livewire\CreateCompanyDrawer;
use App\Models\Company;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_defaults_to_all_companies_and_can_switch_to_one(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $context = app(CompanyContext::class);
        $this->assertTrue($context->isAll());
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $context->companyIds());

        Livewire::test(CompanySwitcher::class)->set('selected', (string) $second->id)->assertRedirect();
        $context = app(CompanyContext::class);
        $this->assertSame($second->id, $context->selectedId());
        $this->assertSame([$second->id], $context->companyIds());
    }

    public function test_return_url_keeps_the_page_filters_and_ignores_foreign_referers(): void
    {
        $page = url('/admin/entries').'?status=overdue&party=7';
        request()->headers->set('referer', $page);
        $this->assertSame($page, CompanyContext::returnUrl());

        foreach (['https://evil.test/admin/entries', url('/logout'), 'not a url'] as $referer) {
            request()->headers->set('referer', $referer);
            $this->assertSame(url()->current(), CompanyContext::returnUrl());
        }
    }

    public function test_switching_to_an_invisible_company_is_refused(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $third = Company::factory()->create();
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach([$mine->id, $third->id]);
        $this->actingAs($accountant);

        Livewire::test(CompanySwitcher::class)->set('selected', (string) $other->id)->assertNoRedirect()->assertSet('selected', '');
        $this->assertTrue(app(CompanyContext::class)->isAll());
        $this->assertEqualsCanonicalizing([$mine->id, $third->id], app(CompanyContext::class)->companyIds());
    }

    public function test_single_company_user_is_pinned_and_stale_selection_falls_back_to_all(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $clerk = User::factory()->create(['role' => 'data-entry']);
        $clerk->companies()->attach($mine);
        $this->actingAs($clerk);
        session([CompanyContext::SESSION_KEY => $other->id]);
        $this->assertSame($mine->id, app(CompanyContext::class)->selectedId());

        $clerk->companies()->attach($other);
        $third = Company::factory()->create();
        session([CompanyContext::SESSION_KEY => $third->id]);
        $this->assertNull(app(CompanyContext::class)->selectedId());
    }

    public function test_header_shows_switcher_for_many_companies_and_a_name_for_one(): void
    {
        [$first, $second] = Company::factory()->count(2)->create(['is_active' => true]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('All companies')->assertSee($first->name)->assertSee($second->name);

        $clerk = User::factory()->create(['role' => 'data-entry']);
        $clerk->companies()->attach($first);
        $this->actingAs($clerk)->get(route('admin.dashboard'))->assertOk()->assertDontSee('All companies')->assertSee($first->name);
    }

    public function test_new_company_drawer_creates_the_company_with_its_books_and_switches_to_it(): void
    {
        Company::factory()->count(2)->create();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk()->assertSee('New company')->assertSee('create-company');

        Livewire::test(CreateCompanyDrawer::class)->set('name', ' Padma Foods ')->set('code', ' pfl ')->call('save')->assertHasNoErrors()->assertRedirect();
        $company = Company::where('code', 'PFL')->sole();
        $this->assertSame('Padma Foods', $company->name);
        $this->assertTrue($company->accounts()->where('is_cash', true)->exists());
        $this->assertSame($company->id, app(CompanyContext::class)->selectedId());

        Livewire::test(CreateCompanyDrawer::class)->set('name', 'Duplicate')->set('code', 'PFL')->call('save')->assertHasErrors('code');
        Livewire::test(CreateCompanyDrawer::class)->set('name', 'Bad')->set('code', 'P-1')->call('save')->assertHasErrors('code');
    }

    public function test_company_creator_without_all_companies_is_assigned_and_others_cannot_create(): void
    {
        RolePermission::factory()->create(['role' => 'branch_manager', 'permissions' => ['admin.access', 'dashboard.view', 'companies.view', 'companies.create']]);
        $manager = User::factory()->create(['role' => 'branch_manager']);
        $this->actingAs($manager);
        Livewire::test(CreateCompanyDrawer::class)->set('name', 'Branch Co')->set('code', 'BRC')->call('save')->assertHasNoErrors();
        $this->assertTrue($manager->canAccessCompany(Company::where('code', 'BRC')->value('id')));

        $accountant = User::factory()->create(['role' => 'accountant']);
        $this->actingAs($accountant)->get(route('admin.dashboard'))->assertOk()->assertDontSee('New company');
        Livewire::test(CreateCompanyDrawer::class)->set('name', 'Sneaky')->set('code', 'SNK')->call('save')->assertForbidden();
        $this->assertFalse(Company::where('code', 'SNK')->exists());
    }

    public function test_choose_company_selects_an_active_company_and_only_follows_admin_paths(): void
    {
        [$active] = Company::factory()->count(2)->create();
        $inactive = Company::factory()->create(['is_active' => false]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        Livewire::withQueryParams(['next' => '/admin/entries/create/income'])->test(ChooseCompany::class)
            ->assertSee($active->name)->assertDontSee($inactive->name)
            ->call('choose', $active->id)->assertRedirect('/admin/entries/create/income');
        $this->assertSame($active->id, app(CompanyContext::class)->selectedId());

        foreach (['https://evil.test/admin/x', '//evil.test/admin/', '/admin//evil.test', '/logout'] as $next) {
            Livewire::withQueryParams(['next' => $next])->test(ChooseCompany::class)->call('choose', $active->id)->assertRedirect(route('admin.dashboard'));
        }
        Livewire::withQueryParams(['next' => '/admin/entries/create/income'])->test(ChooseCompany::class)->call('choose', $inactive->id)->assertNotFound();

        // Reports can open a closed company's books.
        Livewire::withQueryParams(['next' => '/admin/reports/account-ledger'])->test(ChooseCompany::class)
            ->assertSee($inactive->name)->call('choose', $inactive->id)->assertRedirect('/admin/reports/account-ledger');
        $this->assertSame($inactive->id, app(CompanyContext::class)->selectedId());
    }
}
