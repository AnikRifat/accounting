<?php

namespace App\Livewire\Admin\Companies;

use App\Models\Company;
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
        Gate::authorize('companies.view');
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.companies.index', [
            'companies' => Company::visibleTo(auth()->user())
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
                ->orderBy('name')->paginate(15),
        ])->layout('layouts.admin');
    }
}
