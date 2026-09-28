<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A priced line of a document. amount = quantity × unit price; discount includes the line's share of the document
 * discount; net + tax = total. All written by App\Services\DocumentService from App\Support\DocumentMath.
 */
class DocumentLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'discount_value' => 'integer', 'tax_rate' => 'integer',
            'amount' => 'integer', 'discount' => 'integer', 'net' => 'integer', 'tax' => 'integer', 'total' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
