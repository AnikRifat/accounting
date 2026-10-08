<?php

namespace App\Models;

use App\Enums\CallType;
use App\Support\Crm;
use App\Support\Modules;
use Database\Factories\LeadCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One contact with a lead (a phone call or a visit), logged by `user_id`. Written through App\Services\CallLogger. */
#[Fillable(['company_id', 'lead_id', 'user_id', 'type', 'called_at', 'call_status_id', 'lead_status_id', 'summary', 'next_call_on'])]
class LeadCall extends Model
{
    /** @use HasFactory<LeadCallFactory> */
    use HasFactory;

    protected $attributes = ['type' => 'call'];

    protected function casts(): array
    {
        return ['type' => CallType::class, 'called_at' => 'datetime', 'next_call_on' => 'date'];
    }

    protected function nextCallOn(): Attribute
    {
        return Attribute::set(fn (mixed $value): ?string => Crm::dateString($value));
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

    public function callStatus(): BelongsTo
    {
        return $this->belongsTo(CrmStatus::class, 'call_status_id');
    }

    public function leadStatus(): BelongsTo
    {
        return $this->belongsTo(CrmStatus::class, 'lead_status_id');
    }

    /**
     * Calls of the companies the user may access. Without `crm.leads.all` only calls on their own leads
     * and calls they logged themselves.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('lead_calls.company_id', $user->accessibleCompanyIds(Modules::CRM));
        if (! $user->hasPermission('crm.leads.all')) {
            $query->where(fn (Builder $calls) => $calls->where('lead_calls.user_id', $user->id)
                ->orWhereHas('lead', fn (Builder $lead) => $lead->where('assigned_to', $user->id)));
        }
    }
}
