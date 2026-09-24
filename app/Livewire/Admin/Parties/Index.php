<?php

namespace App\Livewire\Admin\Parties;

use App\Models\Company;
use App\Models\Party;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $companyId = '';

    public string $kind = '';

    public string $status = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'companyId', 'kind', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        Gate::authorize('parties.view');
        $actor = auth()->user();
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.parties.index', [
            'companyOptions' => ['' => __('All companies')] + Company::visibleTo($actor)->orderBy('name')->pluck('name', 'id')->all(),
            // The visibility scope stays applied, so a forged company filter can only narrow the result.
            'parties' => Party::visibleTo($actor)->with('company')
                ->when($this->companyId !== '', fn ($query) => $query->where('company_id', (int) $this->companyId))
                ->when($this->kind === 'employee', fn ($query) => $query->whereNotNull('employee_id'))
                ->when($this->kind === 'custom', fn ($query) => $query->whereNull('employee_id'))
                ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'))
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')))
                ->orderBy('name')->paginate(15),
        ])->layout('layouts.admin');
    }
}
