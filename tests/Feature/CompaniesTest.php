<?php

namespace Tests\Feature;

use App\Livewire\Admin\Companies\Form;
use App\Livewire\Admin\Users\Form as UserForm;
use App\Models\Company;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CompaniesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_a_company_with_an_uppercase_code(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('name', 'Frish Traders')->set('code', ' ft01 ')->set('address', 'Dhaka')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.companies.index'));
        $this->assertDatabaseHas('companies', ['name' => 'Frish Traders', 'code' => 'FT01', 'address' => 'Dhaka', 'phone' => null, 'is_active' => true]);
    }

    public function test_company_code_is_unique_regardless_of_case(): void
    {
        Company::factory()->create(['code' => 'FT01']);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('name', 'Another')->set('code', 'ft01')->call('save')->assertHasErrors(['code' => 'unique']);
        $this->assertDatabaseCount('companies', 1);
    }

    #[TestWith(['F', 'between'])]
    #[TestWith(['ABCDEFGHIJK', 'between'])]
    #[TestWith(['FT-01', 'regex'])]
    public function test_company_code_must_be_two_to_ten_letters_or_digits(string $code, string $rule): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('name', 'Frish Traders')->set('code', $code)->call('save')->assertHasErrors(['code' => $rule]);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_companies_are_deactivated_instead_of_deleted(): void
    {
        $company = Company::factory()->create(['code' => 'FT01']);
        $this->actingAs(User::factory()->create(['role' => 'administrator']));
        Livewire::test(Form::class, ['company' => $company])->assertSet('code', 'FT01')->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($company->fresh()->is_active);
    }

    public function test_company_list_and_edit_are_limited_to_assigned_companies(): void
    {
        RolePermission::factory()->create(['role' => 'company_editor', 'permissions' => ['admin.access', 'companies.view', 'companies.update']]);
        [$assigned, $other] = Company::factory()->count(2)->sequence(['name' => 'Assigned Ltd'], ['name' => 'Hidden Ltd'])->create();
        $editor = User::factory()->create(['role' => 'company_editor']);
        $editor->companies()->attach($assigned);
        $this->actingAs($editor);

        $this->get('/admin/companies')->assertOk()->assertSee('Assigned Ltd')->assertDontSee('Hidden Ltd');
        $this->get('/admin/companies/'.$assigned->id.'/edit')->assertOk();
        $this->get('/admin/companies/'.$other->id.'/edit')->assertNotFound();
    }

    public function test_a_creator_without_all_company_access_is_assigned_to_the_new_company(): void
    {
        RolePermission::factory()->create(['role' => 'company_creator', 'permissions' => ['admin.access', 'companies.view', 'companies.create']]);
        $creator = User::factory()->create(['role' => 'company_creator']);
        $this->actingAs($creator);
        Livewire::test(Form::class)->set('name', 'New Branch')->set('code', 'NB')->call('save')->assertHasNoErrors();
        $this->assertSame([Company::sole()->id], $creator->accessibleCompanyIds());
    }

    public function test_accountant_cannot_create_or_edit_companies(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach($company);
        $this->actingAs($accountant);
        $this->get('/admin/companies')->assertOk();
        $this->get('/admin/companies/create')->assertForbidden();
        $this->get('/admin/companies/'.$company->id.'/edit')->assertForbidden();
    }

    public function test_user_company_assignment_is_limited_to_companies_the_actor_can_see(): void
    {
        [$visible, $alsoVisible, $hidden] = Company::factory()->count(3)->create();
        $admin = User::factory()->create(['role' => 'administrator', 'denied_permissions' => ['companies.all']]);
        $admin->companies()->attach([$visible->id, $alsoVisible->id]);
        $user = User::factory()->create(['role' => 'accountant']);
        $user->companies()->attach($visible);
        $this->actingAs($admin);

        Livewire::test(UserForm::class, ['user' => $user])->assertSet('companyIds', [(string) $visible->id])
            ->set('companyIds', [(string) $alsoVisible->id])->call('save')->assertHasNoErrors();
        $this->assertSame([$alsoVisible->id], $user->companies()->pluck('companies.id')->all());

        Livewire::test(UserForm::class, ['user' => $user])->set('companyIds', [(string) $hidden->id, 'x'])->call('save')
            ->assertHasErrors(['companyIds.0' => 'in', 'companyIds.1' => 'integer']);
        $this->assertSame([$alsoVisible->id], $user->companies()->pluck('companies.id')->all());
    }

    public function test_new_user_is_created_as_data_entry_with_assigned_companies_listed(): void
    {
        $company = Company::factory()->create(['name' => 'Frish Traders']);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(UserForm::class)->assertSet('role', 'data-entry')->set('name', 'Clerk')->set('email', 'clerk@example.test')
            ->set('password', 'StrongPass12345')->set('password_confirmation', 'StrongPass12345')
            ->set('companyIds', [(string) $company->id])->call('save')->assertHasNoErrors();
        $clerk = User::where('email', 'clerk@example.test')->sole();
        $this->assertSame('data-entry', $clerk->role);
        $this->assertSame([$company->id], $clerk->accessibleCompanyIds());
        $this->get('/admin/users')->assertOk()->assertSeeInOrder(['Clerk', 'Frish Traders']);
    }

    public function test_navigation_matches_each_role(): void
    {
        $company = Company::factory()->create();
        $links = ['companies.index', 'users.index', 'roles.index', 'settings'];
        $expected = [
            'owner' => $links,
            'administrator' => $links,
            'accountant' => ['companies.index', 'users.index'],
            'data-entry' => ['companies.index'],
        ];
        foreach ($expected as $role => $visible) {
            $user = User::factory()->create(['role' => $role]);
            $user->companies()->attach($company);
            $response = $this->actingAs($user)->get('/admin')->assertOk();
            foreach ($links as $link) {
                $href = 'href="'.route('admin.'.$link).'"';
                in_array($link, $visible, true) ? $response->assertSee($href, false) : $response->assertDontSee($href, false);
            }
        }
        $this->actingAs(User::factory()->create(['role' => 'member']))->get('/admin')->assertForbidden();
    }
}
