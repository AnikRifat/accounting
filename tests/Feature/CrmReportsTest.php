<?php

namespace Tests\Feature;

use App\Livewire\Admin\Crm\Dashboard;
use App\Livewire\Admin\Crm\Insights;
use App\Livewire\Admin\Crm\Reports\Performance;
use App\Livewire\Admin\Crm\Services\Form as ServiceForm;
use App\Livewire\Admin\Crm\Services\Index as ServiceIndex;
use App\Livewire\Admin\Crm\Statuses\Form as StatusForm;
use App\Livewire\Admin\Crm\Statuses\Index as StatusIndex;
use App\Models\Company;
use App\Models\CrmService;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrmReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-29 11:30:00');
    }

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function statusId(Company $company, string $name): int
    {
        return CrmStatus::query()->where('company_id', $company->id)->where('name', $name)->value('id');
    }

    public function test_the_dashboard_queues_follow_ups_and_leaves_out_closed_leads(): void
    {
        $company = Company::factory()->create();
        Lead::factory()->for($company)->create(['name' => 'Today Lead', 'next_call_on' => '2026-09-29']);
        Lead::factory()->for($company)->create(['name' => 'Late Lead', 'next_call_on' => '2026-09-01']);
        Lead::factory()->for($company)->create(['name' => 'Later Lead', 'next_call_on' => '2026-10-15']);
        Lead::factory()->for($company)->create(['name' => 'Lost Lead', 'next_call_on' => '2026-09-01', 'crm_status_id' => $this->statusId($company, 'Closed lost')]);
        $this->actingAs($this->userFor('sales-manager', $company));

        $component = Livewire::test(Dashboard::class);
        $queues = $component->viewData('queues');
        $this->assertSame(['Today Lead'], $queues['today']->pluck('name')->all());
        $this->assertSame(['Late Lead'], $queues['overdue']->pluck('name')->all());
        $this->assertSame(['Later Lead'], $queues['upcoming']->pluck('name')->all());
        $this->assertSame(4, $component->viewData('totalLeads'));
    }

    public function test_insights_count_leads_calls_statuses_and_follow_ups(): void
    {
        $company = Company::factory()->create();
        $rep = $this->userFor('sales', $company);
        $called = Lead::factory()->for($company)->create(['assigned_to' => $rep->id, 'crm_status_id' => $this->statusId($company, 'Contacted'), 'next_call_on' => '2026-09-29']);
        Lead::factory()->for($company)->create(['assigned_to' => $rep->id]);
        Lead::factory()->for($company)->create();
        LeadCall::factory()->for($called)->for($rep)->count(2)->create(['call_status_id' => $this->statusId($company, 'Busy')]);
        LeadCall::factory()->for($called)->for($rep)->create(['type' => 'visit']);
        $this->actingAs($this->userFor('sales-manager', $company));

        $component = Livewire::test(Insights::class);
        $this->assertSame(['leads' => 3, 'neverCalled' => 2, 'calls' => 2, 'leadsCalled' => 1, 'repeatCalls' => 2], $component->viewData('leadFigures'));
        $this->assertSame(['New' => 2, 'Contacted' => 1, 'Qualified' => 0], array_slice($component->viewData('byLeadStatus'), 0, 3));
        $this->assertSame(2, $component->viewData('byCallResult')['Busy']);
        $this->assertSame(1, $component->viewData('byCallResult')['Not recorded']);
        $this->assertSame(['today' => 1, 'overdue' => 0, 'upcoming' => 0, 'visitsToday' => 1, 'visits' => 1], $component->viewData('followUps'));

        // A rep sees their own figures only, whatever person is asked for.
        $this->actingAs($rep);
        $this->assertSame(2, Livewire::test(Insights::class)->set('person', '')->viewData('leadFigures')['leads']);
    }

    public function test_team_performance_counts_per_person(): void
    {
        $company = Company::factory()->create();
        [$rahim, $karim] = [$this->userFor('sales', $company), $this->userFor('sales', $company)];
        $rahim->update(['name' => 'Rahim']);
        $karim->update(['name' => 'Karim']);
        $lead = Lead::factory()->for($company)->create(['assigned_to' => $rahim->id, 'next_call_on' => '2026-09-01']);
        Lead::factory()->for($company)->create(['assigned_to' => $rahim->id, 'crm_status_id' => $this->statusId($company, 'Closed won')]);
        LeadCall::factory()->for($lead)->for($karim)->count(3)->create();
        $this->actingAs($this->userFor('sales-manager', $company));

        $rows = Livewire::test(Performance::class)->viewData('rows')->keyBy('name');
        $this->assertSame([2, 1, 0, 1], [$rows['Rahim']->leads_count, $rows['Rahim']->overdue_count, $rows['Rahim']->calls_count, $rows['Rahim']->status_5_count]);
        $this->assertSame([0, 3], [$rows['Karim']->leads_count, $rows['Karim']->calls_count]);

        $this->actingAs($rahim)->get('/admin/crm/reports/performance')->assertForbidden();
    }

    public function test_services_are_managed_per_header_company_and_used_ones_are_only_deactivated(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs($this->userFor('sales-manager', $first, $second));
        session([CompanyContext::SESSION_KEY => $first->id]);

        Livewire::test(ServiceForm::class)->set('name', ' Ecommerce ')->call('save')->assertHasNoErrors();
        Livewire::test(ServiceForm::class)->set('name', 'Ecommerce')->call('save')->assertHasErrors(['name' => 'unique']);
        session([CompanyContext::SESSION_KEY => $second->id]);
        Livewire::test(ServiceForm::class)->set('name', 'Ecommerce')->call('save')->assertHasNoErrors();
        $this->assertSame(2, CrmService::query()->where('name', 'Ecommerce')->count());

        $used = CrmService::query()->where('company_id', $second->id)->sole();
        Lead::factory()->for($second)->create(['crm_service_id' => $used->id]);
        Livewire::test(ServiceIndex::class)->call('delete', $used->id)->assertHasErrors('delete');
        $this->assertModelExists($used);
        session([CompanyContext::SESSION_KEY => $first->id]);
        $unused = CrmService::query()->where('company_id', $first->id)->sole();
        Livewire::test(ServiceIndex::class)->call('delete', $unused->id)->assertHasNoErrors();
        $this->assertModelMissing($unused);
    }

    public function test_statuses_keep_their_type_and_only_lead_statuses_can_close(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('sales-manager', $company));

        Livewire::test(StatusForm::class, ['type' => 'call'])->set('name', 'Wrong number')->set('tone', 'danger')->set('isClosed', true)
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.crm.statuses.index', ['type' => 'call']));
        $status = CrmStatus::query()->where('name', 'Wrong number')->sole();
        $this->assertSame(['call', 'danger', false], [$status->type->value, $status->tone, $status->is_closed]);

        Livewire::test(StatusForm::class, ['status' => $status])->set('type', 'lead')->set('tone', 'purple')->call('save')->assertHasErrors('tone');
        Livewire::test(StatusForm::class, ['status' => $status])->set('type', 'lead')->set('name', 'Invalid number')->call('save')->assertHasNoErrors();
        $this->assertSame(['call', 'Invalid number'], [$status->fresh()->type->value, $status->fresh()->name]);

        $new = CrmStatus::query()->where('company_id', $company->id)->where('name', 'New')->sole();
        Livewire::test(StatusIndex::class)->call('delete', $new->id)->assertHasNoErrors();
        Lead::factory()->for($company)->create();
        $inUse = CrmStatus::query()->where('company_id', $company->id)->where('name', 'Contacted')->sole();
        Lead::query()->update(['crm_status_id' => $inUse->id]);
        Livewire::test(StatusIndex::class)->call('delete', $inUse->id)->assertHasErrors('delete');
    }

    public function test_setup_needs_the_manage_permission_but_sales_can_read_it(): void
    {
        $company = Company::factory()->create();
        $service = CrmService::factory()->for($company)->create(['name' => 'Websites']);
        $this->actingAs($this->userFor('sales', $company));

        $this->get('/admin/crm/services')->assertOk()->assertSee('Websites')->assertDontSee(route('admin.crm.services.edit', $service));
        $this->get('/admin/crm/statuses')->assertOk()->assertSee('Closed won');
        $this->get('/admin/crm/services/create')->assertForbidden();
        Livewire::test(ServiceIndex::class)->call('delete', $service->id)->assertForbidden();
    }

    public function test_every_crm_page_renders_in_all_and_single_company_mode(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $service = CrmService::factory()->for($first)->create();
        $lead = Lead::factory()->for($first)->create(['crm_service_id' => $service->id, 'next_call_on' => '2026-09-29']);
        $call = LeadCall::factory()->for($lead)->create(['call_status_id' => $this->statusId($first, 'Busy')]);
        $this->actingAs($this->userFor('owner'));
        $pages = ['/admin/crm', '/admin/crm/insights', '/admin/crm/leads', '/admin/crm/leads/'.$lead->id, '/admin/crm/leads/'.$lead->id.'/edit',
            '/admin/crm/leads/'.$lead->id.'/calls/create', '/admin/crm/calls', '/admin/crm/calls/'.$call->id.'/edit', '/admin/crm/services',
            '/admin/crm/statuses', '/admin/crm/statuses?type=call', '/admin/crm/reports/performance', '/admin/crm/leads?sheet=call:'.$lead->id];

        foreach ([null, $first->id, $second->id] as $context) {
            session([CompanyContext::SESSION_KEY => $context]);
            foreach ($pages as $page) {
                $this->get($page)->assertOk();
            }
        }
        $this->get('/admin/crm/leads/create')->assertOk();
        $this->get('/admin/crm/leads?sheet=import')->assertOk()->assertSee(route('admin.crm.leads.import-template'));
        $this->get('/admin/crm/leads/import-template')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
