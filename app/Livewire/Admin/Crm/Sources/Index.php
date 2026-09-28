<?php

namespace App\Livewire\Admin\Crm\Sources;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\CrmSource;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithFormSheet, WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Deletes an unused source; one that leads use can only be deactivated, so their history stays readable. */
    public function delete(int $sourceId): void
    {
        Gate::authorize('crm.setup.manage');
        $this->resetErrorBag('delete');
        $source = CrmSource::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($sourceId);
        if ($source->leads()->exists()) {
            $this->addError('delete', __(':name is used by leads. Deactivate it instead.', ['name' => $source->name]));

            return;
        }
        $source->delete();
        session()->now('success', __(':name deleted.', ['name' => $source->name]));
    }

    public function render(): View
    {
        Gate::authorize('crm.view');
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.crm.sources.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'sources' => CrmSource::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->with('company:id,name')->withCount('leads')
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->orderBy('name')->paginate(20),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.crm.sources.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }
}
