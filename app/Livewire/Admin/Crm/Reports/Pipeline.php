<?php

namespace App\Livewire\Admin\Crm\Reports;

use App\Enums\CrmStatusType;
use App\Livewire\Concerns\WithCrmReportFilters;
use App\Support\CompanyContext;
use App\Support\Crm;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Leads by service (rows) and current lead status (columns). Names merge across companies in "All companies". */
class Pipeline extends Component
{
    use WithCrmReportFilters;

    public function render(): View
    {
        Gate::authorize('crm.reports.view');
        $counts = $this->reportLeads()->leftJoin('crm_services', 'crm_services.id', '=', 'leads.crm_service_id')
            ->join('crm_statuses', 'crm_statuses.id', '=', 'leads.crm_status_id')->toBase()->reorder()
            ->groupBy('crm_services.name', 'crm_statuses.name')
            ->selectRaw('crm_services.name as service_name, crm_statuses.name as status_name, COUNT(*) as total')->get();
        $statuses = Crm::statusOptions(app(CompanyContext::class)->companyIds(), CrmStatusType::Lead);
        $rows = $counts->groupBy(fn (object $row): string => (string) $row->service_name)
            ->map(fn ($group, string $service): array => [
                'label' => $service !== '' ? $service : __('No service'),
                'service' => $service !== '' ? $service : null,
                'counts' => $group->mapWithKeys(fn (object $row): array => [$row->status_name => (int) $row->total])->all(),
                'total' => (int) $group->sum('total'),
            ])->sortByDesc('total')->values();

        return view('livewire.admin.crm.reports.pipeline', [
            ...$this->filterData(),
            'statuses' => $statuses,
            'rows' => $rows,
            'totals' => collect($statuses)->mapWithKeys(fn (string $name): array => [$name => (int) $counts->where('status_name', $name)->sum('total')])->all(),
        ])->layout('layouts.admin');
    }
}
