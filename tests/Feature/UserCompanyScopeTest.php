<?php

namespace Tests\Feature;

use App\Livewire\Admin\Users\Form;
use App\Models\Company;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $mine;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->mine, $this->other] = Company::factory()->count(2)->create();
        RolePermission::factory()->create(['role' => 'office_manager', 'permissions' => [
            'admin.access', 'users.view', 'users.update', ...config('permissions.roles.data-entry'),
        ]]);
        $manager = User::factory()->create(['role' => 'office_manager']);
        $manager->companies()->attach($this->mine);
        $this->actingAs($manager);
    }

    public function test_company_scoped_manager_cannot_list_open_or_save_accounts_outside_its_companies(): void
    {
        $otherAccountant = User::factory()->create(['role' => 'accountant', 'name' => 'Other Accountant', 'password' => 'OriginalPass123']);
        $otherAccountant->companies()->attach($this->other);
        $sharedAccountant = User::factory()->create(['role' => 'accountant', 'name' => 'Shared Accountant', 'password' => 'OriginalPass123']);
        $sharedAccountant->companies()->attach([$this->mine->id, $this->other->id]);
        $administrator = User::factory()->create(['role' => 'administrator', 'name' => 'Some Administrator', 'password' => 'OriginalPass123']);
        $suspendedAdministrator = User::factory()->create(['role' => 'administrator', 'is_active' => false, 'denied_permissions' => ['companies.all'], 'name' => 'Suspended Administrator']);

        $otherClerk = User::factory()->create(['role' => 'data-entry', 'name' => 'Other Clerk']);
        $otherClerk->companies()->attach($this->other);

        $this->get('/admin/users')->assertOk()->assertDontSee('Other Accountant')->assertDontSee('Shared Accountant')
            ->assertDontSee('Some Administrator')->assertDontSee('Suspended Administrator')->assertDontSee('Other Clerk');
        foreach ([$otherAccountant, $sharedAccountant, $administrator, $suspendedAdministrator, $otherClerk] as $target) {
            $this->get('/admin/users/'.$target->id.'/edit')->assertNotFound();
        }
        foreach ([$otherAccountant, $administrator] as $target) {
            $component = Livewire::actingAs(User::factory()->create(['role' => 'owner']))->test(Form::class, ['user' => $target]);
            $this->actingAs(User::where('role', 'office_manager')->sole());
            $component->set('password', 'NewPassword123')->set('password_confirmation', 'NewPassword123')->call('save')->assertNotFound();
            $this->assertTrue(Hash::check('OriginalPass123', $target->fresh()->password));
        }
    }

    public function test_company_scoped_manager_manages_accounts_of_its_own_companies(): void
    {
        $colleague = User::factory()->create(['role' => 'data-entry', 'name' => 'Local Clerk']);
        $colleague->companies()->attach($this->mine);
        $unassigned = User::factory()->create(['role' => 'data-entry', 'name' => 'New Starter']);

        $this->get('/admin/users')->assertOk()->assertSee('Local Clerk')->assertSee('New Starter');
        $this->get('/admin/users/'.$colleague->id.'/edit')->assertOk();
        Livewire::test(Form::class, ['user' => $colleague])->set('password', 'NewPassword123')->set('password_confirmation', 'NewPassword123')
            ->call('save')->assertHasNoErrors();
        $this->assertTrue(Hash::check('NewPassword123', $colleague->fresh()->password));
        Livewire::test(Form::class, ['user' => $unassigned])->set('companyIds', [(string) $this->mine->id])->call('save')->assertHasNoErrors();
        $this->assertSame([$this->mine->id], $unassigned->accessibleCompanyIds());
    }

    public function test_manager_cannot_take_over_a_more_powerful_account_in_its_own_company(): void
    {
        $accountant = User::factory()->create(['role' => 'accountant', 'name' => 'Local Accountant', 'password' => 'OriginalPass123']);
        $accountant->companies()->attach($this->mine);

        $this->get('/admin/users')->assertOk()->assertDontSee('Local Accountant');
        $this->get('/admin/users/'.$accountant->id.'/edit')->assertNotFound();
        $component = Livewire::actingAs(User::factory()->create(['role' => 'owner']))->test(Form::class, ['user' => $accountant]);
        $this->actingAs(User::where('role', 'office_manager')->sole());
        $component->set('password', 'NewPassword123')->set('password_confirmation', 'NewPassword123')->call('save')->assertNotFound();
        $this->assertTrue(Hash::check('OriginalPass123', $accountant->fresh()->password));
    }
}
