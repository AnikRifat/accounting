<?php

namespace Tests\Feature;

use App\Livewire\Admin\Roles\Form;
use App\Livewire\Admin\Roles\Index;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_and_extra_system_roles_form_a_union_minus_personal_denials(): void
    {
        RolePermission::factory()->create(['role' => 'reviewer', 'permissions' => ['users.view']]);
        $user = User::factory()->create(['role' => 'reviewer', 'extra_roles' => ['data-entry'], 'denied_permissions' => ['entries.create']]);
        $this->assertTrue(Gate::forUser($user)->allows('users.view'));
        $this->assertTrue(Gate::forUser($user)->allows('dashboard.view'));
        $this->assertFalse(Gate::forUser($user)->allows('entries.create'));
        $this->assertFalse(Gate::forUser($user)->allows('users.update'));
    }

    public function test_custom_roles_cannot_be_stacked_and_unknown_abilities_never_grant_access(): void
    {
        RolePermission::factory()->create(['role' => 'rogue', 'permissions' => ['users.update', 'unknown.ability']]);
        $user = User::factory()->create(['extra_roles' => ['rogue']]);
        $this->assertFalse($user->hasPermission('users.update'));
        $this->assertFalse($user->hasPermission('unknown.ability'));
    }

    public function test_the_super_admin_skips_every_check_while_administrator_is_an_ordinary_role(): void
    {
        RolePermission::factory()->create(['role' => 'owner', 'permissions' => [], 'is_active' => false]);
        $root = User::factory()->create(['role' => 'owner', 'denied_permissions' => ['users.update']]);
        $this->assertTrue($root->hasPermission('users.update'));
        $this->assertTrue(Gate::forUser($root)->allows('an.ability.outside.the.catalogue'));
        $root->forceFill(['is_active' => false])->save();
        $this->assertFalse(Gate::forUser($root)->allows('users.update'));
        $this->assertSame('Super admin', app(Permissions::class)->label('owner'));
        $this->assertNotContains('owner', app(Permissions::class)->assignableRoles());

        $admin = User::factory()->create(['role' => 'administrator']);
        $this->assertTrue($admin->hasPermission('users.update'));
        $this->assertFalse(Gate::forUser($admin)->allows('an.ability.outside.the.catalogue'));
        RolePermission::factory()->create(['role' => 'administrator', 'permissions' => ['admin.access', 'dashboard.view']]);
        $this->assertFalse($admin->hasPermission('users.update'));
        $this->assertTrue($admin->hasPermission('dashboard.view'));
    }

    public function test_only_one_super_admin_can_be_created(): void
    {
        User::factory()->create(['role' => 'owner']);
        $this->artisan('app:create-admin')->expectsOutputToContain('A super admin already exists.')->assertFailed();
        $this->assertSame(1, User::where('role', 'owner')->count());
    }

    public function test_custom_role_creation_rejects_system_names_and_wildcard_payloads(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('label', 'Administrator')->call('save')->assertHasErrors('label');
        Livewire::test(Form::class)->set('label', 'Reviewer')->set('permissions', ['*'])->call('save')->assertHasErrors('permissions.0');
        Livewire::test(Form::class)->set('label', 'Reviewer')->set('permissions', ['users.view'])->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('role_permissions', ['role' => 'reviewer', 'label' => 'Reviewer']);
    }

    public function test_system_roles_keep_their_names_but_their_abilities_can_change_and_they_can_be_disabled(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $holder = User::factory()->create(['role' => 'data-entry']);
        Livewire::test(Form::class, ['role' => 'data-entry'])->set('label', 'Clerk')->call('save')->assertHasErrors('label');
        Livewire::test(Form::class, ['role' => 'data-entry'])->set('permissions', ['dashboard.view', 'admin.access'])->call('save')->assertHasNoErrors();
        $this->assertSame(['admin.access', 'dashboard.view'], app(Permissions::class)->forRole('data-entry'));
        $this->assertFalse($holder->hasPermission('entries.create'));

        Livewire::test(Form::class, ['role' => 'data-entry'])->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertNotContains('data-entry', app(Permissions::class)->enabledRoles());
        $this->assertTrue($holder->hasPermission('dashboard.view'));
        $this->assertSame(['admin.access', 'dashboard.view'], app(Permissions::class)->forRole('data-entry'));

        Livewire::test(Form::class, ['role' => 'accountant'])->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertContains('accounts.manage', app(Permissions::class)->forRole('accountant'));

        $admin = User::factory()->create(['role' => 'administrator', 'denied_permissions' => ['permissions.manage']]);
        $this->actingAs($admin);
        Livewire::test(Form::class, ['role' => 'accountant'])->set('permissions', ['dashboard.view'])->call('save')->assertForbidden();
        $this->assertContains('accounts.manage', app(Permissions::class)->forRole('accountant'));
    }

    public function test_custom_roles_cannot_be_deleted_while_assigned_and_system_roles_cannot_be_deleted(): void
    {
        RolePermission::factory()->create(['role' => 'reviewer']);
        $holder = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Index::class)->call('delete', 'reviewer')->assertHasErrors('role');
        $this->assertDatabaseHas('role_permissions', ['role' => 'reviewer']);
        $holder->forceFill(['role' => 'member'])->save();
        Livewire::test(Index::class)->call('delete', 'reviewer')->assertHasNoErrors();
        $this->assertDatabaseMissing('role_permissions', ['role' => 'reviewer']);
        Livewire::test(Index::class)->call('delete', 'administrator')->assertForbidden();
    }
}
