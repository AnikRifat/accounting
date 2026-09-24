<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** List rows open the shared delete drawer only for users who may delete, and never for protected records. */
class DeleteButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_buttons_follow_permissions_and_protected_records(): void
    {
        $company = Company::factory()->create();
        $custom = Party::factory()->for($company)->create(['name' => 'Karim Traders']);
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach($company);
        $accountant->syncParties();
        session([CompanyContext::SESSION_KEY => $company->id]);
        $receivable = $company->accounts()->where('is_system', true)->where('type', 'asset')->sole();
        $rent = $company->accounts()->where('code', '5100')->sole();
        $cash = $company->accounts()->where('code', '1000')->sole();

        $this->actingAs($accountant);
        $this->get(route('admin.categories.index'))->assertOk()->assertSee("kind: 'category', id: {$rent->id}", false);
        $this->get(route('admin.payment-methods.index'))->assertOk()->assertSee("kind: 'payment-method', id: {$cash->id}", false);
        $this->get(route('admin.accounts.index'))->assertOk()->assertSee("kind: 'account', id: {$rent->id}", false)
            ->assertDontSee("kind: 'account', id: {$receivable->id}", false);
        $employeeParty = $accountant->parties()->sole();
        $this->get(route('admin.parties.index'))->assertOk()->assertSee("kind: 'party', id: {$custom->id}", false)
            ->assertDontSee("kind: 'party', id: {$employeeParty->id}", false);
        $this->get(route('admin.companies.index'))->assertOk()->assertDontSee("kind: 'company'", false);

        $clerk = User::factory()->create(['role' => 'data-entry']);
        $clerk->companies()->attach($company);
        $this->actingAs($clerk);
        foreach (['admin.categories.index', 'admin.payment-methods.index', 'admin.parties.index'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('open-delete', false);
        }

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get(route('admin.companies.index'))->assertOk()->assertSee("kind: 'company', id: {$company->id}", false);
    }
}
