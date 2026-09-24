<?php

namespace App\Livewire\Admin\Users;

use App\Livewire\Concerns\WithFormSheet;
use App\Livewire\Concerns\WithTableTools;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Permissions;
use App\Support\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/** Employees the actor may manage; with one company in the header, those assigned to it and those not assigned to any company yet. */
class Index extends Component
{
    use WithFormSheet, WithPagination, WithTableTools;

    public string $search = '';

    public string $status = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        Gate::authorize('users.view');

        return view('livewire.admin.users.index', [
            'users' => $this->tableQuery()->paginate(15),
            'permissions' => app(Permissions::class),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.users.index';
    }

    /** @return Builder<User> the accounts this user may manage, filtered like the list */
    protected function tableQuery(): Builder
    {
        $actor = auth()->user();
        $context = app(CompanyContext::class);
        $search = mb_substr(trim($this->search), 0, 100);

        return ManageableUsers::for($actor)->with(['companies' => fn ($query) => $query->visibleTo($actor)->orderBy('name')])
            ->when(! $context->isAll(), fn ($query) => $query->where(fn ($q) => $q->whereHas('companies', fn ($companies) => $companies->whereKey($context->selectedId()))
                ->orWhereDoesntHave('companies')))
            ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('employee_code', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')))
            ->orderBy('name');
    }

    /** Salary is exported only to those who may edit employees, as on the list. */
    protected function tableExport(): TableExport
    {
        $permissions = app(Permissions::class);

        return new TableExport(__('Employees'), array_filter([
            'name' => ['label' => __('Name'), 'value' => fn (User $user): string => $user->name],
            'email' => ['label' => __('Email address'), 'value' => fn (User $user): string => $user->email],
            'code' => ['label' => __('Employee code'), 'value' => fn (User $user): ?string => $user->employee_code],
            'phone' => ['label' => __('Phone'), 'value' => fn (User $user): ?string => $user->phone],
            'designation' => ['label' => __('Designation'), 'value' => fn (User $user): ?string => $user->designation],
            'department' => ['label' => __('Department'), 'value' => fn (User $user): ?string => $user->department],
            'role' => ['label' => __('Role'), 'value' => fn (User $user): string => $permissions->label($user->role)],
            'companies' => ['label' => __('Companies'), 'value' => fn (User $user): string => $user->hasPermission('companies.all') ? __('All companies') : $user->companies->pluck('name')->join(', ')],
            'salary' => auth()->user()->hasPermission('users.update') ? ['label' => __('Monthly salary'), 'value' => fn (User $user): int => $user->monthly_salary, 'type' => 'money'] : null,
            'joined' => ['label' => __('Joining date'), 'value' => fn (User $user) => $user->joined_on, 'type' => 'date'],
            'status' => ['label' => __('Status'), 'value' => fn (User $user): string => $user->is_active ? __('Active') : __('Inactive')],
        ]), $this->companyScopeLabel());
    }
}
