<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\Form as EmployeeForm;
use App\Livewire\Admin\Parties\Form;
use App\Livewire\Admin\Parties\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Party;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class PartiesTest extends TestCase
{
    use RefreshDatabase;

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    public function test_custom_party_is_created_with_trimmed_fields(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $company));
        Livewire::test(Form::class)->assertSet('companyId', $company->id)->set('name', '  Karim Traders ')->set('phone', ' 01711000000 ')
            ->set('address', 'Motijheel, Dhaka')->set('notes', '   ')->call('save')->assertHasNoErrors()->assertRedirect(route('admin.parties.index'));
        $party = Party::sole();
        $this->assertSame([$company->id, 'Karim Traders', '01711000000', 'Motijheel, Dhaka', null, true, false],
            [$party->company_id, $party->name, $party->phone, $party->address, $party->notes, $party->is_active, $party->isEmployee()]);
    }

    public function test_party_name_is_required_and_limited(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $company));
        Livewire::test(Form::class)->set('name', '')->call('save')->assertHasErrors(['name' => 'required']);
        Livewire::test(Form::class)->set('name', str_repeat('a', 151))->call('save')->assertHasErrors(['name' => 'max']);
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_create_asks_for_an_active_company_while_the_header_is_on_all_or_inactive(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $inactive = Company::factory()->create(['is_active' => false]);
        $this->actingAs($this->userFor('accountant', $first, $second, $inactive));
        $choose = route('admin.choose-company', ['next' => '/admin/parties/create']);

        $this->get('/admin/parties/create')->assertRedirect($choose);
        session([CompanyContext::SESSION_KEY => $inactive->id]);
        $this->get('/admin/parties/create')->assertRedirect($choose);
        session([CompanyContext::SESSION_KEY => $second->id]);
        $this->get('/admin/parties/create')->assertOk()->assertSee($second->name);
    }

    public function test_new_parties_go_to_the_header_company_and_crafted_values_cannot_move_them(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $hidden = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $first, $second));

        // A crafted session value for an invisible company falls back to All, so the create page asks for a company.
        session([CompanyContext::SESSION_KEY => $hidden->id]);
        $this->get('/admin/parties/create')->assertRedirect(route('admin.choose-company', ['next' => '/admin/parties/create']));

        session([CompanyContext::SESSION_KEY => $second->id]);
        $component = Livewire::test(Form::class)->assertSet('companyId', $second->id);
        try {
            $component->set('companyId', $hidden->id);
            $this->fail('The company of the form must not be settable from the client.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        $component->set('name', 'Supplier')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('parties', ['name' => 'Supplier', 'company_id' => $second->id]);
    }

    public function test_save_fails_when_the_header_company_changed_or_became_inactive(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs($this->userFor('accountant', $first, $second));
        session([CompanyContext::SESSION_KEY => $first->id]);
        $component = Livewire::test(Form::class)->set('name', 'Late Supplier');

        session([CompanyContext::SESSION_KEY => $second->id]);
        $component->call('save')->assertHasErrors('company');
        session([CompanyContext::SESSION_KEY => $first->id]);
        $first->update(['is_active' => false]);
        $component->call('save')->assertHasErrors('company');
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_company_column_shows_only_on_all_companies(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        Party::factory()->for($first)->create(['name' => 'First Customer']);
        Party::factory()->for($second)->create(['name' => 'Second Customer']);
        $this->actingAs($this->userFor('accountant', $first, $second));

        $this->get('/admin/parties')->assertOk()->assertSeeInOrder(['<th>'.__('Company').'</th>', 'First Customer', $first->name], false)->assertSee('Second Customer');
        session([CompanyContext::SESSION_KEY => $second->id]);
        $this->get('/admin/parties')->assertOk()->assertSee('Second Customer')->assertDontSee('First Customer')->assertDontSee('<th>'.__('Company').'</th>', false);
    }

    public function test_listing_and_editing_are_limited_to_assigned_companies(): void
    {
        [$assigned, $hidden] = Company::factory()->count(2)->create();
        $mine = Party::factory()->for($assigned)->create(['name' => 'Visible Supplier']);
        $other = Party::factory()->for($hidden)->create(['name' => 'Hidden Supplier']);
        $this->actingAs($this->userFor('accountant', $assigned));

        $this->get('/admin/parties')->assertOk()->assertSee('Visible Supplier')->assertDontSee('Hidden Supplier');
        session([CompanyContext::SESSION_KEY => $hidden->id]);
        Livewire::test(Index::class)->assertSee('Visible Supplier')->assertDontSee('Hidden Supplier');
        $this->get('/admin/parties/'.$mine->id.'/edit')->assertOk();
        $this->get('/admin/parties/'.$other->id.'/edit')->assertNotFound();

        Livewire::test(Form::class, ['party' => $mine])->set('name', 'Renamed')->set('isActive', false)
            ->call('save')->assertHasNoErrors();
        $this->assertSame([$assigned->id, 'Renamed', false], [$mine->fresh()->company_id, $mine->fresh()->name, $mine->fresh()->is_active]);
    }

    public function test_save_rechecks_company_access(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create(['name' => 'Original']);
        $accountant = $this->userFor('accountant', $company);
        $this->actingAs($accountant);
        $component = Livewire::test(Form::class, ['party' => $party]);
        $accountant->companies()->detach();
        try {
            $component->set('name', 'Changed')->call('save');
            $this->fail('Saving a party of an unassigned company must fail.');
        } catch (ModelNotFoundException) {
            // Rendered as 404 over HTTP.
        }
        $this->assertSame('Original', $party->fresh()->name);
    }

    public function test_index_filters_by_type_status_and_search(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create(['name' => 'Rahim Employee']);
        Party::factory()->for($company)->create(['name' => 'Active Customer', 'phone' => '01811222333']);
        Party::factory()->for($company)->create(['name' => 'Dormant Customer', 'is_active' => false]);
        $this->actingAs($this->userFor('accountant', $company));

        Livewire::test(Index::class)->set('kind', 'employee')->assertSee('Rahim Employee')->assertDontSee('Active Customer')
            ->set('kind', 'custom')->assertSee('Active Customer')->assertDontSee('Rahim Employee')
            ->set('status', 'inactive')->assertSee('Dormant Customer')->assertDontSee('Active Customer')
            ->set('status', '')->set('search', '01811')->assertSee('Active Customer')->assertDontSee('Dormant Customer');
    }

    public function test_employee_creation_and_updates_sync_to_its_party(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $company));
        Livewire::test(EmployeeForm::class)->set('employeeCode', 'E-01')->set('name', 'Rahim Uddin')->set('phone', '01700000000')
            ->set('designation', 'Driver')->call('save')->assertHasNoErrors();
        $employee = Employee::sole();
        $party = $employee->party;
        $this->assertSame([$company->id, 'Rahim Uddin', '01700000000', true], [$party->company_id, $party->name, $party->phone, $party->is_active]);

        Livewire::test(EmployeeForm::class, ['employee' => $employee])->set('name', 'Rahim Mia')->set('phone', '')->set('isActive', false)
            ->call('save')->assertHasNoErrors();
        $party->refresh();
        $this->assertSame(['Rahim Mia', null, false], [$party->name, $party->phone, $party->is_active]);
        $this->assertDatabaseCount('parties', 1);
    }

    public function test_employee_parties_are_edited_through_the_employee_only(): void
    {
        $company = Company::factory()->create();
        $party = Employee::factory()->for($company)->create(['name' => 'Staff Member'])->party;
        $this->actingAs($this->userFor('accountant', $company));

        $this->get('/admin/parties')->assertOk()->assertSee(route('admin.employees.edit', $party->employee_id))->assertDontSee(route('admin.parties.edit', $party));
        $this->get('/admin/parties/'.$party->id.'/edit')->assertNotFound();
    }

    public function test_data_entry_can_view_and_create_but_not_update_parties(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create();
        $this->actingAs($this->userFor('data-entry', $company));

        $this->get('/admin/parties')->assertOk()->assertSee(route('admin.parties.create'))->assertDontSee(route('admin.parties.edit', $party));
        $this->get('/admin/parties/'.$party->id.'/edit')->assertForbidden();
        Livewire::test(Form::class)->set('name', 'Walk-in Customer')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('parties', ['company_id' => $company->id, 'name' => 'Walk-in Customer']);
    }
}
