<?php

namespace App\Livewire\Admin\Crm\Statuses;

use App\Enums\CrmStatusType;
use App\Livewire\Concerns\WithFormSheet;
use App\Models\CrmStatus;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use WithFormSheet;

    #[Url(except: 'lead')]
    public string $type = 'lead';

    /** Deletes an unused status; one that leads or calls use can only be deactivated. */
    public function delete(int $statusId): void
    {
        Gate::authorize('crm.setup.manage');
        $this->resetErrorBag('delete');
        $status = CrmStatus::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($statusId);
        if ($status->usageCount() > 0) {
            $this->addError('delete', __(':name is used by leads or calls. Deactivate it instead.', ['name' => $status->name]));

            return;
        }
        $status->delete();
        session()->now('success', __(':name deleted.', ['name' => $status->name]));
    }

    public function render(): View
    {
        Gate::authorize('crm.view');
        $type = CrmStatusType::tryFrom($this->type) ?? CrmStatusType::Lead;

        return view('livewire.admin.crm.statuses.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'statusType' => $type,
            'statuses' => CrmStatus::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->where('type', $type->value)
                ->with('company:id,name')->orderBy('company_id')->orderBy('position')->orderBy('name')->get(),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.crm.statuses.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }
}
