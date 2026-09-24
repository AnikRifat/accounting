<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\Form;
use App\Livewire\Admin\Employees\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_is_created_with_salary_stored_in_paisa(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('employeeCode', ' E-01 ')->set('name', 'Rahim Uddin')
            ->set('designation', 'Driver')->set('monthlySalary', '25,000.50')->set('joinedOn', '2026-01-15')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.employees.index'));
        $employee = Employee::sole();
        $this->assertSame($company->id, $employee->company_id);
        $this->assertSame('E-01', $employee->employee_code);
        $this->assertSame(2500050, $employee->monthly_salary);
        $this->assertSame('2026-01-15', $employee->joined_on->toDateString());
        $this->assertTrue($employee->is_active);
        $this->get('/admin/employees')->assertOk()->assertSee('Rahim Uddin')->assertSee('৳25,000.50');
    }

    public function test_required_fields_and_salary_format_are_validated(): void
    {
        Company::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('monthlySalary', '25000.505')->call('save')
            ->assertHasErrors(['employeeCode' => 'required', 'name' => 'required'])
            ->assertSee('Enter an amount in taka with up to two decimals, e.g. 25,000.50.');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_employee_code_is_unique_per_company(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        Employee::factory()->for($first)->create(['employee_code' => 'E-01']);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        session([CompanyContext::SESSION_KEY => $first->id]);
        Livewire::test(Form::class)->set('employeeCode', 'E-01')->set('name', 'Duplicate')
            ->call('save')->assertHasErrors(['employeeCode' => 'unique']);
        session([CompanyContext::SESSION_KEY => $second->id]);
        Livewire::test(Form::class)->set('employeeCode', 'E-01')->set('name', 'Other company')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(1, Employee::where('company_id', $second->id)->count());
    }

    public function test_editing_shows_salary_in_taka_and_keeps_the_company(): void
    {
        [$company, $other] = Company::factory()->count(2)->create();
        $employee = Employee::factory()->for($company)->create(['monthly_salary' => 1500000]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class, ['employee' => $employee])->assertSet('monthlySalary', '15000.00')
            ->set('monthlySalary', '18000')->set('isActive', false)->call('save')->assertHasNoErrors();
        $employee->refresh();
        $this->assertSame($company->id, $employee->company_id);
        $this->assertSame(1800000, $employee->monthly_salary);
        $this->assertFalse($employee->is_active);
    }

    public function test_accountant_only_lists_and_opens_employees_of_assigned_companies(): void
    {
        [$assigned, $other] = Company::factory()->count(2)->create();
        $mine = Employee::factory()->for($assigned)->create(['name' => 'Assigned Worker']);
        $hidden = Employee::factory()->for($other)->create(['name' => 'Hidden Worker']);
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach($assigned);
        $this->actingAs($accountant);

        $this->get('/admin/employees')->assertOk()->assertSee('Assigned Worker')->assertDontSee('Hidden Worker')->assertDontSee($other->name);
        session([CompanyContext::SESSION_KEY => $other->id]);
        Livewire::test(Index::class)->assertSee('Assigned Worker')->assertDontSee('Hidden Worker');
        $this->get('/admin/employees/'.$mine->id.'/edit')->assertOk();
        $this->get('/admin/employees/'.$hidden->id.'/edit')->assertNotFound();
    }

    public function test_accountant_creates_employees_in_its_pinned_company_and_cannot_move_them(): void
    {
        [$assigned, $other] = Company::factory()->count(2)->create();
        $employee = Employee::factory()->for($assigned)->create();
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach($assigned);
        $this->actingAs($accountant);

        session([CompanyContext::SESSION_KEY => $other->id]);
        Livewire::test(Form::class)->assertSet('companyId', $assigned->id)->set('employeeCode', 'X-1')->set('name', 'Mine')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('employees', ['name' => 'Mine', 'company_id' => $assigned->id]);

        Livewire::test(Form::class, ['employee' => $employee])->call('save')->assertHasNoErrors();
        $this->assertSame($assigned->id, $employee->fresh()->company_id);
    }

    public function test_text_inputs_are_trimmed_and_joining_date_is_stored_as_a_plain_date(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('employeeCode', 'E-02')->set('name', '  Karim  ')
            ->set('designation', ' Clerk ')->set('department', '   ')->set('monthlySalary', ' 100 ')->set('joinedOn', '2026-03-01')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('employees', ['name' => 'Karim', 'designation' => 'Clerk', 'department' => null, 'monthly_salary' => 10000, 'joined_on' => '2026-03-01']);
    }

    public function test_data_entry_can_view_but_not_manage_employees(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();
        $user = User::factory()->create(['role' => 'data-entry']);
        $user->companies()->attach($company);
        $this->actingAs($user);
        $this->get('/admin/employees')->assertOk()->assertDontSee(route('admin.employees.create'));
        $this->get('/admin/employees/create')->assertForbidden();
        $this->get('/admin/employees/'.$employee->id.'/edit')->assertForbidden();
    }

    public function test_owned_admin_pages_render_for_owner_and_accountant(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();
        $member = User::factory()->create(['role' => 'accountant']);
        $member->companies()->attach($company);

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        foreach (['/admin/companies', '/admin/companies/create', '/admin/companies/'.$company->id.'/edit', '/admin/employees', '/admin/employees/create',
            '/admin/employees/'.$employee->id.'/edit', '/admin/users', '/admin/users/create', '/admin/users/'.$member->id.'/edit'] as $path) {
            $this->get($path)->assertOk();
        }

        $this->actingAs($member);
        foreach (['/admin/companies', '/admin/employees', '/admin/employees/create', '/admin/employees/'.$employee->id.'/edit'] as $path) {
            $this->get($path)->assertOk();
        }
        foreach (['/admin/companies/create', '/admin/users', '/admin/users/create'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }
}
