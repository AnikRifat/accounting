<?php

namespace App\Livewire\Admin\Users;

use App\Support\CompanyContext;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/** Employees the actor may manage; with one company in the header, only employees assigned to it. */
class Index extends Component
{
    use WithPagination;

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
        $actor = auth()->user();
        $context = app(CompanyContext::class);
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.users.index', [
            'users' => ManageableUsers::for($actor)->with(['companies' => fn ($query) => $query->visibleTo($actor)->orderBy('name')])
                ->when(! $context->isAll(), fn ($query) => $query->whereHas('companies', fn ($companies) => $companies->whereKey($context->selectedId())))
                ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'))
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('employee_code', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')))
                ->orderBy('name')->paginate(15),
            'permissions' => app(Permissions::class),
        ])->layout('layouts.admin');
    }
}
