<?php

namespace App\Livewire\Admin\Users;

use App\Models\Company;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Form extends Component
{
    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $isActive = true;

    public string $role = 'data-entry';

    public array $extraRoles = [];

    public array $permissions = [];

    public array $companyIds = [];

    public function mount(?User $user = null): void
    {
        $this->userId = $user?->exists ? $user->id : null;
        Gate::authorize($this->userId ? 'users.update' : 'users.create');
        if ($this->userId) {
            abort_if($user->isRoot(), 403, __('The root account cannot be edited here.'));
            abort_unless(ManageableUsers::for(auth()->user())->whereKey($user->id)->exists(), 404);
            $this->name = $user->name;
            $this->email = $user->email;
            $this->isActive = $user->is_active;
            $this->role = $user->role;
            $this->extraRoles = $user->extra_roles ?? [];
            $this->companyIds = $user->companies()->whereIn('companies.id', auth()->user()->accessibleCompanyIds())
                ->pluck('companies.id')->map(fn (int $id): string => (string) $id)->all();
        }
        $this->refreshPermissions();
    }

    public function updatedRole(): void
    {
        $this->refreshPermissions();
    }

    public function updatedExtraRoles(): void
    {
        $this->refreshPermissions();
    }

    private function refreshPermissions(): void
    {
        $denied = $this->userId ? User::findOrFail($this->userId)->denied_permissions ?? [] : [];
        $this->permissions = array_values(array_diff(app(Permissions::class)->roleCeiling($this->role, $this->extraRoles), $denied));
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->userId ? 'users.update' : 'users.create');
        $registry = app(Permissions::class);
        $existing = $this->userId ? User::findOrFail($this->userId) : null;
        abort_if($existing?->isRoot(), 403);
        abort_if($existing && ! ManageableUsers::for(auth()->user())->whereKey($existing->id)->exists(), 404);
        $previousRole = $existing?->role ?? 'data-entry';
        $previousExtras = $existing?->extra_roles ?? [];
        if ($this->role !== $previousRole || array_diff($this->extraRoles, $previousExtras) || array_diff($previousExtras, $this->extraRoles)) {
            Gate::authorize('roles.assign');
        }
        $this->email = strtolower(trim($this->email));
        $allowedRoles = $registry->enabledRoles();
        // A disabled role remains valid for its existing holder, but cannot be newly assigned.
        if ($existing && ! in_array($existing->role, $allowedRoles, true)) {
            $allowedRoles[] = $existing->role;
        }
        $allowedExtras = array_intersect($registry->systemRoles(), $registry->enabledRoles());
        $allowedExtras = array_unique([...$allowedExtras, ...($existing->extra_roles ?? [])]);
        $visibleCompanyIds = auth()->user()->accessibleCompanyIds();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'password' => [$this->userId ? 'nullable' : 'required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->numbers()],
            'isActive' => ['boolean'], 'role' => ['required', Rule::in($allowedRoles)],
            'extraRoles' => ['array'], 'extraRoles.*' => ['string', 'distinct', Rule::in($allowedExtras)],
            'permissions' => ['array'], 'permissions.*' => ['string', 'distinct', Rule::in($registry->catalogue())],
            'companyIds' => ['array'], 'companyIds.*' => ['integer', 'distinct', Rule::in($visibleCompanyIds)],
        ];
        $data = $this->validate($rules, [], ['companyIds.*' => __('company')]);
        if ($existing?->is(auth()->user())) {
            $this->addError('role', __('Ask another administrator to change your own access.'));

            return null;
        }
        DB::transaction(function () use ($data, $existing, $registry, $visibleCompanyIds): void {
            $user = $existing ?? new User;
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            if ($data['password'] !== '') {
                $user->password = $data['password'];
            }
            $user->forceFill(['is_active' => $data['isActive'], 'role' => $data['role'], 'extra_roles' => array_values(array_unique($data['extraRoles']))]);
            if (Gate::allows('permissions.manage')) {
                $user->denied_permissions = array_values(array_diff($registry->roleCeiling($data['role'], $data['extraRoles']), $data['permissions']));
            }
            $user->save();
            // Only assignments the actor can see are changed; the others are preserved.
            $preserved = $user->companies()->whereNotIn('companies.id', $visibleCompanyIds)->pluck('companies.id')->all();
            $user->companies()->sync([...$preserved, ...array_map('intval', $data['companyIds'])]);
        });
        session()->flash('success', __('Account saved.'));

        return redirect()->route('admin.users.index');
    }

    public function render(): View
    {
        $registry = app(Permissions::class);

        return view('livewire.admin.users.form', ['registry' => $registry,
            'roleOptions' => collect($registry->assignableRoles())->mapWithKeys(fn (string $role): array => [$role => $registry->label($role).($registry->isActive($role) ? '' : ' '.__('(disabled)'))])->all(),
            'ceiling' => $registry->roleCeiling($this->role, $this->extraRoles),
            'companies' => Company::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'code']),
        ])->layout('layouts.admin');
    }
}
