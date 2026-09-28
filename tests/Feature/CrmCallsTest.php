<?php

namespace Tests\Feature;

use App\Livewire\Admin\Crm\Calls\Form;
use App\Livewire\Admin\Crm\Calls\Index;
use App\Models\Company;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrmCallsTest extends TestCase
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

    public function test_logging_a_call_moves_the_lead_to_its_status_and_next_call(): void
    {
        $company = Company::factory()->create();
        $rep = $this->userFor('sales', $company);
        $lead = Lead::factory()->for($company)->create(['assigned_to' => $rep->id, 'next_call_on' => '2026-09-29']);
        $this->actingAs($rep);

        Livewire::test(Form::class, ['lead' => $lead])->assertSet('calledOn', '2026-09-29')->assertSet('calledTime', '11:30')
            ->set('callStatusId', (string) $this->statusId($company, 'Connected'))->set('leadStatusId', (string) $this->statusId($company, 'Qualified'))
            ->set('summary', ' Wants a demo ')->set('nextCallOn', '2026-10-02')->call('save')->assertHasNoErrors()
            ->assertRedirect(route('admin.crm.leads.show', $lead));

        $call = LeadCall::sole();
        $this->assertSame([$company->id, $rep->id, 'Wants a demo', '2026-09-29 11:30:00'], [$call->company_id, $call->user_id, $call->summary, $call->called_at->toDateTimeString()]);
        $this->assertSame(['Qualified', '2026-10-02'], [$lead->fresh()->status->name, $lead->fresh()->next_call_on->toDateString()]);
    }

    public function test_a_closed_status_ends_follow_ups_and_an_older_call_does_not_override_the_latest(): void
    {
        $company = Company::factory()->create();
        $manager = $this->userFor('sales-manager', $company);
        $lead = Lead::factory()->for($company)->create(['next_call_on' => '2026-09-29']);
        $this->actingAs($manager);

        Livewire::test(Form::class, ['lead' => $lead])->set('leadStatusId', (string) $this->statusId($company, 'Closed won'))
            ->set('nextCallOn', '2026-10-10')->call('save')->assertHasNoErrors();
        $this->assertSame(['Closed won', null], [$lead->fresh()->status->name, $lead->fresh()->next_call_on]);

        Livewire::test(Form::class, ['lead' => $lead])->set('calledOn', '2026-09-20')->set('leadStatusId', (string) $this->statusId($company, 'Contacted'))
            ->set('nextCallOn', '2026-09-25')->call('save')->assertHasNoErrors();
        $this->assertSame(['Closed won', null], [$lead->fresh()->status->name, $lead->fresh()->next_call_on]);
        $this->assertSame(2, $lead->calls()->count());
    }

    public function test_call_input_is_validated_against_the_lead_company(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $lead = Lead::factory()->for($mine)->create();
        $this->actingAs($this->userFor('sales-manager', $mine));

        Livewire::test(Form::class, ['lead' => $lead])->set('callStatusId', (string) $this->statusId($other, 'Busy'))
            ->set('leadStatusId', (string) $this->statusId($other, 'Qualified'))->set('calledOn', '2026-10-01')
            ->set('nextCallOn', '2026-09-01')->set('type', 'fax')->call('save')
            ->assertHasErrors(['callStatusId', 'leadStatusId', 'calledOn', 'nextCallOn', 'type']);
        $this->assertDatabaseCount('lead_calls', 0);
    }

    public function test_calls_cannot_be_logged_on_hidden_leads_or_in_inactive_companies(): void
    {
        $company = Company::factory()->create();
        $rep = $this->userFor('sales', $company);
        $colleagueLead = Lead::factory()->for($company)->create(['assigned_to' => $this->userFor('sales', $company)->id]);
        $this->actingAs($rep);
        $this->get('/admin/crm/leads/'.$colleagueLead->id.'/calls/create')->assertNotFound();

        $mine = Lead::factory()->for($company)->create(['assigned_to' => $rep->id]);
        $company->update(['is_active' => false]);
        Livewire::test(Form::class, ['lead' => $mine])->call('save')->assertHasErrors('company');
        $this->assertDatabaseCount('lead_calls', 0);
    }

    public function test_reps_edit_and_delete_only_their_own_calls(): void
    {
        $company = Company::factory()->create();
        $rep = $this->userFor('sales', $company);
        $colleague = $this->userFor('sales', $company);
        $lead = Lead::factory()->for($company)->create(['assigned_to' => $rep->id]);
        $own = LeadCall::factory()->for($lead)->for($rep)->create(['summary' => 'Mine']);
        $theirs = LeadCall::factory()->for($lead)->for($colleague)->create(['summary' => 'Covered for you']);
        $this->actingAs($rep);

        $this->get('/admin/crm/calls')->assertOk()->assertSee('Mine')->assertSee('Covered for you');
        $this->get('/admin/crm/calls/'.$theirs->id.'/edit')->assertForbidden();
        $this->get('/admin/crm/calls/'.$own->id.'/edit')->assertOk();
        Livewire::test(Form::class, ['call' => $own])->set('summary', 'Updated')->call('save')->assertHasNoErrors();
        $this->assertSame('Updated', $own->fresh()->summary);

        $this->actingAs($this->userFor('sales-manager', $company));
        Livewire::test(Index::class)->call('delete', $theirs->id)->assertHasNoErrors();
        $this->assertModelMissing($theirs);
    }

    public function test_the_call_log_is_scoped_and_filtered(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $manager = $this->userFor('sales-manager', $first, $second);
        $caller = $this->userFor('sales', $first);
        LeadCall::factory()->for(Lead::factory()->for($first)->state(['name' => 'First Lead']))->for($caller)
            ->create(['summary' => 'Busy line', 'call_status_id' => $this->statusId($first, 'Busy'), 'called_at' => '2026-09-10 10:00:00']);
        LeadCall::factory()->for(Lead::factory()->for($second)->state(['name' => 'Second Lead']))->for($manager)
            ->create(['summary' => 'Office visit', 'type' => 'visit', 'called_at' => '2026-09-28 15:00:00']);
        LeadCall::factory()->for(Lead::factory()->for(Company::factory())->state(['name' => 'Stranger']))->create(['summary' => 'Not yours']);
        $this->actingAs($manager);

        Livewire::test(Index::class)->assertSee('Busy line')->assertSee('Office visit')->assertDontSee('Not yours')
            ->set('callStatus', 'Busy')->assertSee('Busy line')->assertDontSee('Office visit')
            ->set('callStatus', '')->set('type', 'visit')->assertSee('Office visit')->assertDontSee('Busy line')
            ->set('type', '')->set('caller', (string) $caller->id)->assertSee('Busy line')->assertDontSee('Office visit')
            ->set('caller', '')->set('from', '2026-09-20')->assertSee('Office visit')->assertDontSee('Busy line');
        session([CompanyContext::SESSION_KEY => $first->id]);
        Livewire::test(Index::class)->assertSee('Busy line')->assertDontSee('Office visit');
    }
}
