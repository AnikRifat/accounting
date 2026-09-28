<?php

namespace App\Livewire\Admin\Crm\Calls;

use App\Enums\CallType;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Services\CallLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Logs a call or visit with a lead, or edits one. The call belongs to the lead's company (like a settlement
 * belongs to its bill), so no header company is needed; that company must still be active for a new call.
 * Without `crm.leads.all` a user edits only the calls they logged.
 */
class Form extends Component
{
    #[Locked]
    public ?int $leadId = null;

    #[Locked]
    public ?int $callId = null;

    /** Where to go after saving: the page the sheet was opened from. */
    #[Locked]
    public string $returnTo = '';

    public string $type = 'call';

    public string $calledOn = '';

    public string $calledTime = '';

    public string $callStatusId = '';

    public string $leadStatusId = '';

    public string $summary = '';

    public string $nextCallOn = '';

    public function mount(?Lead $lead = null, ?LeadCall $call = null, ?string $returnTo = null): void
    {
        if ($call?->exists) {
            Gate::authorize('crm.calls.update');
            $call = $this->editableCall($call->id);
            [$this->callId, $this->leadId] = [$call->id, $call->lead_id];
            [$this->type, $this->callStatusId, $this->leadStatusId, $this->summary]
                = [$call->type->value, (string) $call->call_status_id, (string) $call->lead_status_id, (string) $call->summary];
            [$this->calledOn, $this->calledTime] = [$call->called_at->toDateString(), $call->called_at->format('H:i')];
            $this->nextCallOn = (string) $call->next_call_on?->toDateString();
        } else {
            Gate::authorize('crm.calls.create');
            $lead = Lead::visibleTo(auth()->user())->findOrFail($lead?->id);
            $this->leadId = $lead->id;
            $this->leadStatusId = (string) $lead->crm_status_id;
            [$this->calledOn, $this->calledTime] = [today()->toDateString(), now()->format('H:i')];
        }
        $this->returnTo = $returnTo !== null && str_starts_with($returnTo, url('/admin')) ? $returnTo : route('admin.crm.leads.show', $this->leadId);
    }

    public function save(CallLogger $logger): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->callId ? 'crm.calls.update' : 'crm.calls.create');
        $call = $this->callId ? $this->editableCall($this->callId) : null;
        $lead = $call?->lead ?? Lead::visibleTo(auth()->user())->findOrFail($this->leadId);
        if (! $call && ! $lead->company->is_active) {
            $this->addError('company', __(':company is inactive and accepts no new calls.', ['company' => $lead->company->name]));

            return null;
        }
        $this->summary = trim($this->summary);
        $data = $this->validate([
            'type' => ['required', Rule::enum(CallType::class)],
            'calledOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'calledTime' => ['required', 'date_format:H:i'],
            'callStatusId' => ['nullable', Rule::in($this->statusIds($lead->company_id, 'call', $call?->call_status_id))],
            'leadStatusId' => ['required', Rule::in($this->statusIds($lead->company_id, 'lead', $call?->lead_status_id ?? $lead->crm_status_id))],
            'summary' => ['nullable', 'string', 'max:1000'],
            'nextCallOn' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:calledOn'],
        ], [], ['calledOn' => __('date'), 'calledTime' => __('time'), 'callStatusId' => __('call result'), 'leadStatusId' => __('lead status'),
            'summary' => __('summary'), 'nextCallOn' => __('next call')]);
        $attributes = [
            'type' => $data['type'], 'called_at' => Carbon::parse($data['calledOn'].' '.$data['calledTime'])->toDateTimeString(),
            'call_status_id' => $data['callStatusId'] ?: null, 'lead_status_id' => (int) $data['leadStatusId'],
            'summary' => $data['summary'] ?: null, 'next_call_on' => $data['nextCallOn'] ?: null,
        ];
        $call ? $logger->update($call, $attributes) : $logger->record($lead, $attributes, auth()->user());
        session()->flash('success', $call ? __('Call updated.') : __('Call logged with :name.', ['name' => $lead->displayName()]));

        return redirect()->to($this->returnTo);
    }

    public function render(): View
    {
        $lead = Lead::query()->with(['company:id,name,is_active', 'status:id,name,tone'])->findOrFail($this->leadId);
        $call = $this->callId ? LeadCall::query()->find($this->callId) : null;
        $options = fn (string $type, ?int $current): array => CrmStatus::query()->where('company_id', $lead->company_id)->where('type', $type)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current))->orderBy('position')->pluck('name', 'id')->all();

        return view('livewire.admin.crm.calls.form', [
            'lead' => $lead,
            'types' => collect(CallType::cases())->mapWithKeys(fn (CallType $type): array => [$type->value => $type->label()])->all(),
            'callStatuses' => ['' => __('Not recorded')] + $options('call', $call?->call_status_id),
            'leadStatuses' => $options('lead', $call?->lead_status_id ?? $lead->crm_status_id),
            'closedStatusIds' => CrmStatus::query()->where('company_id', $lead->company_id)->lead()->where('is_closed', true)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
        ])->layout('layouts.admin');
    }

    /** A call the user may edit: visible to them, and their own unless they see every lead. */
    private function editableCall(int $callId): LeadCall
    {
        $user = auth()->user();
        $call = LeadCall::visibleTo($user)->findOrFail($callId);
        abort_unless($user->hasPermission('crm.leads.all') || $call->user_id === $user->id, 403);

        return $call;
    }

    /** @return list<string> active status ids of the company and type, plus the one already in use */
    private function statusIds(int $companyId, string $type, ?int $current): array
    {
        return CrmStatus::query()->where('company_id', $companyId)->where('type', $type)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current))->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }
}
