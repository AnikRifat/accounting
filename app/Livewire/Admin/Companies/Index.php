<?php

namespace App\Livewire\Admin\Companies;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use App\Livewire\Concerns\WithTableTools;
use App\Support\TableExport;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithFormSheet, WithPagination, WithTableTools;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('companies.view');

        return view('livewire.admin.companies.index', [
            'companies' => $this->tableQuery()->paginate(15),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.companies.index';
    }

    /** @return Builder<Company> the companies this user can see, filtered like the list */
    protected function tableQuery(): Builder
    {
        $search = mb_substr(trim($this->search), 0, 100);

        return Company::visibleTo(auth()->user())
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
            ->orderBy('name');
    }

    protected function tableExport(): TableExport
    {
        return new TableExport(__('Companies'), [
            'name' => ['label' => __('Company'), 'value' => fn (Company $company): string => $company->name],
            'code' => ['label' => __('Code'), 'value' => fn (Company $company): string => $company->code],
            'phone' => ['label' => __('Phone'), 'value' => fn (Company $company): ?string => $company->phone],
            'address' => ['label' => __('Address'), 'value' => fn (Company $company): ?string => $company->address],
            'status' => ['label' => __('Status'), 'value' => fn (Company $company): string => $company->is_active ? __('Active') : __('Inactive')],
        ]);
    }
}
