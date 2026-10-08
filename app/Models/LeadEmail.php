<?php

namespace App\Models;

use App\Support\Modules;
use Database\Factories\LeadEmailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email sent to a lead from the CRM, by `user_id`, with what the mail server answered: `sent`, or `failed` with the
 * error. Written only through App\Services\LeadMailer. Kept as a log: never edited, and it doesn't change the lead.
 */
#[Fillable(['company_id', 'lead_id', 'user_id', 'to', 'cc', 'subject', 'message', 'status', 'error', 'sent_at'])]
class LeadEmail extends Model
{
    /** @use HasFactory<LeadEmailFactory> */
    use HasFactory;

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function failed(): bool
    {
        return $this->status === self::FAILED;
    }

    /**
     * Emails of the companies the user may access. Without `crm.leads.all` only emails to their own leads
     * and emails they sent themselves, like calls.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('lead_emails.company_id', $user->accessibleCompanyIds(Modules::CRM));
        if (! $user->hasPermission('crm.leads.all')) {
            $query->where(fn (Builder $emails) => $emails->where('lead_emails.user_id', $user->id)
                ->orWhereHas('lead', fn (Builder $lead) => $lead->where('assigned_to', $user->id)));
        }
    }
}
