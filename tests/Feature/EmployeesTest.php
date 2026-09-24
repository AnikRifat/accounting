<?php

namespace Tests\Feature;

use App\Livewire\Admin\Users\Form;
use App\Livewire\Admin\Users\Index;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Employees are users: every one signs in, carries staff details, and has a party in each assigned company. */
class EmployeesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret123456';

    private function employee(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));
        $user->syncParties();

        return $user;
    }

    public function test_employee_is_created_as_a_login_with_details_and_a_party_in_each_assigned_company(): void
    {
        [$first, $second, $unassigned] = Company::factory()->count(3)->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        Livewire::test(Form::class)->set('name', ' Rahim Uddin ')->set('email', ' Rahim@Example.com ')
            ->set('password', self::PASSWORD)->set('password_confirmation', self::PASSWORD)
            ->set('employeeCode', ' E-01 ')->set('designation', ' Driver ')->set('department', '   ')->set('phone', '01711-000000')
            ->set('monthlySalary', '25,000.50')->set('joinedOn', '2026-01-15')->set('companyIds', [(string) $first->id, (string) $second->id])
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.users.index'));

        $employee = User::where('email', 'rahim@example.com')->sole();
        $this->assertSame(['Rahim Uddin', 'E-01', 'Driver', null, '01711-000000', 25_000_50, '2026-01-15', 'data-entry'],
            [$employee->name, $employee->employee_code, $employee->designation, $employee->department, $employee->phone,
                $employee->monthly_salary, $employee->joined_on->toDateString(), $employee->role]);
        $this->assertTrue(Hash::check(self::PASSWORD, $employee->password));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $employee->parties()->pluck('company_id')->all());
        foreach ($employee->parties as $party) {
            $this->assertSame(['Rahim Uddin', '01711-000000', true], [$party->name, $party->phone, $party->is_active]);
        }
        $this->assertFalse(Party::where('company_id', $unassigned->id)->exists());
        $this->get('/admin/users')->assertOk()->assertSee('Rahim Uddin')->assertSee('৳25,000.50');
    }

    public function test_login_details_are_required_and_salary_and_code_are_validated(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        User::factory()->create()->forceFill(['employee_code' => 'E-01'])->save();

        Livewire::test(Form::class)->set('monthlySalary', '25000.505')->set('employeeCode', 'E-01')->call('save')
            ->assertHasErrors(['name' => 'required', 'email' => 'required', 'password' => 'required', 'employeeCode' => 'unique'])
            ->assertSee('Enter an amount in taka with up to two decimals, e.g. 25,000.50.');
        $this->assertSame(2, User::count());
    }

    public function test_parties_follow_the_employee_and_are_deactivated_not_deleted_when_a_company_is_removed(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $employee = $this->employee('data-entry', $first, $second);
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        Livewire::test(Form::class, ['user' => $employee])->assertSet('monthlySalary', '0.00')
            ->set('name', 'Renamed')->set('phone', '01800-111111')->set('monthlySalary', '18000')->set('companyIds', [(string) $first->id])
            ->call('save')->assertHasNoErrors();
        $parties = $employee->parties()->get()->keyBy('company_id');
        $this->assertCount(2, $parties);
        $this->assertSame(['Renamed', '01800-111111', true], [$parties[$first->id]->name, $parties[$first->id]->phone, $parties[$first->id]->is_active]);
        $this->assertFalse($parties[$second->id]->is_active);
        $this->assertSame(18_000_00, $employee->fresh()->monthly_salary);

        Livewire::test(Form::class, ['user' => $employee])->set('companyIds', [(string) $first->id, (string) $second->id])->call('save')->assertHasNoErrors();
        $this->assertSame([true, true], $employee->parties()->orderBy('company_id')->pluck('is_active')->all());
        $this->assertSame(2, Party::count());

        Livewire::test(Form::class, ['user' => $employee])->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertSame([false, false], $employee->parties()->pluck('is_active')->all());
    }

    public function test_the_super_admin_has_no_party_and_is_not_editable_here(): void
    {
        $company = Company::factory()->create();
        $root = User::factory()->create(['role' => 'owner']);
        $root->companies()->attach($company);
        $root->syncParties();
        $this->assertSame(0, Party::count());

        $this->actingAs($this->employee('administrator'));
        $this->get('/admin/users')->assertOk()->assertDontSee($root->email);
        $this->get('/admin/users/'.$root->id.'/edit')->assertForbidden();
    }

    public function test_accountant_manages_employees_of_its_companies_and_creates_them_without_raising_roles(): void
    {
        [$assigned, $other] = Company::factory()->count(2)->create();
        $mine = $this->employee('data-entry', $assigned);
        $hidden = $this->employee('data-entry', $other);
        $accountant = $this->employee('accountant', $assigned);
        $this->actingAs($accountant);

        $this->get('/admin/users')->assertOk()->assertSee($mine->name)->assertDontSee($hidden->name);
        $this->get('/admin/users/'.$mine->id.'/edit')->assertOk();
        $this->get('/admin/users/'.$hidden->id.'/edit')->assertNotFound();

        $create = fn () => Livewire::test(Form::class)->set('name', 'New Clerk')->set('email', 'clerk@example.com')
            ->set('password', self::PASSWORD)->set('password_confirmation', self::PASSWORD);
        $create()->set('companyIds', [(string) $other->id])->call('save')->assertHasErrors('companyIds.0');
        $create()->set('role', 'administrator')->set('companyIds', [(string) $assigned->id])->call('save')->assertForbidden();
        $create()->set('companyIds', [(string) $assigned->id])->call('save')->assertHasNoErrors();
        $this->assertSame([$assigned->id], User::where('email', 'clerk@example.com')->sole()->parties()->pluck('company_id')->all());
    }

    public function test_salary_is_shown_only_to_those_who_can_edit_employees(): void
    {
        $company = Company::factory()->create();
        $employee = $this->employee('data-entry', $company);
        $employee->forceFill(['monthly_salary' => 42_000_00])->save();

        $this->actingAs($this->employee('accountant', $company));
        $this->get('/admin/users')->assertOk()->assertSee($employee->name)->assertSee('৳42,000.00');

        $viewer = $this->employee('accountant', $company);
        $viewer->forceFill(['denied_permissions' => ['users.update']])->save();
        $this->actingAs($viewer->fresh());
        $this->get('/admin/users')->assertOk()->assertSee($employee->name)->assertDontSee('৳42,000.00')->assertDontSee('Monthly salary');
    }

    public function test_header_company_narrows_the_list_to_its_and_unassigned_employees_and_data_entry_cannot_open_it(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $inFirst = $this->employee('data-entry', $first);
        $inSecond = $this->employee('data-entry', $second);
        $newStarter = $this->employee('data-entry');
        $this->actingAs($this->employee('administrator'));

        Livewire::test(Index::class)->assertSee($inFirst->name)->assertSee($inSecond->name);
        session([CompanyContext::SESSION_KEY => $second->id]);
        Livewire::test(Index::class)->assertDontSee($inFirst->name)->assertSee($inSecond->name)->assertSee($newStarter->name)
            ->set('status', 'inactive')->assertDontSee($inSecond->name);

        $this->actingAs($inFirst);
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/users/create')->assertForbidden();
    }

    public function test_admin_pages_render_for_the_super_admin_and_accountant(): void
    {
        $company = Company::factory()->create();
        $member = $this->employee('data-entry', $company);

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        foreach (['/admin/companies', '/admin/companies/'.$company->id.'/edit', '/admin/users', '/admin/users/create', '/admin/users/'.$member->id.'/edit'] as $path) {
            $this->get($path)->assertOk();
        }

        $this->actingAs($this->employee('accountant', $company));
        foreach (['/admin/companies', '/admin/users', '/admin/users/create', '/admin/users/'.$member->id.'/edit'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/admin/employees')->assertNotFound();
    }
}
