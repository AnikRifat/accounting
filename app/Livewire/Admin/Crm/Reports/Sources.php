<?php

namespace App\Livewire\Admin\Crm\Reports;

use App\Livewire\Concerns\WithCrmReportFilters;
use App\Models\CrmStatus;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Leads per source: how many were contacted, how many are still open, and how many sit in each closed status. */
class Sources extends Component
{
    use WithCrmReportFilters;

    public function render(): View
    {
        Gate::authorize('crm.reports.view');
        $byStatus = $this->reportLeads()->join('crm_statuses', 'crm_statuses.id', '=', 'leads.crm_status_id')
            ->leftJoin('crm_sources', 'crm_sources.id', '=', 'leads.crm_source_id')->toBase()->reorder()
            ->groupBy('crm_sources.name', 'crm_statuses.name', 'crm_statuses.is_closed')
            ->selectRaw('crm_sources.name as source, crm_statuses.name as status_name, crm_statuses.is_closed as is_closed, COUNT(*) as total')->get();
        $contacted = $this->reportLeads()->whereHas('calls')->leftJoin('crm_sources', 'crm_sources.id', '=', 'leads.crm_source_id')->toBase()->reorder()
            ->groupBy('crm_sources.name')->selectRaw('crm_sources.name as source, COUNT(*) as total')->pluck('total', 'source');
        $closed = CrmStatus::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->lead()->where('is_closed', true)
            ->orderBy('position')->pluck('name')->unique()->values()->all();
        $rows = $byStatus->groupBy(fn (object $row): string => (string) $row->source)
            ->map(function ($group, string $source) use ($contacted): array {
                $total = (int) $group->sum('total');

                return [
                    'label' => $source !== '' ? $source : __('Not recorded'),
                    'filter' => $source !== '' ? $source : 'none',
                    'total' => $total,
                    'contacted' => (int) ($contacted[$source] ?? 0),
                    'open' => (int) $group->filter(fn (object $row): bool => ! $row->is_closed)->sum('total'),
                    'closed' => $group->filter(fn (object $row): bool => (bool) $row->is_closed)->mapWithKeys(fn (object $row): array => [$row->status_name => (int) $row->total])->all(),
                ];
            })->sortByDesc('total')->values();

        return view('livewire.admin.crm.reports.sources', [...$this->filterData(), 'rows' => $rows, 'closedStatuses' => $closed])->layout('layouts.admin');
    }
}
