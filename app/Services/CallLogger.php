<?php

namespace App\Services;

use App\Models\CrmStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes lead calls. The latest call of a lead (by call time) drives the lead: its lead status becomes the
 * lead's status and its next call date the lead's follow-up date, cleared when that status is closed.
 * Callers validate that the statuses belong to the lead's company.
 */
class CallLogger
{
    /**
     * @param  array{type: string, called_at: string, call_status_id: ?int, lead_status_id: int, summary: ?string, next_call_on: ?string}  $data
     */
    public function record(Lead $lead, array $data, User $actor): LeadCall
    {
        return DB::transaction(function () use ($lead, $data, $actor): LeadCall {
            $call = $lead->calls()->create([...$data, 'company_id' => $lead->company_id, 'user_id' => $actor->id]);
            $this->syncLead($lead, $call);

            return $call;
        });
    }

    /**
     * @param  array{type: string, called_at: string, call_status_id: ?int, lead_status_id: int, summary: ?string, next_call_on: ?string}  $data
     */
    public function update(LeadCall $call, array $data): void
    {
        DB::transaction(function () use ($call, $data): void {
            $call->update($data);
            $this->syncLead($call->lead, $call);
        });
    }

    /** Applies the call to its lead when it is the lead's latest call; an older, back-dated call changes nothing. */
    private function syncLead(Lead $lead, LeadCall $call): void
    {
        $latest = $lead->calls()->orderByDesc('called_at')->orderByDesc('id')->lockForUpdate()->first();
        if (! $latest?->is($call)) {
            return;
        }
        $closed = (bool) CrmStatus::query()->whereKey($call->lead_status_id)->value('is_closed');
        $lead->update(['crm_status_id' => $call->lead_status_id, 'next_call_on' => $closed ? null : $call->next_call_on]);
    }
}
