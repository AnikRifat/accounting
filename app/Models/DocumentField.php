<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company's own extra field on documents (all types, or one); values live in documents.custom_values keyed by id. */
#[Fillable(['company_id', 'document_type', 'label', 'kind', 'is_required', 'is_active', 'sort'])]
class DocumentField extends Model
{
    public const KINDS = ['text', 'number', 'date'];

    protected $attributes = ['is_required' => false, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['document_type' => DocumentType::class, 'is_required' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Active fields a document of this type and company shows, in order. */
    public function scopeFor(Builder $query, int $companyId, DocumentType $type): void
    {
        $query->where('company_id', $companyId)->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('document_type')->orWhere('document_type', $type))
            ->orderBy('sort')->orderBy('id');
    }

    /** @return list<string> validation rules for a value of this field */
    public function rules(): array
    {
        return [$this->is_required ? 'required' : 'nullable', ...match ($this->kind) {
            'number' => ['numeric', 'max:999999999999'],
            'date' => ['date_format:Y-m-d'],
            default => ['string', 'max:255'],
        }];
    }
}
