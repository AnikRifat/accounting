<?php

namespace App\Livewire\Admin\Crm\Leads;

use App\Enums\CrmStatusType;
use App\Livewire\Concerns\WithFormSheet;
use App\Livewire\Concerns\WithTableTools;
use App\Models\Lead;
use App\Support\CompanyContext;
use App\Support\Crm;
use App\Support\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Leads of the header companies the user may see (their own only without `crm.leads.all`). Doubles as the lead report. */
class Index extends Component
{
    use WithFormSheet, WithPagination, WithTableTools;

    public const FOLLOW_UPS = ['today', 'overdue', 'upcoming', 'none'];

    #[Url(except: '')]
    public string $search = '';

    /** Service name: names merge across companies in "All companies". */
    #[Url(except: '')]
    public string $service = '';

    /** Source name. */
    #[Url(except: '')]
    public string $source = '';

    /** Lead status name. */
    #[Url(except: '')]
    public string $status = '';

    /** User id, or "none" for unassigned leads. */
    #[Url(except: '')]
    public string $assignee = '';

    #[Url(as: 'follow_up', except: '')]
    public string $followUp = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'service', 'source', 'status', 'assignee', 'followUp', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function delete(int $leadId): void
    {
        Gate::authorize('crm.leads.delete');
        $lead = Lead::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($leadId);
        DB::transaction(function () use ($lead): void {
            $lead->calls()->delete();
            $lead->delete();
        });
        session()->now('success', __('Lead :name and its calls deleted.', ['name' => $lead->displayName()]));
    }

    public function render(): View
    {
        Gate::authorize('crm.view');
        $companyIds = app(CompanyContext::class)->companyIds();

        return view('livewire.admin.crm.leads.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'seesAll' => Gate::allows('crm.leads.all'),
            'leads' => $this->tableQuery()->paginate(25),
            'services' => Crm::serviceOptions($companyIds),
            'sources' => Crm::sourceOptions($companyIds),
            'statuses' => Crm::statusOptions($companyIds, CrmStatusType::Lead),
            'people' => Gate::allows('crm.leads.all') ? Crm::peopleOptions($companyIds) : [],
            'active' => ($this->service !== '') + ($this->source !== '') + ($this->status !== '') + ($this->assignee !== '') + ($this->followUp !== '') + ($this->from !== '' || $this->to !== ''),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.crm.leads.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create', 'import'];
    }

    /** @return Builder<Lead> the visible leads of the header companies, filtered like the list, newest first */
    protected function tableQuery(): Builder
    {
        $search = mb_substr(trim($this->search), 0, 100);
        $phone = Crm::normalizePhone($search);

        return Lead::visibleTo(auth()->user())->whereIn('leads.company_id', app(CompanyContext::class)->companyIds())
            ->with(['photo', 'company:id,name', 'service:id,name', 'source:id,name', 'status:id,name,tone,is_closed', 'assignee:id,name', 'latestCall'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('name', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.($phone !== '' ? $phone : $search).'%')->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('organization', 'like', '%'.$search.'%')))
            ->when($this->service !== '', fn (Builder $query) => $query->whereHas('service', fn (Builder $q) => $q->where('name', $this->service)))
            ->when($this->source === 'none', fn (Builder $query) => $query->whereNull('crm_source_id'))
            ->when(! in_array($this->source, ['', 'none'], true), fn (Builder $query) => $query->whereHas('source', fn (Builder $q) => $q->where('name', $this->source)))
            ->when($this->status !== '', fn (Builder $query) => $query->whereHas('status', fn (Builder $q) => $q->where('name', $this->status)))
            ->when($this->assignee === 'none', fn (Builder $query) => $query->whereNull('assigned_to'))
            ->when(ctype_digit($this->assignee), fn (Builder $query) => $query->where('assigned_to', (int) $this->assignee))
            ->when(in_array($this->followUp, ['today', 'overdue', 'upcoming'], true), fn (Builder $query) => $query->followUp($this->followUp))
            ->when($this->followUp === 'none', fn (Builder $query) => $query->whereNull('next_call_on'))
            ->when($this->validDate($this->from), fn (Builder $query) => $query->where('leads.created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->validDate($this->to), fn (Builder $query) => $query->where('leads.created_at', '<=', $this->to.' 23:59:59'))
            ->orderByDesc('leads.created_at')->orderByDesc('leads.id');
    }

    protected function tableExport(): TableExport
    {
        return new TableExport(__('Leads'), [
            'name' => ['label' => __('Name'), 'value' => fn (Lead $lead): string => $lead->displayName()],
            'phone' => ['label' => __('Phone'), 'value' => fn (Lead $lead): string => $lead->phone],
            'email' => ['label' => __('Email'), 'value' => fn (Lead $lead): ?string => $lead->email],
            'organization' => ['label' => __('Organisation'), 'value' => fn (Lead $lead): ?string => $lead->organization],
            'company' => ['label' => __('Company'), 'value' => fn (Lead $lead): string => $lead->company->name],
            'service' => ['label' => __('Service'), 'value' => fn (Lead $lead): ?string => $lead->service?->name],
            'status' => ['label' => __('Status'), 'value' => fn (Lead $lead): string => $lead->status->name],
            'assignee' => ['label' => __('Assigned to'), 'value' => fn (Lead $lead): ?string => $lead->assignee?->name],
            'source' => ['label' => __('Source'), 'value' => fn (Lead $lead): ?string => $lead->source?->name],
            'next_call' => ['label' => __('Next call'), 'value' => fn (Lead $lead): mixed => $lead->next_call_on, 'type' => 'date'],
            'last_call' => ['label' => __('Last call'), 'value' => fn (Lead $lead): ?string => $lead->latestCall?->summary],
            'created' => ['label' => __('Created'), 'value' => fn (Lead $lead): mixed => $lead->created_at, 'type' => 'date'],
        ], $this->companyScopeLabel());
    }

    private function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
