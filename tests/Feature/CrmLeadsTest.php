<?php

namespace Tests\Feature;

use App\Livewire\Admin\Crm\Leads\Form;
use App\Livewire\Admin\Crm\Leads\Import;
use App\Livewire\Admin\Crm\Leads\Index;
use App\Livewire\Admin\Crm\Leads\Show;
use App\Models\Company;
use App\Models\CrmService;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CrmLeadsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_lead_is_created_in_the_header_company_with_a_normalised_phone(): void
    {
        $company = Company::factory()->create();
        $service = CrmService::factory()->for($company)->create(['name' => 'Ecommerce']);
        $manager = $this->userFor('sales-manager', $company);
        $rep = $this->userFor('sales', $company);
        $this->actingAs($manager);

        Livewire::test(Form::class)->assertSet('companyId', $company->id)->assertSet('statusId', (string) $this->statusId($company, 'New'))
            ->set('name', ' Karim ')->set('phone', '+880 1711-000000')->set('serviceId', (string) $service->id)
            ->set('assignedTo', (string) $rep->id)->set('nextCallOn', '2026-10-05')->call('save')->assertHasNoErrors()->assertRedirect(route('admin.crm.leads.index'));

        $lead = Lead::sole();
        $this->assertSame([$company->id, 'Karim', '01711000000', $service->id, $rep->id, $manager->id, '2026-10-05'],
            [$lead->company_id, $lead->name, $lead->phone, $lead->crm_service_id, $lead->assigned_to, $lead->created_by, $lead->next_call_on->toDateString()]);
    }

    public function test_one_phone_number_is_one_lead_per_company(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        Lead::factory()->for($first)->create(['phone' => '01711000000']);
        $this->actingAs($this->userFor('sales-manager', $first, $second));

        session([CompanyContext::SESSION_KEY => $first->id]);
        Livewire::test(Form::class)->set('phone', '8801711000000')->call('save')->assertHasErrors(['phone' => 'unique']);
        Livewire::test(Form::class)->set('phone', 'call me')->call('save')->assertHasErrors(['phone' => 'regex']);
        session([CompanyContext::SESSION_KEY => $second->id]);
        Livewire::test(Form::class)->set('phone', '01711 000000')->call('save')->assertHasNoErrors();
        $this->assertSame(2, Lead::query()->where('phone', '01711000000')->count());
    }

    public function test_services_statuses_and_assignees_must_belong_to_the_lead_company(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $foreignService = CrmService::factory()->for($other)->create();
        $outsider = $this->userFor('sales', $other);
        $this->actingAs($this->userFor('sales-manager', $mine));

        Livewire::test(Form::class)->set('phone', '01711000001')->set('serviceId', (string) $foreignService->id)
            ->set('statusId', (string) $this->statusId($other, 'New'))->set('assignedTo', (string) $outsider->id)
            ->call('save')->assertHasErrors(['serviceId', 'statusId', 'assignedTo']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_the_lead_company_cannot_be_moved_with_crafted_values(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $hidden = Company::factory()->create();
        $this->actingAs($this->userFor('sales-manager', $first, $second));

        $this->get('/admin/crm/leads/create')->assertRedirect(route('admin.choose-company', ['next' => '/admin/crm/leads/create']));
        session([CompanyContext::SESSION_KEY => $second->id]);
        $component = Livewire::test(Form::class);
        try {
            $component->set('companyId', $hidden->id);
            $this->fail('The company of the form must not be settable from the client.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        session([CompanyContext::SESSION_KEY => $first->id]);
        $component->set('phone', '01711000002')->call('save')->assertHasErrors('company');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sales_reps_see_and_edit_only_their_own_leads_and_cannot_reassign_them(): void
    {
        $company = Company::factory()->create();
        $rep = $this->userFor('sales', $company);
        $colleague = $this->userFor('sales', $company);
        $mine = Lead::factory()->for($company)->create(['name' => 'My Lead', 'assigned_to' => $rep->id]);
        $theirs = Lead::factory()->for($company)->create(['name' => 'Their Lead', 'assigned_to' => $colleague->id]);
        $this->actingAs($rep);

        $this->get('/admin/crm/leads')->assertOk()->assertSee('My Lead')->assertDontSee('Their Lead');
        $this->get('/admin/crm/leads/'.$mine->id)->assertOk();
        $this->get('/admin/crm/leads/'.$theirs->id)->assertNotFound();
        $this->get('/admin/crm/leads/'.$theirs->id.'/edit')->assertNotFound();

        Livewire::test(Form::class, ['lead' => $mine])->set('assignedTo', (string) $colleague->id)->set('name', 'Renamed')->call('save')->assertHasNoErrors();
        $this->assertSame(['Renamed', $rep->id], [$mine->fresh()->name, $mine->fresh()->assigned_to]);

        Livewire::test(Form::class)->set('phone', '01811000000')->set('assignedTo', (string) $colleague->id)->call('save')->assertHasNoErrors();
        $this->assertSame($rep->id, Lead::query()->where('phone', '01811000000')->value('assigned_to'));
        $this->get('/admin/crm/leads/'.$mine->id.'/edit')->assertOk();
        Livewire::test(Index::class)->call('delete', $mine->id)->assertForbidden();
    }

    public function test_leads_of_unassigned_companies_stay_hidden_from_managers(): void
    {
        [$assigned, $hidden] = Company::factory()->count(2)->create();
        Lead::factory()->for($assigned)->create(['name' => 'Visible Lead']);
        $secret = Lead::factory()->for($hidden)->create(['name' => 'Hidden Lead']);
        $this->actingAs($this->userFor('sales-manager', $assigned));

        $this->get('/admin/crm/leads')->assertOk()->assertSee('Visible Lead')->assertDontSee('Hidden Lead');
        session([CompanyContext::SESSION_KEY => $hidden->id]);
        Livewire::test(Index::class)->assertSee('Visible Lead')->assertDontSee('Hidden Lead');
        $this->get('/admin/crm/leads/'.$secret->id)->assertNotFound();
        try {
            Livewire::test(Index::class)->call('delete', $secret->id);
            $this->fail('Deleting a lead of an unassigned company must fail.');
        } catch (ModelNotFoundException) {
            // Rendered as 404 over HTTP.
        }
        $this->assertModelExists($secret);
    }

    public function test_the_list_filters_by_follow_up_status_service_and_search(): void
    {
        $this->travelTo('2026-09-29 10:00:00');
        $company = Company::factory()->create();
        $service = CrmService::factory()->for($company)->create(['name' => 'Website']);
        Lead::factory()->for($company)->create(['name' => 'Due Today', 'next_call_on' => '2026-09-29', 'crm_service_id' => $service->id]);
        Lead::factory()->for($company)->create(['name' => 'Late One', 'next_call_on' => '2026-09-20', 'phone' => '01900111222']);
        Lead::factory()->for($company)->create(['name' => 'Won Deal', 'next_call_on' => '2026-09-20', 'crm_status_id' => $this->statusId($company, 'Closed won')]);
        $this->actingAs($this->userFor('sales-manager', $company));

        Livewire::test(Index::class)->set('followUp', 'today')->assertSee('Due Today')->assertDontSee('Late One')
            ->set('followUp', 'overdue')->assertSee('Late One')->assertDontSee('Won Deal')->assertDontSee('Due Today')
            ->set('followUp', '')->set('status', 'Closed won')->assertSee('Won Deal')->assertDontSee('Late One')
            ->set('status', '')->set('service', 'Website')->assertSee('Due Today')->assertDontSee('Won Deal')
            ->set('service', '')->set('search', '+880 1900-111222')->assertSee('Late One')->assertDontSee('Due Today');
    }

    public function test_a_closed_status_clears_the_follow_up_and_deleting_removes_the_calls(): void
    {
        $company = Company::factory()->create();
        $lead = Lead::factory()->for($company)->create(['next_call_on' => '2026-10-01']);
        $lead->calls()->create(['company_id' => $company->id, 'user_id' => User::factory()->create()->id, 'called_at' => now(), 'lead_status_id' => $lead->crm_status_id]);
        $this->actingAs($this->userFor('sales-manager', $company));

        Livewire::test(Form::class, ['lead' => $lead])->set('statusId', (string) $this->statusId($company, 'Closed lost'))->call('save')->assertHasNoErrors();
        $this->assertNull($lead->fresh()->next_call_on);

        Livewire::test(Index::class)->call('delete', $lead->id)->assertHasNoErrors();
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('lead_calls', 0);
    }

    public function test_the_lead_page_shows_the_call_history(): void
    {
        $company = Company::factory()->create();
        $lead = Lead::factory()->for($company)->create(['name' => 'History Lead']);
        $lead->calls()->create(['company_id' => $company->id, 'user_id' => User::factory()->create(['name' => 'Caller Person'])->id,
            'called_at' => now(), 'lead_status_id' => $lead->crm_status_id, 'summary' => 'Asked for the price list']);
        $this->actingAs($this->userFor('sales-manager', $company));

        Livewire::test(Show::class, ['lead' => $lead])->assertSee('History Lead')->assertSee('Asked for the price list')->assertSee('Caller Person');
    }

    public function test_import_adds_new_leads_and_skips_duplicates_and_invalid_rows(): void
    {
        $company = Company::factory()->create();
        CrmService::factory()->for($company)->create(['name' => 'Ecommerce']);
        Lead::factory()->for($company)->create(['phone' => '01711000000']);
        $manager = $this->userFor('sales-manager', $company);
        $rep = $this->userFor('sales', $company);
        $this->actingAs($manager);
        $csv = implode("\n", [
            'Name,Mobile,Email,Company,Service,Status,Next call,Remarks',
            'Existing,+880 1711-000000,,,,,,',
            'Fresh Lead,1811000000,fresh@example.com,Fresh Ltd,ecommerce,contacted,05/10/2026,Hot',
            'No Phone,,,,,,,',
            'Repeated,01811000000,,,,,,',
            'Won Already,01911000000,not-an-email,,Unknown,Closed won,2026-10-05,',
            ',,,,,,,',
        ]);

        Livewire::test(Import::class)->set('file', UploadedFile::fake()->createWithContent('leads.csv', $csv))
            ->set('assignTo', (string) $rep->id)->call('import')->assertHasNoErrors()
            ->assertSet('result', ['created' => 2, 'duplicates' => 2, 'invalid' => [4]]);

        $fresh = Lead::query()->where('phone', '01811000000')->sole();
        $this->assertSame(['Fresh Lead', 'fresh@example.com', 'Fresh Ltd', 'Ecommerce', 'Contacted', '2026-10-05', 'Hot', $rep->id],
            [$fresh->name, $fresh->email, $fresh->organization, $fresh->service->name, $fresh->status->name, $fresh->next_call_on->toDateString(), $fresh->notes, $fresh->assigned_to]);
        $won = Lead::query()->where('phone', '01911000000')->sole();
        $this->assertSame([null, null, 'Closed won', null], [$won->email, $won->crm_service_id, $won->status->name, $won->next_call_on]);
    }

    public function test_import_needs_a_phone_column_and_import_permission(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('sales-manager', $company));
        Livewire::test(Import::class)->set('file', UploadedFile::fake()->createWithContent('leads.csv', "Name,Email\nA,a@example.com"))
            ->call('import')->assertHasErrors('file');
        $this->assertDatabaseCount('leads', 0);

        $this->actingAs($this->userFor('sales', $company));
        Livewire::test(Import::class)->assertForbidden();
        $this->get('/admin/crm/leads/import-template')->assertForbidden();
    }
}
