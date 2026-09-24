<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\PaymentType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-company ledger account. `is_system` accounts are maintained by the application only.
 * A payment method is an `is_cash` asset account (with a payment_type); a category is a non-system
 * income or expense account.
 */
#[Fillable(['code', 'name', 'type', 'is_cash', 'payment_type', 'details', 'is_active'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $attributes = ['is_cash' => false, 'is_system' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['type' => AccountType::class, 'payment_type' => PaymentType::class, 'is_cash' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Cash, bank, mobile banking and other accounts that receive or pay money. */
    public function scopePaymentMethods(Builder $query): void
    {
        $query->where('type', AccountType::Asset)->where('is_cash', true);
    }

    /** Income or expense categories (non-system); pass a type to narrow to one side. */
    public function scopeCategories(Builder $query, ?AccountType $type = null): void
    {
        $query->where('is_system', false)->whereIn('type', $type ? [$type] : [AccountType::Income, AccountType::Expense]);
    }

    public function isPaymentMethod(): bool
    {
        return $this->type === AccountType::Asset && $this->is_cash;
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
