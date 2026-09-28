<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Numbering (prefix, padding, last number), default template and default notes of one document type in a company. */
class DocumentSequence extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => DocumentType::class, 'padding' => 'integer', 'last_number' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    /** The company's sequence for a type, created with the default prefix on first use. */
    public static function for(int $companyId, DocumentType $type): self
    {
        return self::query()->firstOrCreate(['company_id' => $companyId, 'type' => $type], ['prefix' => $type->defaultPrefix(), 'padding' => 5]);
    }

    public function format(int $number): string
    {
        return $this->prefix.str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT);
    }
}
