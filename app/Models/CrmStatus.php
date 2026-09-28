<?php

namespace App\Models;

use App\Enums\CrmStatusType;
use Database\Factories\CrmStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead status (where a lead stands; closed ones end follow-ups) or a call result (how one call went).
 * Per company; defaults come from App\Support\Crm::DEFAULT_STATUSES.
 */
#[Fillable(['company_id', 'type', 'name', 'tone', 'is_closed', 'position', 'is_active'])]
class CrmStatus extends Model
{
    /** @use HasFactory<CrmStatusFactory> */
    use HasFactory;

    protected $attributes = ['tone' => 'neutral', 'is_closed' => false, 'position' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['type' => CrmStatusType::class, 'is_closed' => 'boolean', 'is_active' => 'boolean', 'position' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeLead(Builder $query): void
    {
        $query->where('type', CrmStatusType::Lead->value);
    }

    public function scopeCall(Builder $query): void
    {
        $query->where('type', CrmStatusType::Call->value);
    }

    /** Statuses of the companies the user may access. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', $user->accessibleCompanyIds());
    }

    /** How many leads and calls point at this status; a used status can only be deactivated. */
    public function usageCount(): int
    {
        return Lead::query()->where('crm_status_id', $this->id)->count()
            + LeadCall::query()->where(fn (Builder $calls) => $calls->where('lead_status_id', $this->id)->orWhere('call_status_id', $this->id))->count();
    }
}
