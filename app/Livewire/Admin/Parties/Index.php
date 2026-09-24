<?php

namespace App\Livewire\Admin\Parties;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\Party;
use App\Support\CompanyContext;
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

        return view('livewire.admin.parties.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'parties' => $this->tableQuery()->paginate(15),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.parties.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }

    /** @return Builder<Party> the parties of the header companies, filtered like the list */
    protected function tableQuery(): Builder
    {
        $search = mb_substr(trim($this->search), 0, 100);

        return Party::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->with('company')
            ->when($this->kind === 'employee', fn ($query) => $query->whereNotNull('user_id'))
            ->when($this->kind === 'custom', fn ($query) => $query->whereNull('user_id'))
            ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')))
            ->orderBy('name');
    }

    protected function tableExport(): TableExport
    {
        return new TableExport(__('Parties'), [
            'name' => ['label' => __('Name'), 'value' => fn (Party $party): string => $party->name],
            'company' => ['label' => __('Company'), 'value' => fn (Party $party): string => $party->company->name],
            'type' => ['label' => __('Type'), 'value' => fn (Party $party): string => $party->isEmployee() ? __('Employee') : __('Custom')],
            'phone' => ['label' => __('Phone'), 'value' => fn (Party $party): ?string => $party->phone],
            'address' => ['label' => __('Address'), 'value' => fn (Party $party): ?string => $party->address],
            'notes' => ['label' => __('Notes'), 'value' => fn (Party $party): ?string => $party->notes],
            'status' => ['label' => __('Status'), 'value' => fn (Party $party): string => $party->is_active ? __('Active') : __('Inactive')],
        ], app(CompanyContext::class)->label());
    }
}
