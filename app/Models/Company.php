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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    /**
     * Validation for the company form fields (name, code, address, phone, isActive). Normalise the code
     * with strtoupper(trim()) before validating.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function formRules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'between:2,10', 'regex:/^[A-Z0-9]+$/', Rule::unique('companies', 'code')->ignore($ignoreId)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'isActive' => ['boolean'],
        ];
    }

    /**
     * Creates a company from validated form data. A creator without all-company access is assigned to it,
     * so they can still see what they created.
     *
     * @param  array{name: string, code: string, address: ?string, phone: ?string, isActive: bool}  $data
     */
    public static function createBy(User $actor, array $data): self
    {
        return DB::transaction(function () use ($actor, $data): self {
            $company = self::create(['name' => $data['name'], 'code' => $data['code'], 'address' => $data['address'] ?: null,
                'phone' => $data['phone'] ?: null, 'is_active' => $data['isActive']]);
            if (! $actor->hasPermission('companies.all')) {
                $company->users()->attach($actor);
            }

            return $company;
        });
    }

    /** Companies whose books the user may read or write. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasPermission('companies.all')) {
            $query->whereIn('id', $user->companies()->select('companies.id'));
        }
    }
}
