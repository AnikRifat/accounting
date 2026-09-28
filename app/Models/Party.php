<?php

namespace App\Models;

use App\Concerns\HasMedia;
use App\Concerns\HasPhoto;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who paid, received or was spent on. Employee parties belong to a user and are created and kept in
 * sync by User::syncParties(); `user_id` is deliberately not fillable.
 */
#[Fillable(['company_id', 'party_category_id', 'name', 'phone', 'email', 'address', 'notes', 'is_active'])]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory, HasMedia, HasPhoto {
        photoUrl as ownPhotoUrl;
    }

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PartyCategory::class, 'party_category_id');
    }

    /** The employee this party stands for. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The party's own photo, or for an employee party the employee's photo. */
    public function photoUrl(): ?string
    {
        return $this->ownPhotoUrl() ?? ($this->isEmployee() ? $this->user?->photoUrl() : null);
    }

    public function isEmployee(): bool
    {
        return $this->user_id !== null;
    }

    /** Parties of the companies the user may access. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', $user->accessibleCompanyIds());
    }
}
