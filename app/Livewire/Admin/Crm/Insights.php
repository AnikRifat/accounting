<?php

namespace App\Livewire\Admin\Crm;

use App\Enums\CallType;
use App\Enums\CrmStatusType;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Support\CompanyContext;
use App\Support\Crm;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * CRM figures for the header companies, optionally for one person and a date range. The range limits leads by
 * creation date and calls by call date; follow-up queues are always as of today. Users without
 * `crm.leads.all` see their own figures only.
 */
class Insights extends Component
{
    /** User id: leads assigned to them and calls they logged. */
    #[Url(as: 'user', except: '')]
    public string $person = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function render(): View
    {
        Gate::authorize('crm.view');
        $companyIds = app(CompanyContext::class)->companyIds();
        $seesAll = Gate::allows('crm.leads.all');
        $person = $seesAll ? (ctype_digit($this->person) ? (int) $this->person : null) : auth()->id();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) ? $this->from.' 00:00:00' : null;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) ? $this->to.' 23:59:59' : null;

        $leads = fn (): Builder => Lead::visibleTo(auth()->user())->whereIn('leads.company_id', $companyIds)
            ->when($person, fn (Builder $query) => $query->where('leads.assigned_to', $person));
        $createdLeads = fn (): Builder => $leads()->when($from, fn (Builder $query) => $query->where('leads.created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('leads.created_at', '<=', $to));
        $calls = fn (): Builder => LeadCall::visibleTo(auth()->user())->whereIn('lead_calls.company_id', $companyIds)
            ->when($person, fn (Builder $query) => $query->where('lead_calls.user_id', $person))
            ->when($from, fn (Builder $query) => $query->where('called_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('called_at', '<=', $to));

        $callCount = $calls()->where('type', CallType::Call->value)->count();
        $leadsCalled = $calls()->distinct()->count('lead_calls.lead_id');
        $visitsToday = $calls()->where('type', CallType::Visit->value)->where('called_at', '>=', today()->toDateTimeString())->count();

        return view('livewire.admin.crm.insights', [
            'seesAll' => $seesAll,
            'people' => $seesAll ? Crm::peopleOptions($companyIds) : [],
            'scopeLabel' => app(CompanyContext::class)->isAll() ? __('All companies') : app(CompanyContext::class)->company()?->name,
            'leadFigures' => [
                'leads' => $createdLeads()->count(),
                'neverCalled' => $createdLeads()->whereDoesntHave('calls')->count(),
                'calls' => $callCount,
                'leadsCalled' => $leadsCalled,
                'repeatCalls' => max(0, $calls()->count() - $leadsCalled),
            ],
            'byLeadStatus' => $this->countByStatus(Crm::statusOptions($companyIds, CrmStatusType::Lead),
                $createdLeads()->join('crm_statuses', 'crm_statuses.id', '=', 'leads.crm_status_id')),
            'byCallResult' => $this->countByStatus(Crm::statusOptions($companyIds, CrmStatusType::Call) + ['' => __('Not recorded')],
                $calls()->leftJoin('crm_statuses', 'crm_statuses.id', '=', 'lead_calls.call_status_id')),
            'followUps' => [
                'today' => $leads()->followUp('today')->count(),
                'overdue' => $leads()->followUp('overdue')->count(),
                'upcoming' => $leads()->followUp('upcoming')->count(),
                'visitsToday' => $visitsToday,
                'visits' => $calls()->where('type', CallType::Visit->value)->count(),
            ],
            'active' => ($person !== null && $seesAll) + ($from !== null || $to !== null),
        ])->layout('layouts.admin');
    }

    /**
     * Counts rows per status name, every known name listed (zero included) in status order.
     *
     * @param  array<string, string>  $names
     * @return array<string, int> label => count
     */
    private function countByStatus(array $names, Builder $joined): array
    {
        $counts = $joined->toBase()->reorder()->groupBy('crm_statuses.name')->selectRaw('crm_statuses.name as status_name, COUNT(*) as total')
            ->pluck('total', 'status_name')->mapWithKeys(fn (mixed $total, mixed $name): array => [(string) $name => (int) $total]);

        return collect($names)->mapWithKeys(fn (string $label, string $name): array => [$label => $counts->get($name, 0)])->all();
    }
}
