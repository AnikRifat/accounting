<?php

namespace App\Livewire\Admin\Users;

use App\Models\Company;
use App\Models\User;
use App\Support\Money;
use App\Support\Permissions;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/** Adds or edits an employee: every employee is a login account with staff details and a party in each assigned company. */
class Form extends Component
{
    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $isActive = true;

    public string $employeeCode = '';

    public string $designation = '';

    public string $department = '';

    public string $phone = '';

    public string $monthlySalary = '0.00';

    public string $joinedOn = '';

    public string $role = 'data-entry';

    public array $extraRoles = [];

    public array $permissions = [];

    public array $companyIds = [];

    public function mount(?User $user = null): void
    {
        $this->userId = $user?->exists ? $user->id : null;
        Gate::authorize($this->userId ? 'users.update' : 'users.create');
        if ($this->userId) {
            abort_if($user->isRoot(), 403, __('The super admin cannot be edited here.'));
            abort_unless(ManageableUsers::for(auth()->user())->whereKey($user->id)->exists(), 404);
            $this->name = $user->name;
            $this->email = $user->email;
            $this->isActive = $user->is_active;
            $this->employeeCode = $user->employee_code ?? '';
            $this->designation = $user->designation ?? '';
            $this->department = $user->department ?? '';
            $this->phone = $user->phone ?? '';
            $this->monthlySalary = Money::toInput($user->monthly_salary);
            $this->joinedOn = $user->joined_on?->toDateString() ?? '';
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
        foreach (['name', 'employeeCode', 'designation', 'department', 'phone', 'monthlySalary', 'joinedOn'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $allowedRoles = $registry->enabledRoles();
        // A disabled role remains valid for its existing holder, but cannot be newly assigned.
        if ($existing && ! in_array($existing->role, $allowedRoles, true)) {
            $allowedRoles[] = $existing->role;
        }
        $allowedExtras = array_intersect($registry->systemRoles(), $registry->enabledRoles());
        $allowedExtras = array_unique([...$allowedExtras, ...($existing->extra_roles ?? [])]);
        $visibleCompanyIds = auth()->user()->accessibleCompanyIds();
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'employeeCode' => ['nullable', 'string', 'max:30', Rule::unique('users', 'employee_code')->ignore($this->userId)],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'monthlySalary' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Money::isValidInput($value)) {
                    $fail(__('Enter an amount in taka with up to two decimals, e.g. 25,000.50.'));
                }
            }],
            'joinedOn' => ['nullable', 'date_format:Y-m-d'],
            'password' => [$this->userId ? 'nullable' : 'required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->numbers()],
            'isActive' => ['boolean'], 'role' => ['required', Rule::in($allowedRoles)],
            'extraRoles' => ['array'], 'extraRoles.*' => ['string', 'distinct', Rule::in($allowedExtras)],
            'permissions' => ['array'], 'permissions.*' => ['string', 'distinct', Rule::in($registry->catalogue())],
            'companyIds' => ['array'], 'companyIds.*' => ['integer', 'distinct', Rule::in($visibleCompanyIds)],
        ];
        $data = $this->validate($rules, [], ['companyIds.*' => __('company'), 'employeeCode' => __('employee code'),
            'monthlySalary' => __('monthly salary'), 'joinedOn' => __('joining date')]);
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
            $user->forceFill(['is_active' => $data['isActive'], 'role' => $data['role'], 'extra_roles' => array_values(array_unique($data['extraRoles'])),
                'employee_code' => $data['employeeCode'] ?: null, 'designation' => $data['designation'] ?: null, 'department' => $data['department'] ?: null,
                'phone' => $data['phone'] ?: null, 'monthly_salary' => Money::toPaisa($data['monthlySalary']), 'joined_on' => $data['joinedOn'] ?: null]);
            if (Gate::allows('permissions.manage')) {
                $user->denied_permissions = array_values(array_diff($registry->roleCeiling($data['role'], $data['extraRoles']), $data['permissions']));
            }
            $user->save();
            // Only assignments the actor can see are changed; the others are preserved.
            $preserved = $user->companies()->whereNotIn('companies.id', $visibleCompanyIds)->pluck('companies.id')->all();
            $user->companies()->sync([...$preserved, ...array_map('intval', $data['companyIds'])]);
            $user->syncParties();
        });
        session()->flash('success', __('Employee saved.'));

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
