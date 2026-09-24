<?php

namespace App\Models;

use App\Services\LedgerService;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'address', 'phone', 'is_active'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::created(fn (Company $company) => app(LedgerService::class)->createDefaultAccounts($company));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** Companies whose books the user may read or write. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasPermission('companies.all')) {
            $query->whereIn('id', $user->companies()->select('companies.id'));
        }
    }
}
