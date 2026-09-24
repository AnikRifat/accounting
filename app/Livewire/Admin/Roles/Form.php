<?php

namespace App\Livewire\Admin\Roles;

use App\Models\RolePermission;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Form extends Component
{
    #[Locked]
    public ?string $roleKey = null;

    public string $label = '';

    public bool $isActive = true;

    public array $permissions = [];

    public function mount(?string $role = null): void
    {
        $registry = app(Permissions::class);
        $this->roleKey = $role;
        Gate::authorize($role ? 'roles.update' : 'roles.create');
        if ($role) {
            abort_unless(in_array($role, $registry->assignableRoles(), true), 404);
            $this->label = $registry->label($role);
            $this->isActive = $registry->isActive($role);
            $this->permissions = $registry->forRole($role);
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->roleKey ? 'roles.update' : 'roles.create');
        $registry = app(Permissions::class);
        $this->validate(['label' => ['required', 'string', 'max:80'], 'isActive' => ['boolean'], 'permissions' => ['array'], 'permissions.*' => ['string', 'distinct', Rule::in($registry->catalogue())]]);
        $key = $this->roleKey ?? Str::slug(trim($this->label), '_');
        if ($this->roleKey === null && ($key === '' || $key === Permissions::ROOT_ROLE || in_array($key, $registry->assignableRoles(), true))) {
            $this->addError('label', 'Choose a unique name that does not match a built-in role.');

            return null;
        }
        abort_if($key === Permissions::ROOT_ROLE, 403);
        if ($this->roleKey && ! in_array($key, $registry->assignableRoles(), true)) {
            abort(404);
        }
        if ($registry->isCustom($key)) {
            Gate::authorize('permissions.manage');
            RolePermission::updateOrCreate(['role' => $key], ['label' => trim($this->label), 'permissions' => $registry->sanitise($this->permissions), 'is_active' => $this->isActive]);
        } else {
            if ($this->label !== $registry->label($key) || $this->permissions !== $registry->forRole($key)) {
                $this->addError('label', 'System roles have fixed names and permissions. Create a custom role instead.');

                return null;
            }
            RolePermission::firstOrCreate(['role' => $key], ['permissions' => []])->update(['is_active' => $this->isActive]);
        }
        $registry->flush();
        session()->flash('success', 'Role saved.');

        return redirect()->route('admin.roles.index');
    }

    public function render(): View
    {
        return view('livewire.admin.roles.form', ['registry' => app(Permissions::class)])->layout('layouts.admin');
    }
}
