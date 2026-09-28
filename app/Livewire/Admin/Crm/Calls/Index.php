<?php

namespace App\Livewire\Admin\Crm\Calls;

use App\Enums\CallType;
use App\Enums\CrmStatusType;
use App\Livewire\Concerns\WithFormSheet;
use App\Livewire\Concerns\WithTableTools;
use App\Models\LeadCall;
use App\Support\CompanyContext;
use App\Support\Configuration;
use App\Support\Crm;
use App\Support\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Every call and visit of the header companies the user may see, newest first. Doubles as the call log report. */
class Index extends Component
{
    use WithFormSheet, WithPagination, WithTableTools;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $service = '';

    /** Lead status the call set, by name. */
    #[Url(as: 'lead_status', except: '')]
    public string $leadStatus = '';

    /** Call result, by name. */
    #[Url(as: 'result', except: '')]
    public string $callStatus = '';

    #[Url(except: '')]
    public string $type = '';

    /** Who logged the call. */
    #[Url(as: 'by', except: '')]
    public string $caller = '';

    /** Who the lead is assigned to now. */
    #[Url(except: '')]
    public string $assignee = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'service', 'leadStatus', 'callStatus', 'type', 'caller', 'assignee', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /** Deletes one logged call; without `crm.leads.all` only the user's own. The lead keeps its status and follow-up. */
    public function delete(int $callId): void
    {
        Gate::authorize('crm.calls.delete');
        $user = auth()->user();
        $call = LeadCall::visibleTo($user)->whereIn('lead_calls.company_id', app(CompanyContext::class)->companyIds())->findOrFail($callId);
        abort_unless($user->hasPermission('crm.leads.all') || $call->user_id === $user->id, 403);
        $call->delete();
        session()->now('success', __('Call deleted.'));
    }

    public function render(): View
    {
        Gate::authorize('crm.view');
        $companyIds = app(CompanyContext::class)->companyIds();
        $query = $this->tableQuery();

        return view('livewire.admin.crm.calls.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'seesAll' => Gate::allows('crm.leads.all'),
            'summary' => ['calls' => (clone $query)->where('type', CallType::Call->value)->count(), 'visits' => (clone $query)->where('type', CallType::Visit->value)->count(),
                'leads' => (clone $query)->reorder()->distinct()->count('lead_calls.lead_id')],
            'calls' => $query->paginate(Configuration::get('general.rows_per_page')),
            'services' => Crm::serviceOptions($companyIds),
            'leadStatuses' => Crm::statusOptions($companyIds, CrmStatusType::Lead),
            'callStatuses' => Crm::statusOptions($companyIds, CrmStatusType::Call),
            'types' => collect(CallType::cases())->mapWithKeys(fn (CallType $type): array => [$type->value => $type->label()])->all(),
            'people' => Gate::allows('crm.leads.all') ? Crm::peopleOptions($companyIds) : [],
            'active' => ($this->service !== '') + ($this->leadStatus !== '') + ($this->callStatus !== '') + ($this->type !== '')
                + ($this->caller !== '') + ($this->assignee !== '') + ($this->from !== '' || $this->to !== ''),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.crm.calls.index';
    }

    /** @return Builder<LeadCall> the visible calls of the header companies, filtered like the list */
    protected function tableQuery(): Builder
    {
        $search = mb_substr(trim($this->search), 0, 100);
        $phone = Crm::normalizePhone($search);

        return LeadCall::visibleTo(auth()->user())->whereIn('lead_calls.company_id', app(CompanyContext::class)->companyIds())
            ->with(['company:id,name', 'lead:id,name,phone,crm_service_id,assigned_to', 'lead.service:id,name', 'lead.assignee:id,name',
                'user:id,name', 'callStatus:id,name,tone', 'leadStatus:id,name,tone'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('summary', 'like', '%'.$search.'%')
                ->orWhereHas('lead', fn (Builder $lead) => $lead->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.($phone !== '' ? $phone : $search).'%'))))
            ->when($this->service !== '', fn (Builder $query) => $query->whereHas('lead.service', fn (Builder $q) => $q->where('name', $this->service)))
            ->when($this->leadStatus !== '', fn (Builder $query) => $query->whereHas('leadStatus', fn (Builder $q) => $q->where('name', $this->leadStatus)))
            ->when($this->callStatus !== '', fn (Builder $query) => $query->whereHas('callStatus', fn (Builder $q) => $q->where('name', $this->callStatus)))
            ->when(CallType::tryFrom($this->type) !== null, fn (Builder $query) => $query->where('type', $this->type))
            ->when(ctype_digit($this->caller), fn (Builder $query) => $query->where('lead_calls.user_id', (int) $this->caller))
            ->when(ctype_digit($this->assignee), fn (Builder $query) => $query->whereHas('lead', fn (Builder $q) => $q->where('assigned_to', (int) $this->assignee)))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from), fn (Builder $query) => $query->where('called_at', '>=', $this->from.' 00:00:00'))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to), fn (Builder $query) => $query->where('called_at', '<=', $this->to.' 23:59:59'))
            ->orderByDesc('called_at')->orderByDesc('lead_calls.id');
    }

    protected function tableExport(): TableExport
    {
        return new TableExport(__('Call log'), [
            'called_at' => ['label' => __('When'), 'value' => fn (LeadCall $call): string => $call->called_at->format('Y-m-d H:i')],
            'lead' => ['label' => __('Lead'), 'value' => fn (LeadCall $call): string => $call->lead->displayName()],
            'phone' => ['label' => __('Phone'), 'value' => fn (LeadCall $call): string => $call->lead->phone],
            'company' => ['label' => __('Company'), 'value' => fn (LeadCall $call): string => $call->company->name],
            'type' => ['label' => __('Type'), 'value' => fn (LeadCall $call): string => $call->type->label()],
            'result' => ['label' => __('Call result'), 'value' => fn (LeadCall $call): ?string => $call->callStatus?->name],
            'lead_status' => ['label' => __('Lead status'), 'value' => fn (LeadCall $call): string => $call->leadStatus->name],
            'service' => ['label' => __('Service'), 'value' => fn (LeadCall $call): ?string => $call->lead->service?->name],
            'summary' => ['label' => __('Summary'), 'value' => fn (LeadCall $call): ?string => $call->summary],
            'next_call' => ['label' => __('Next call'), 'value' => fn (LeadCall $call): mixed => $call->next_call_on, 'type' => 'date'],
            'by' => ['label' => __('Logged by'), 'value' => fn (LeadCall $call): string => $call->user->name],
            'assignee' => ['label' => __('Assigned to'), 'value' => fn (LeadCall $call): ?string => $call->lead->assignee?->name],
        ], $this->companyScopeLabel());
    }
}
