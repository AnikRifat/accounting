<?php

namespace App\Livewire\Admin\Users;

use App\Models\Employee;
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

    #[Locked]
    public bool $employee = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $isActive = true;

    public string $role = 'member';

    public array $extraRoles = [];

    public array $permissions = [];

    public string $employeeCode = '';

    public string $jobTitle = '';

    public string $department = '';

    public string $phone = '';

    public function module(): string
    {
        return $this->employee ? 'employees' : 'users';
    }

    public function mount(?User $user = null, bool $employee = false): void
    {
        $this->employee = $employee;
        $this->userId = $user?->exists ? $user->id : null;
        Gate::authorize($this->module().($this->userId ? '.update' : '.create'));
        if ($this->userId) {
            abort_if($user->isRoot(), 403, 'The root account cannot be edited here.');
            abort_if($employee && ! $user->employee, 404);
            $this->name = $user->name;
            $this->email = $user->email;
            $this->isActive = $user->is_active;
            $this->role = $user->role;
            $this->extraRoles = $user->extra_roles ?? [];
            $this->refreshPermissions();
            $this->employeeCode = $user->employee?->employee_code ?? '';
            $this->jobTitle = $user->employee?->job_title ?? '';
            $this->department = $user->employee?->department ?? '';
            $this->phone = $user->employee?->phone ?? '';
        } else {
            $this->role = $employee ? 'employee' : 'member';
            $this->refreshPermissions();
        }
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
        Gate::authorize($this->module().($this->userId ? '.update' : '.create'));
        $registry = app(Permissions::class);
        $existing = $this->userId ? User::findOrFail($this->userId) : null;
        abort_if($existing?->isRoot(), 403);
        abort_if($this->employee && $existing && ! $existing->employee, 404);
        $previousRole = $existing?->role ?? ($this->employee ? 'employee' : 'member');
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
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'password' => [$this->userId ? 'nullable' : 'required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->numbers()],
            'isActive' => ['boolean'], 'role' => ['required', Rule::in($allowedRoles)],
            'extraRoles' => ['array'], 'extraRoles.*' => ['string', 'distinct', Rule::in($allowedExtras)],
            'permissions' => ['array'], 'permissions.*' => ['string', 'distinct', Rule::in($registry->catalogue())],
        ];
        if ($this->employee) {
            $rules += [
                'employeeCode' => ['required', 'string', 'max:80', Rule::unique('employees', 'employee_code')->ignore($existing?->employee?->id)],
                'jobTitle' => ['nullable', 'string', 'max:255'], 'department' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            ];
        }
        $data = $this->validate($rules);
        if ($existing?->is(auth()->user())) {
            $this->addError('role', 'Ask another administrator to change your own access.');

            return null;
        }
        DB::transaction(function () use ($data, $existing, $registry): void {
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
            if ($this->employee) {
                Employee::updateOrCreate(['user_id' => $user->id], [
                    'employee_code' => $data['employeeCode'], 'job_title' => $data['jobTitle'] ?: null,
                    'department' => $data['department'] ?: null, 'phone' => $data['phone'] ?: null,
                ]);
            }
        });
        session()->flash('success', 'Account saved.');

        return redirect()->route('admin.'.$this->module().'.index');
    }

    public function render(): View
    {
        $registry = app(Permissions::class);

        return view('livewire.admin.users.form', ['registry' => $registry,
            'roleOptions' => collect($registry->assignableRoles())->mapWithKeys(fn (string $role): array => [$role => $registry->label($role).($registry->isActive($role) ? '' : ' (disabled)')])->all(),
            'ceiling' => $registry->roleCeiling($this->role, $this->extraRoles),
        ])->layout('layouts.admin');
    }
}
