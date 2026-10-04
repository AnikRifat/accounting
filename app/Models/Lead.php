<?php

namespace App\Models;

use App\Concerns\HasMedia;
use App\Concerns\HasPhoto;
use App\Support\Crm;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A prospective customer of one company. `next_call_on` is the follow-up date; it is set by the lead form
 * and replaced by every logged call (see App\Services\CallLogger). Follow-up buckets are derived from it.
 */
#[Fillable(['company_id', 'name', 'phone', 'email', 'organization', 'address', 'crm_source_id', 'crm_service_id', 'crm_status_id',
    'assigned_to', 'created_by', 'next_call_on', 'notes'])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, HasMedia, HasPhoto;

    protected function casts(): array
    {
        return ['next_call_on' => 'date'];
    }

    protected function nextCallOn(): Attribute
    {
        return Attribute::set(fn (mixed $value): ?string => Crm::dateString($value));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(CrmService::class, 'crm_service_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CrmSource::class, 'crm_source_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(CrmStatus::class, 'crm_status_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(LeadCall::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(LeadEmail::class);
    }

    public function latestCall(): HasOne
    {
        return $this->hasOne(LeadCall::class)->ofMany(['called_at' => 'max', 'id' => 'max']);
    }

    public function displayName(): string
    {
        return $this->name ?: __('Unnamed lead');
    }

    /**
     * Leads of the companies the user may access. Without `crm.leads.all` only the leads assigned to them.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('leads.company_id', $user->accessibleCompanyIds());
        if (! $user->hasPermission('crm.leads.all')) {
            $query->where('leads.assigned_to', $user->id);
        }
    }

    /**
     * Open leads with a follow-up in the bucket: `today`, `overdue` (before today) or `upcoming` (after today).
     * Leads in a closed status never need a call.
     */
    public function scopeFollowUp(Builder $query, string $bucket): void
    {
        $today = today()->toDateString();
        $query->whereNotNull('leads.next_call_on')
            ->whereHas('status', fn (Builder $status) => $status->where('is_closed', false))
            ->when($bucket === 'today', fn (Builder $leads) => $leads->where('leads.next_call_on', $today))
            ->when($bucket === 'overdue', fn (Builder $leads) => $leads->where('leads.next_call_on', '<', $today))
            ->when($bucket === 'upcoming', fn (Builder $leads) => $leads->where('leads.next_call_on', '>', $today));
    }
}
