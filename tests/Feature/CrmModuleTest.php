<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\User;
use App\Services\RecordDeletion;
use App\Support\CompanyContext;
use App\Support\Crm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrmModuleTest extends TestCase
{
    use RefreshDatabase;

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    public function test_every_new_company_gets_the_default_statuses_once(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(count(Crm::DEFAULT_STATUSES), CrmStatus::query()->where('company_id', $company->id)->count());
        $this->assertSame(['New', 'Contacted', 'Qualified', 'Proposal sent', 'Negotiation', 'Closed won', 'Closed lost'],
            CrmStatus::query()->where('company_id', $company->id)->lead()->orderBy('position')->pluck('name')->all());
        $this->assertSame(['Closed won', 'Closed lost'], CrmStatus::query()->where('company_id', $company->id)->where('is_closed', true)->orderBy('position')->pluck('name')->all());
        Crm::createDefaults($company->id);
        $this->assertSame(count(Crm::DEFAULT_STATUSES), CrmStatus::query()->where('company_id', $company->id)->count());
    }

    public function test_the_header_switches_between_modules_the_user_can_open(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('owner'));

        $this->get('/admin')->assertOk()->assertSee(route('admin.crm.dashboard'))->assertSee(route('admin.entries.index'))->assertDontSee(route('admin.crm.leads.index'));
        $this->get('/admin/crm')->assertOk()->assertSee(route('admin.crm.leads.index'))->assertSee(route('admin.crm.calls.index'))->assertDontSee(route('admin.entries.index'));
        // Shared pages keep the module the user was last in.
        $this->get('/admin/companies')->assertOk()->assertSee(route('admin.crm.leads.index'))->assertDontSee(route('admin.entries.index'));
        $this->get('/admin/entries')->assertOk();
        $this->get('/admin/companies')->assertOk()->assertSee(route('admin.entries.index'))->assertDontSee(route('admin.crm.leads.index'));
        $this->assertNotNull($company);
    }

    public function test_crm_only_users_land_on_the_crm_and_accounting_only_users_never_see_it(): void
    {
        $company = Company::factory()->create();
        $sales = $this->userFor('sales', $company);
        $this->actingAs($sales)->get('/admin')->assertRedirect(route('admin.crm.dashboard'));
        $this->get('/admin/crm')->assertOk()->assertDontSee('class="segmented module-switcher"', false);
        $this->get('/admin/entries')->assertForbidden();

        $this->actingAs($this->userFor('accountant', $company))->get('/admin')->assertOk()->assertDontSee(route('admin.crm.dashboard'));
        $this->get('/admin/crm')->assertForbidden();
        $this->get('/admin/crm/leads')->assertForbidden();
    }

    public function test_deleting_a_company_removes_its_crm_data(): void
    {
        $company = Company::factory()->create(['code' => 'GONE']);
        $lead = Lead::factory()->for($company)->create();
        LeadCall::factory()->for($lead)->create();
        $owner = $this->userFor('owner');
        session([CompanyContext::SESSION_KEY => $company->id]);

        app(RecordDeletion::class)->hardDelete($company, $owner, 'GONE');

        $this->assertDatabaseCount('lead_calls', 0);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseMissing('crm_statuses', ['company_id' => $company->id]);
        $this->assertModelMissing($company);
    }

    public function test_an_employee_who_logged_calls_is_deactivated_instead_of_deleted_and_assigned_leads_survive(): void
    {
        $company = Company::factory()->create();
        $owner = $this->userFor('owner');
        $caller = $this->userFor('sales', $company);
        LeadCall::factory()->for(Lead::factory()->for($company))->for($caller)->create();
        try {
            app(RecordDeletion::class)->deleteUnused($caller, $owner);
            $this->fail('An employee with logged calls must not be deleted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('logged CRM calls', collect($exception->errors())->flatten()->first());
        }

        $holder = $this->userFor('sales', $company);
        $lead = Lead::factory()->for($company)->create(['assigned_to' => $holder->id]);
        app(RecordDeletion::class)->deleteUnused($holder, $owner);
        $this->assertNull($lead->fresh()->assigned_to);
    }
}
