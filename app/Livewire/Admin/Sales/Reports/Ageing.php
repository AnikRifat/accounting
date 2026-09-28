<?php

namespace App\Livewire\Admin\Sales\Reports;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Open invoices (or bills) of the header companies with something still owed, by party, bucketed by days overdue on
 * a date: Not due, 1–30, 31–60, 61–90 and over 90 days past the due date (the issue date when there is none).
 * Balances are those at the end of that date (Document::balanceAsOfExpression), so later payments and notes don't count.
 */
class Ageing extends Component
{
    public const BUCKETS = ['not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

    /** 'invoice' (receivables) or 'bill' (payables). */
    #[Url(except: 'invoice')]
    public string $kind = 'invoice';

    #[Url(except: '')]
    public string $asOf = '';

    public function render(): View
    {
        Gate::authorize('sales.reports');
        $context = app(CompanyContext::class);
        $type = $this->kind === 'bill' ? DocumentType::Bill : DocumentType::Invoice;
        $this->kind = $type->value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->asOf) !== 1 || ! checkdate((int) substr($this->asOf, 5, 2), (int) substr($this->asOf, 8, 2), (int) substr($this->asOf, 0, 4))) {
            $this->asOf = CarbonImmutable::today()->toDateString();
        }
        $asOf = CarbonImmutable::parse($this->asOf);

        [$balance, $bindings] = Document::balanceAsOfExpression($asOf->toDateString());
        $documents = Document::query()->select('documents.*')->selectRaw($balance.' as balance', $bindings)->whereIn('documents.company_id', $context->companyIds())->where('documents.type', $type)
            ->whereNotIn('documents.status', [DocumentStatus::Draft, DocumentStatus::Void])->where('documents.issue_date', '<=', $asOf->toDateString())
            ->whereRaw($balance.' > 0', $bindings)->with(['party:id,name', 'company:id,name,code'])->orderBy('documents.issue_date')->get();

        $parties = $documents->groupBy(fn (Document $document): string => $document->company_id.'-'.(int) $document->party_id)
            ->map(function (Collection $items) use ($asOf): array {
                $buckets = array_fill_keys(self::BUCKETS, 0);
                foreach ($items as $document) {
                    $buckets[self::bucket($document, $asOf)] += (int) $document->balance;
                }

                return ['party' => $items->first()->party, 'company' => $items->first()->company, 'count' => $items->count(), 'buckets' => $buckets, 'total' => array_sum($buckets)];
            })->sortBy([['company.name', 'asc'], ['party.name', 'asc']])->values();

        return view('livewire.admin.sales.reports.ageing', [
            'parties' => $parties,
            'totals' => collect(self::BUCKETS)->mapWithKeys(fn (string $bucket): array => [$bucket => (int) $parties->sum(fn (array $row): int => $row['buckets'][$bucket])])->all(),
            'bucketLabels' => ['not_due' => __('Not due'), 'days_1_30' => __('1–30 days'), 'days_31_60' => __('31–60 days'), 'days_61_90' => __('61–90 days'), 'days_90_plus' => __('Over 90 days')],
            'kindOptions' => ['invoice' => __('Invoices (owed to us)'), 'bill' => __('Bills (we owe)')],
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()->name,
            'showCompany' => $context->isAll(),
            'asOfLabel' => $asOf->format('d M Y'),
            'isPurchase' => $type->isPurchase(),
        ])->layout('layouts.admin');
    }

    /** The ageing bucket of an open document on a date, counted from its due date (or issue date). */
    public static function bucket(Document $document, CarbonImmutable $asOf): string
    {
        $reference = CarbonImmutable::parse(($document->due_date ?? $document->issue_date)->toDateString());
        if ($reference->gte($asOf)) {
            return 'not_due';
        }
        $days = (int) $reference->diffInDays($asOf);

        return match (true) {
            $days <= 30 => 'days_1_30',
            $days <= 60 => 'days_31_60',
            $days <= 90 => 'days_61_90',
            default => 'days_90_plus',
        };
    }
}
