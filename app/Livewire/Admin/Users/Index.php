<?php

namespace App\Livewire\Admin\Users;

use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('users.view');
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.users.index', [
            'users' => ManageableUsers::for(auth()->user())->with(['companies' => fn ($query) => $query->visibleTo(auth()->user())->orderBy('name')])
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
                ->latest('id')->paginate(15),
            'permissions' => app(Permissions::class),
        ])->layout('layouts.admin');
    }
}
