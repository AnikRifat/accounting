<?php

namespace App\Livewire\Admin\Crm\Services;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\CrmService;
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

    /** Deletes an unused service; one that leads use can only be deactivated, so their history stays readable. */
    public function delete(int $serviceId): void
    {
        Gate::authorize('crm.setup.manage');
        $this->resetErrorBag('delete');
        $service = CrmService::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($serviceId);
        if ($service->leads()->exists()) {
            $this->addError('delete', __(':name is used by leads. Deactivate it instead.', ['name' => $service->name]));

            return;
        }
        $service->delete();
        session()->now('success', __(':name deleted.', ['name' => $service->name]));
    }

    public function render(): View
    {
        Gate::authorize('crm.view');
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.crm.services.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'services' => CrmService::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->with('company:id,name')->withCount('leads')
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->orderBy('name')->paginate(20),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.crm.services.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }
}
