<?php

namespace Tests\Feature\Concerns;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\Party;
use App\Models\User;
use App\Services\DocumentService;

/**
 * Builds documents through DocumentService as $this->owner. Line shorthand: [description, quantity (units),
 * unit price (paisa), VAT (basis points), category code].
 *
 * @property User $owner
 */
trait MakesDocuments
{
    /**
     * @param  list<array{0: string, 1: int, 2: int, 3?: int, 4?: string}>  $lines
     * @param  array<string, mixed>  $extra
     */
    protected function draft(Company $company, DocumentType $type, ?Party $party, array $lines, array $extra = []): Document
    {
        $category = $type->isPurchase() ? '5400' : '4000';

        return app(DocumentService::class)->save(null, $company, $type, $extra + [
            'party_id' => $party?->id, 'issue_date' => '2026-09-01', 'due_date' => '2026-09-30', 'tax_inclusive' => false,
            'discount_type' => null, 'discount_value' => 0, 'custom_values' => [], 'post_to_accounts' => false,
            'lines' => array_map(fn (array $line): array => ['description' => $line[0], 'quantity' => $line[1] * 1000, 'unit_price' => $line[2],
                'tax_rate' => $line[3] ?? 0, 'discount_type' => null, 'discount_value' => 0,
                'account_id' => (int) $company->accounts()->where('code', $line[4] ?? $category)->value('id')], $lines),
        ], $this->owner);
    }

    /** @param list<array{0: string, 1: int, 2: int, 3?: int, 4?: string}> $lines */
    protected function issued(Company $company, DocumentType $type, ?Party $party, array $lines, array $extra = []): Document
    {
        return app(DocumentService::class)->issue($this->draft($company, $type, $party, $lines, $extra), $this->owner)->fresh();
    }

    /** The saved data of a document, in the shape save() takes, with overrides. */
    protected function dataOf(Document $document, array $overrides = []): array
    {
        return $overrides + [
            'party_id' => $document->party_id, 'issue_date' => $document->issue_date->toDateString(), 'due_date' => $document->due_date?->toDateString(),
            'tax_inclusive' => $document->tax_inclusive, 'discount_type' => $document->discount_type, 'discount_value' => $document->discount_value,
            'custom_values' => $document->custom_values ?? [], 'post_to_accounts' => $document->post_to_accounts,
            'lines' => $document->lines()->get()->map(fn ($line): array => $line->only(['item_id', 'account_id', 'description', 'quantity', 'unit',
                'unit_price', 'discount_type', 'discount_value', 'tax_rate']))->all(),
        ];
    }
}
