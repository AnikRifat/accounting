<?php

namespace App\Models;

use App\Support\Modules;
use Database\Factories\CrmServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What a lead is interested in (a product or service the company sells). Per company. */
#[Fillable(['company_id', 'name', 'is_active'])]
class CrmService extends Model
{
    /** @use HasFactory<CrmServiceFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** Services of the companies the user may access. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', $user->accessibleCompanyIds(Modules::CRM));
    }
}
