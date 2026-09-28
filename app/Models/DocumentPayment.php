<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A payment recorded on an invoice or bill that is not posted to the books. */
class DocumentPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'integer'];
    }

    protected function paidOn(): Attribute
    {
        return Attribute::set(fn (mixed $value): string => Carbon::parse($value)->toDateString());
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** The payment method the money moved through. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
