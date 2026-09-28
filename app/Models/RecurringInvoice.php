<?php

namespace App\Models;

use App\Enums\RecurringFrequency;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A schedule that copies an invoice (its party, lines and terms) into a new draft on every run date.
 * Written by App\Services\RecurringInvoices; each run is unique per schedule and period.
 */
class RecurringInvoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['frequency' => RecurringFrequency::class, 'day' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date',
            'next_run_on' => 'date', 'last_run_on' => 'date', 'is_active' => 'boolean'];
    }

    protected function nextRunOn(): Attribute
    {
        return Attribute::set(fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
