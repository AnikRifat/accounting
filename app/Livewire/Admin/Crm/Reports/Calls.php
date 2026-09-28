<?php

namespace App\Livewire\Admin\Crm\Reports;

use App\Enums\CallType;
use App\Enums\CrmStatusType;
use App\Livewire\Concerns\WithCrmReportFilters;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Crm;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Calls and visits per person who logged them, with calls split by call result. */
class Calls extends Component
{
    use WithCrmReportFilters;

    public function render(): View
    {
        Gate::authorize('crm.reports.view');
        $results = $this->reportCalls()->where('lead_calls.type', CallType::Call->value)
            ->leftJoin('crm_statuses', 'crm_statuses.id', '=', 'lead_calls.call_status_id')->toBase()->reorder()
            ->groupBy('lead_calls.user_id', 'crm_statuses.name')
            ->selectRaw('lead_calls.user_id as user_id, crm_statuses.name as status_name, COUNT(*) as total')->get();
        $visits = $this->reportCalls()->where('lead_calls.type', CallType::Visit->value)->toBase()->reorder()
            ->groupBy('lead_calls.user_id')->selectRaw('lead_calls.user_id as user_id, COUNT(*) as total')->pluck('total', 'user_id');
        $leads = $this->reportCalls()->toBase()->reorder()->groupBy('lead_calls.user_id')
            ->selectRaw('lead_calls.user_id as user_id, COUNT(DISTINCT lead_calls.lead_id) as total')->pluck('total', 'user_id');
        $userIds = $results->pluck('user_id')->merge($visits->keys())->unique()->all();
        $names = User::query()->whereKey($userIds)->pluck('name', 'id');
        $columns = Crm::statusOptions(app(CompanyContext::class)->companyIds(), CrmStatusType::Call) + ['' => __('Not recorded')];
        $rows = collect($userIds)->map(fn (mixed $userId): array => [
            'name' => (string) $names->get($userId),
            'counts' => $results->where('user_id', $userId)->mapWithKeys(fn (object $row): array => [(string) $row->status_name => (int) $row->total])->all(),
            'calls' => (int) $results->where('user_id', $userId)->sum('total'),
            'visits' => (int) ($visits[$userId] ?? 0),
            'leads' => (int) ($leads[$userId] ?? 0),
        ])->sortBy('name')->values();

        return view('livewire.admin.crm.reports.calls', [...$this->filterData(), 'rows' => $rows, 'columns' => $columns])->layout('layouts.admin');
    }
}
