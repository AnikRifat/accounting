<?php

namespace App\Models;

use App\Concerns\HasMedia;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A login account. Everyone except the single super admin is an employee: staff details live here and
 * each assigned company holds a party for them (see syncParties()). Staff fields are not fillable.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['role' => 'member', 'is_active' => true, 'extra_roles' => '[]', 'denied_permissions' => '[]', 'monthly_salary' => 0];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasMedia, Notifiable;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed',
            'is_active' => 'boolean', 'extra_roles' => 'array', 'denied_permissions' => 'array',
            'monthly_salary' => 'integer', 'joined_on' => 'date'];
    }

    /** Stores a plain Y-m-d so date comparisons behave the same on SQLite and MySQL. */
    protected function joinedOn(): Attribute
    {
        return Attribute::set(fn (mixed $value): ?string => $value === null || $value === '' ? null : Carbon::parse($value)->toDateString());
    }

    /** The super admin: passes every permission check and sees every company. There is only one. */
    public function isRoot(): bool
    {
        return $this->role === Permissions::ROOT_ROLE;
    }

    public function hasPermission(string $permission): bool
    {
        return app(Permissions::class)->allows($this, $permission);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    /**
     * Keeps one party per assigned company, with the user's name, phone and active status. Parties of
     * companies no longer assigned are deactivated, never deleted, because entries may point at them.
     * The super admin is not an employee and has no parties. Call it after saving the user and its companies.
     */
    public function syncParties(): void
    {
        $companyIds = $this->isRoot() ? [] : $this->companies()->pluck('companies.id')->map(fn (mixed $id): int => (int) $id)->all();
        foreach ($companyIds as $companyId) {
            $this->parties()->firstOrNew(['company_id' => $companyId])
                ->fill(['name' => $this->name, 'phone' => $this->phone, 'is_active' => $this->is_active])->save();
        }
        $this->parties()->whereNotIn('company_id', $companyIds)->where('is_active', true)->update(['is_active' => false]);
    }

    /** @return list<int> */
    public function accessibleCompanyIds(): array
    {
        return Company::visibleTo($this)->pluck('id')->all();
    }

    public function canAccessCompany(int $companyId): bool
    {
        return Company::visibleTo($this)->whereKey($companyId)->exists();
    }
}
