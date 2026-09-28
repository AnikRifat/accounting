<?php

namespace App\Livewire\Admin\Crm\Reports;

use App\Enums\CallType;
use App\Enums\CrmStatusType;
use App\Livewire\Concerns\WithTableTools;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Crm;
use App\Support\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Per-person totals for the header companies: leads assigned to them by current status, overdue follow-ups, and
 * the calls and visits they logged. The date range limits leads by creation date and calls by call date.
 * Without `crm.leads.all` the report shows the viewer's own row only.
 */
class Performance extends Component
{
    use WithTableTools;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function render(): View
    {
        Gate::authorize('crm.reports.view');

        return view('livewire.admin.crm.reports.performance', [
            'rows' => $this->tableQuery()->get(),
            'statuses' => $this->statuses(),
            'showUnassigned' => Gate::allows('crm.leads.all'),
        ])->layout('layouts.admin');
    }

    /** @return Builder<User> one row per person with their counts */
    protected function tableQuery(): Builder
    {
        $companyIds = app(CompanyContext::class)->companyIds();
        [$from, $to] = $this->range();
        $leads = fn (Builder $query): Builder => $query->whereIn('leads.company_id', $companyIds)
            ->when($from, fn (Builder $q) => $q->where('leads.created_at', '>=', $from))->when($to, fn (Builder $q) => $q->where('leads.created_at', '<=', $to));
        $calls = fn (Builder $query): Builder => $query->whereIn('lead_calls.company_id', $companyIds)
            ->when($from, fn (Builder $q) => $q->where('called_at', '>=', $from))->when($to, fn (Builder $q) => $q->where('called_at', '<=', $to));
        $counts = [
            'assignedLeads as leads_count' => $leads,
            'assignedLeads as overdue_count' => fn (Builder $query) => $leads($query)->followUp('overdue'),
            'leadCalls as calls_count' => fn (Builder $query) => $calls($query)->where('type', CallType::Call->value),
            'leadCalls as visits_count' => fn (Builder $query) => $calls($query)->where('type', CallType::Visit->value),
        ];
        foreach (array_keys($this->statuses()) as $index => $name) {
            $counts['assignedLeads as status_'.$index.'_count'] = fn (Builder $query) => $leads($query)->whereHas('status', fn (Builder $status) => $status->where('name', $name));
        }
        $people = Gate::allows('crm.leads.all') ? array_keys(Crm::peopleOptions($companyIds)) : [auth()->id()];

        return User::query()->whereKey($people)->withCount($counts)->orderBy('name');
    }

    protected function tableExport(): TableExport
    {
        $columns = [
            'name' => ['label' => __('Person'), 'value' => fn (User $user): string => $user->name],
            'leads' => ['label' => __('Leads'), 'value' => fn (User $user): int => $user->leads_count, 'type' => 'number'],
        ];
        foreach (array_keys($this->statuses()) as $index => $name) {
            $columns['status_'.$index] = ['label' => $name, 'value' => fn (User $user): int => $user->{'status_'.$index.'_count'}, 'type' => 'number'];
        }

        return new TableExport(__('Team performance'), $columns + [
            'overdue' => ['label' => __('Overdue follow-ups'), 'value' => fn (User $user): int => $user->overdue_count, 'type' => 'number'],
            'calls' => ['label' => __('Calls'), 'value' => fn (User $user): int => $user->calls_count, 'type' => 'number'],
            'visits' => ['label' => __('Visits'), 'value' => fn (User $user): int => $user->visits_count, 'type' => 'number'],
        ], $this->companyScopeLabel().($this->from !== '' || $this->to !== '' ? ' · '.trim($this->from.' – '.$this->to, ' –') : ''));
    }

    /** @return array<string, string> lead status names of the header companies, in status order */
    private function statuses(): array
    {
        return Crm::statusOptions(app(CompanyContext::class)->companyIds(), CrmStatusType::Lead);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function range(): array
    {
        return [preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) ? $this->from.' 00:00:00' : null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) ? $this->to.' 23:59:59' : null];
    }
}
