<?php

namespace App\Livewire\Admin\Sales\Reports;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Livewire\Concerns\WithTableTools;
use App\Models\Document;
use App\Support\CompanyContext;
use App\Support\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Issued documents of one type in a period for the header companies: invoices by default. Drafts never appear;
 * void documents are listed struck through and left out of the totals. Paid means paid or credited (total − balance).
 */
class Register extends Component
{
    use HasPeriod, WithTableTools;

    #[Url(except: 'invoice')]
    public string $type = 'invoice';

    public function render(): View
    {
        Gate::authorize('sales.reports');
        $type = $this->documentType();
        $range = $this->resolvePeriod();
        $documents = $range === null ? collect() : $this->tableQuery()->with(['party:id,name', 'company:id,name,code'])->get();
        $counted = $documents->reject(fn (Document $document): bool => $document->isVoid());

        return view('livewire.admin.sales.reports.register', [
            'documents' => $documents,
            'documentType' => $type,
            'typeOptions' => collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $case): array => [$case->value => $case->pluralLabel()])->all(),
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'scopeLabel' => $this->companyScopeLabel(),
            'showCompany' => app(CompanyContext::class)->isAll(),
            'totals' => [
                'subtotal' => (int) $counted->sum('subtotal'), 'discount' => (int) $counted->sum('discount_total'), 'tax' => (int) $counted->sum('tax_total'),
                'total' => (int) $counted->sum('total'), 'paid' => (int) $counted->sum(fn (Document $document): int => $this->paid($document) ?? 0),
                'balance' => (int) $counted->sum(fn (Document $document): int => $document->balance() ?? 0),
            ],
        ])->layout('layouts.admin');
    }

    /** What was paid or credited on an open invoice or bill; null for other documents. */
    public function paid(Document $document): ?int
    {
        $balance = $document->balance();

        return $balance === null ? null : $document->total - $balance;
    }

    protected function tableQuery(): Builder
    {
        $range = $this->resolvePeriod() ?? [self::LATEST_DATE, self::EARLIEST_DATE];

        return Document::query()->withBalance()->whereIn('documents.company_id', app(CompanyContext::class)->companyIds())
            ->where('documents.type', $this->documentType())->where('documents.status', '!=', DocumentStatus::Draft)
            ->whereBetween('documents.issue_date', $range)->orderBy('documents.issue_date')->orderBy('documents.id');
    }

    protected function tableExport(): TableExport
    {
        $type = $this->documentType();

        return new TableExport(__(':type register', ['type' => $type->label()]), [
            'number' => ['label' => __('Number'), 'value' => fn (Document $document): string => (string) $document->number],
            'date' => ['label' => __('Date'), 'value' => fn (Document $document) => $document->issue_date, 'type' => 'date'],
            'company' => ['label' => __('Company'), 'value' => fn (Document $document): ?string => $document->company?->name],
            'party' => ['label' => $type->isPurchase() ? __('Supplier') : __('Customer'), 'value' => fn (Document $document): ?string => $document->party?->name],
            'subtotal' => ['label' => __('Subtotal'), 'value' => fn (Document $document): int => $document->subtotal, 'type' => 'money'],
            'discount' => ['label' => __('Discount'), 'value' => fn (Document $document): int => $document->discount_total, 'type' => 'money'],
            'tax' => ['label' => __('VAT'), 'value' => fn (Document $document): int => $document->tax_total, 'type' => 'money'],
            'total' => ['label' => __('Total'), 'value' => fn (Document $document): int => $document->total, 'type' => 'money'],
            'paid' => ['label' => __('Paid'), 'value' => fn (Document $document): ?int => $this->paid($document), 'type' => 'money'],
            'balance' => ['label' => __('Balance'), 'value' => fn (Document $document): ?int => $document->balance(), 'type' => 'money'],
            'status' => ['label' => __('Status'), 'value' => fn (Document $document): string => $document->dueStatus()?->label() ?? $document->status->label()],
            'posted' => ['label' => __('Posted'), 'value' => fn (Document $document): string => $document->isPosted() ? __('Yes') : __('No')],
        ], $this->companyScopeLabel().' · '.$this->periodLabel($this->resolvePeriod()));
    }

    private function documentType(): DocumentType
    {
        $type = DocumentType::tryFrom($this->type);
        if ($type === null) {
            $this->type = DocumentType::Invoice->value;
        }

        return $type ?? DocumentType::Invoice;
    }
}
