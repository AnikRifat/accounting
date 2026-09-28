<?php

namespace App\Livewire\Admin\Crm\Leads;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\Lead;
use App\Models\LeadCall;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** One lead with its whole call history. Calls are logged and edited in a sheet over this page. */
class Show extends Component
{
    use WithFormSheet;

    #[Locked]
    public int $leadId;

    public function mount(Lead $lead): void
    {
        Gate::authorize('crm.view');
        abort_unless(Lead::visibleTo(auth()->user())->whereKey($lead->id)->exists(), 404);
        $this->leadId = $lead->id;
    }

    /** Deletes one logged call; without `crm.leads.all` only the user's own. The lead keeps its status and follow-up. */
    public function deleteCall(int $callId): void
    {
        Gate::authorize('crm.calls.delete');
        $user = auth()->user();
        $call = LeadCall::visibleTo($user)->where('lead_id', $this->leadId)->findOrFail($callId);
        abort_unless($user->hasPermission('crm.leads.all') || $call->user_id === $user->id, 403);
        $call->delete();
        session()->now('success', __('Call deleted.'));
    }

    public function render(): View
    {
        $lead = Lead::visibleTo(auth()->user())->with(['company:id,name', 'service:id,name', 'status', 'assignee:id,name', 'creator:id,name'])->findOrFail($this->leadId);

        return view('livewire.admin.crm.leads.show', [
            'lead' => $lead,
            'calls' => $lead->calls()->with(['user:id,name', 'callStatus:id,name,tone', 'leadStatus:id,name,tone'])->orderByDesc('called_at')->orderByDesc('id')->get(),
            'editsAny' => Gate::allows('crm.leads.all'),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.crm.leads.index';
    }
}
