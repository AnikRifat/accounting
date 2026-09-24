<?php

namespace App\Livewire\Admin\Roles;

use App\Models\RolePermission;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Index extends Component
{
    public function delete(string $role): void
    {
        Gate::authorize('roles.delete');
        $registry = app(Permissions::class);
        abort_unless(in_array($role, $registry->assignableRoles(), true) && $registry->isCustom($role), 403);
        if ($registry->holders($role) > 0) {
            $this->addError('role', 'Move all users to another role before deleting this role.');

            return;
        }
        RolePermission::where('role', $role)->delete();
        $registry->flush();
        session()->flash('success', 'Custom role deleted.');
    }

    public function render(): View
    {
        Gate::authorize('roles.view');
        $registry = app(Permissions::class);

        return view('livewire.admin.roles.index', ['registry' => $registry, 'roles' => $registry->assignableRoles()])->layout('layouts.admin');
    }
}
