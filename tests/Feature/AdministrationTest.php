<?php

namespace Tests\Feature;

use App\Livewire\Admin\Users\Form;
use App\Livewire\Auth\Login;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_requires_authentication_active_status_and_permission(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => false]))->get('/admin')->assertForbidden();
    }

    public function test_root_can_render_every_core_admin_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        foreach (['/admin', '/admin/users', '/admin/users/create', '/admin/roles', '/admin/roles/create', '/admin/settings', '/admin/media'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_admin_login_regenerates_access_and_disallows_member_accounts(): void
    {
        $root = User::factory()->create(['role' => 'owner', 'password' => 'StrongPass12345']);
        Livewire::test(Login::class)->set('email', $root->email)->set('password', 'StrongPass12345')->call('authenticate')->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($root);
        auth()->logout();
        $member = User::factory()->create(['password' => 'StrongPass12345']);
        Livewire::test(Login::class)->set('email', $member->email)->set('password', 'StrongPass12345')->call('authenticate')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_creation_persists_account_and_restrictions_together(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('name', 'Team Member')->set('email', 'team@example.test')
            ->set('password', 'StrongPass12345')->set('password_confirmation', 'StrongPass12345')
            ->set('permissions', ['admin.access', 'dashboard.view', 'entries.view'])->call('save')->assertHasNoErrors()->assertRedirect(route('admin.users.index'));
        $user = User::where('email', 'team@example.test')->sole();
        $this->assertSame('data-entry', $user->role);
        $this->assertFalse($user->hasPermission('entries.create'));
        $this->assertTrue($user->hasPermission('entries.view'));
    }

    public function test_root_is_hidden_and_cannot_be_edited_or_assigned(): void
    {
        $root = User::factory()->create(['role' => 'owner', 'name' => 'Protected Root']);
        $this->actingAs($root)->get('/admin/users')->assertDontSee('Protected Root</strong>', false);
        $this->get('/admin/users/'.$root->id.'/edit')->assertForbidden();
        Livewire::test(Form::class)->set('name', 'Forged')->set('email', 'forged@example.test')
            ->set('password', 'StrongPass12345')->set('password_confirmation', 'StrongPass12345')->set('role', 'owner')->call('save')->assertHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'forged@example.test']);
    }

    public function test_livewire_save_rechecks_permission_after_account_access_changes(): void
    {
        $actor = User::factory()->create(['role' => 'administrator']);
        $this->actingAs($actor);
        $component = Livewire::test(Form::class);
        $actor->forceFill(['role' => 'member'])->save();
        $component->call('save')->assertForbidden();
    }

    public function test_reactivating_an_account_preserves_its_permission_choices(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $user = User::factory()->create(['role' => 'accountant', 'is_active' => false, 'denied_permissions' => ['entries.void']]);

        Livewire::test(Form::class, ['user' => $user])->set('isActive', true)->call('save')->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertSame(['entries.void'], $user->denied_permissions);
        $this->assertTrue($user->hasPermission('dashboard.view'));
        $this->assertFalse($user->hasPermission('entries.void'));
    }

    public function test_account_creation_does_not_implicitly_allow_privileged_role_assignment(): void
    {
        RolePermission::factory()->create(['role' => 'account_creator', 'permissions' => ['admin.access', 'users.create']]);
        $this->actingAs(User::factory()->create(['role' => 'account_creator']));

        Livewire::test(Form::class)->set('role', 'administrator')->call('save')->assertForbidden();
        Livewire::test(Form::class)->set('extraRoles', ['administrator'])->call('save')->assertForbidden();

        Livewire::test(Form::class)->set('name', 'New member')->set('email', 'new-member@example.test')
            ->set('password', 'StrongPass12345')->set('password_confirmation', 'StrongPass12345')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new-member@example.test', 'role' => 'data-entry']);
    }

    public function test_account_update_cannot_change_roles_without_assignment_permission(): void
    {
        RolePermission::factory()->create(['role' => 'account_editor', 'permissions' => ['users.update', ...config('permissions.roles.data-entry')]]);
        $this->actingAs(User::factory()->create(['role' => 'account_editor']));
        $user = User::factory()->create(['role' => 'data-entry']);

        Livewire::test(Form::class, ['user' => $user])->set('extraRoles', ['administrator'])->call('save')->assertForbidden();
        $this->assertSame([], $user->fresh()->extra_roles);

        Livewire::test(Form::class, ['user' => $user])->set('name', 'Updated member')->call('save')->assertHasNoErrors();
        $this->assertSame('Updated member', $user->fresh()->name);
    }
}
