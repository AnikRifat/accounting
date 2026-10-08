<?php

namespace App\Models;

use App\Services\LedgerService;
use App\Support\Crm;
use App\Support\Modules;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

#[Fillable(['name', 'code', 'address', 'phone', 'mail_from_address', 'mail_from_name', 'is_active', 'sales_enabled', 'crm_enabled'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** Modules a company can switch off for itself => their column. Accounting and Organisation are always on. */
    public const MODULE_COLUMNS = [Modules::SALES => 'sales_enabled', Modules::CRM => 'crm_enabled'];

    protected $attributes = ['is_active' => true, 'sales_enabled' => true, 'crm_enabled' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sales_enabled' => 'boolean', 'crm_enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::created(function (Company $company): void {
            app(LedgerService::class)->createDefaultAccounts($company);
            Crm::createDefaults($company->id);
            Crm::createDefaultSources($company->id);
            PartyCategory::employeeCategoryId($company->id);
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Validation for the company form fields (name, code, address, phone, isActive, and on the full form the
     * mail sender). Normalise the code with strtoupper(trim()) before validating.
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

    /** @return array<string, array<int, string>> */
    public static function senderRules(): array
    {
        return [
            'mailFromAddress' => ['nullable', 'email', 'max:255'],
            'mailFromName' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * Creates a company from validated form data. A creator without all-company access is assigned to it,
     * so they can still see what they created.
     *
     * @param  array{name: string, code: string, address: ?string, phone: ?string, isActive: bool, mailFromAddress?: ?string, mailFromName?: ?string, salesEnabled?: bool, crmEnabled?: bool}  $data
     */
    public static function createBy(User $actor, array $data): self
    {
        return DB::transaction(function () use ($actor, $data): self {
            $company = self::create(['name' => $data['name'], 'code' => $data['code'], 'address' => $data['address'] ?: null,
                'phone' => $data['phone'] ?: null, 'mail_from_address' => ($data['mailFromAddress'] ?? null) ?: null,
                'mail_from_name' => ($data['mailFromName'] ?? null) ?: null, 'is_active' => $data['isActive'],
                'sales_enabled' => $data['salesEnabled'] ?? true, 'crm_enabled' => $data['crmEnabled'] ?? true]);
            if (! $actor->hasPermission('companies.all')) {
                $company->users()->attach($actor);
            }

            return $company;
        });
    }

    public function usesModule(string $module): bool
    {
        return ! isset(self::MODULE_COLUMNS[$module]) || $this->{self::MODULE_COLUMNS[$module]};
    }

    /** Companies that use the module; no filter for a module every company uses. */
    public function scopeUsingModule(Builder $query, ?string $module): void
    {
        if (isset(self::MODULE_COLUMNS[$module])) {
            $query->where('companies.'.self::MODULE_COLUMNS[$module], true);
        }
    }

    /** Companies whose books the user may read or write. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasPermission('companies.all')) {
            $query->whereIn('id', $user->companies()->select('companies.id'));
        }
    }
}
