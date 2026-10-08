<?php

namespace App\Models;

use App\Support\Modules;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A product or service a company sells, with its category, price (paisa), VAT rate (basis points) and income category. */
#[Fillable(['company_id', 'item_category_id', 'name', 'description', 'unit', 'price', 'tax_rate', 'account_id', 'is_active'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true, 'tax_rate' => 0];

    protected function casts(): array
    {
        return ['price' => 'integer', 'tax_rate' => 'integer', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Items of the companies the user may access. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', $user->accessibleCompanyIds(Modules::SALES));
    }
}
