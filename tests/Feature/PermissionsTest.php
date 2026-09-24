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
        $user = User::factory()->create(['role' => 'reviewer', 'extra_roles' => ['employee'], 'denied_permissions' => ['media.upload']]);
        $this->assertTrue(Gate::forUser($user)->allows('users.view'));
        $this->assertTrue(Gate::forUser($user)->allows('dashboard.view'));
        $this->assertFalse(Gate::forUser($user)->allows('media.upload'));
        $this->assertFalse(Gate::forUser($user)->allows('users.update'));
    }

    public function test_custom_roles_cannot_be_stacked_and_unknown_abilities_never_grant_access(): void
    {
        RolePermission::factory()->create(['role' => 'rogue', 'permissions' => ['users.update', 'unknown.ability']]);
        $user = User::factory()->create(['extra_roles' => ['rogue']]);
        $this->assertFalse($user->hasPermission('users.update'));
        $this->assertFalse($user->hasPermission('unknown.ability'));
    }

    public function test_root_is_immune_to_denials_and_system_role_database_overlays(): void
    {
        RolePermission::factory()->create(['role' => 'owner', 'permissions' => [], 'is_active' => false]);
        $root = User::factory()->create(['role' => 'owner', 'denied_permissions' => ['users.update']]);
        $this->assertTrue($root->hasPermission('users.update'));
        $admin = User::factory()->create(['role' => 'administrator']);
        RolePermission::factory()->create(['role' => 'administrator', 'permissions' => []]);
        app(Permissions::class)->flush();
        $this->assertTrue($admin->hasPermission('users.update'));
        $this->assertNotContains('owner', app(Permissions::class)->assignableRoles());
    }

    public function test_custom_role_creation_rejects_system_names_and_wildcard_payloads(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class)->set('label', 'Administrator')->call('save')->assertHasErrors('label');
        Livewire::test(Form::class)->set('label', 'Reviewer')->set('permissions', ['*'])->call('save')->assertHasErrors('permissions.0');
        Livewire::test(Form::class)->set('label', 'Reviewer')->set('permissions', ['users.view'])->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('role_permissions', ['role' => 'reviewer', 'label' => 'Reviewer']);
    }

    public function test_system_roles_are_immutable_but_can_be_disabled_without_revoking_existing_holders(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Form::class, ['role' => 'employee'])->set('permissions', [])->call('save')->assertHasErrors('label');
        Livewire::test(Form::class, ['role' => 'employee'])->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertNotContains('employee', app(Permissions::class)->enabledRoles());
        $existingHolder = User::factory()->create(['role' => 'employee']);
        $this->assertTrue($existingHolder->hasPermission('dashboard.view'));
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
