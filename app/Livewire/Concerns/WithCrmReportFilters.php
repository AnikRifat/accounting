<?php

namespace App\Livewire\Concerns;

use App\Models\Lead;
use App\Models\LeadCall;
use App\Support\CompanyContext;
use App\Support\Crm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Date range and person filters shared by the CRM reports. The range limits leads by the day they were added
 * and calls by the day they were made. Everything stays within the header companies and the viewer's lead
 * visibility: without `crm.leads.all` the reports cover only the viewer's own leads and calls.
 */
trait WithCrmReportFilters
{
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** User id: leads assigned to them and calls they logged. Only offered with `crm.leads.all`. */
    #[Url(as: 'user', except: '')]
    public string $person = '';

    /** @return array<string, mixed> the view data every report's filter bar needs */
    protected function filterData(): array
    {
        $context = app(CompanyContext::class);

        return [
            'seesAll' => Gate::allows('crm.leads.all'),
            'people' => Gate::allows('crm.leads.all') ? Crm::peopleOptions($context->companyIds()) : [],
            'scopeLabel' => ($context->isAll() ? __('All companies') : $context->company()?->name)
                .($this->from !== '' || $this->to !== '' ? ' · '.trim($this->from.' – '.$this->to, ' –') : ''),
        ];
    }

    /** @return Builder<Lead> visible leads of the header companies, filtered by person and creation date */
    protected function reportLeads(): Builder
    {
        [$from, $to, $person] = $this->filters();

        return Lead::visibleTo(auth()->user())->whereIn('leads.company_id', app(CompanyContext::class)->companyIds())
            ->when($person, fn (Builder $query) => $query->where('leads.assigned_to', $person))
            ->when($from, fn (Builder $query) => $query->where('leads.created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('leads.created_at', '<=', $to));
    }

    /** @return Builder<LeadCall> visible calls of the header companies, filtered by who logged them and call date */
    protected function reportCalls(): Builder
    {
        [$from, $to, $person] = $this->filters();

        return LeadCall::visibleTo(auth()->user())->whereIn('lead_calls.company_id', app(CompanyContext::class)->companyIds())
            ->when($person, fn (Builder $query) => $query->where('lead_calls.user_id', $person))
            ->when($from, fn (Builder $query) => $query->where('lead_calls.called_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('lead_calls.called_at', '<=', $to));
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int} range start, range end and person id */
    private function filters(): array
    {
        $date = fn (string $value, string $time): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value.' '.$time : null;

        return [$date($this->from, '00:00:00'), $date($this->to, '23:59:59'),
            Gate::allows('crm.leads.all') && ctype_digit($this->person) ? (int) $this->person : null];
    }
}
