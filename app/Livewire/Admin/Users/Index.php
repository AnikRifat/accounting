<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Locked]
    public bool $employee = false;

    public string $search = '';

    public function mount(bool $employee = false): void
    {
        $this->employee = $employee;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function module(): string
    {
        return $this->employee ? 'employees' : 'users';
    }

    public function render(): View
    {
        Gate::authorize($this->module().'.view');
        $search = substr($this->search, 0, 100);

        return view('livewire.admin.users.index', [
            'users' => User::with('employee')->where('role', '!=', Permissions::ROOT_ROLE)
                ->when($this->employee, fn ($query) => $query->whereHas('employee'))
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
                ->latest('id')->paginate(15),
            'permissions' => app(Permissions::class),
        ])->layout('layouts.admin');
    }
}
