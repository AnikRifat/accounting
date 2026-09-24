<?php

namespace App\Livewire\Admin\Parties;

use App\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $kind = '';

    public string $status = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'kind', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        Gate::authorize('parties.view');
        $context = app(CompanyContext::class);
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.parties.index', [
            'showCompany' => $context->isAll(),
            'parties' => Party::query()->whereIn('company_id', $context->companyIds())->with('company')
                ->when($this->kind === 'employee', fn ($query) => $query->whereNotNull('user_id'))
                ->when($this->kind === 'custom', fn ($query) => $query->whereNull('user_id'))
                ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'))
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')))
                ->orderBy('name')->paginate(15),
        ])->layout('layouts.admin');
    }
}
