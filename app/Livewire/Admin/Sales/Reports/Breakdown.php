<?php

namespace App\Livewire\Admin\Sales\Reports;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Models\Company;
use App\Models\Item;
use App\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sales by customer and by item (issued, non-void invoices minus credit notes), or purchases by supplier (bills minus
 * debit notes), for the header companies in a period. Net is before VAT and after discounts. Lines without an item
 * are grouped as "Other items".
 */
class Breakdown extends Component
{
    use HasPeriod;

    /** 'sales' or 'purchases'. */
    #[Url(except: 'sales')]
    public string $side = 'sales';

    public function render(): View
    {
        Gate::authorize('sales.reports');
        $context = app(CompanyContext::class);
        $this->side = $this->side === 'purchases' ? 'purchases' : 'sales';
        [$main, $note] = $this->side === 'sales' ? [DocumentType::Invoice, DocumentType::CreditNote] : [DocumentType::Bill, DocumentType::DebitNote];
        $range = $this->resolvePeriod();
        $companyIds = $context->companyIds();

        $parties = $range === null ? collect() : $this->byParty($companyIds, $range, $main, $note);
        $items = $range === null || $this->side === 'purchases' ? collect() : $this->byItem($companyIds, $range, $note);
        $total = fn (Collection $rows): array => ['net' => (int) $rows->sum('net'), 'tax' => (int) $rows->sum('tax'), 'total' => (int) $rows->sum('total')];

        return view('livewire.admin.sales.reports.breakdown', [
            'sideOptions' => ['sales' => __('Sales (invoices less credit notes)'), 'purchases' => __('Purchases (bills less debit notes)')],
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()->name,
            'showCompany' => $context->isAll(),
            'parties' => $parties,
            'partyTotals' => $total($parties),
            'items' => $items,
            'itemTotals' => $total($items),
            'isSales' => $this->side === 'sales',
        ])->layout('layouts.admin');
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{0: string, 1: string}  $range
     * @return Collection<int, array{company: ?string, name: string, count: int, net: int, tax: int, total: int}>
     */
    private function byParty(array $companyIds, array $range, DocumentType $main, DocumentType $note): Collection
    {
        $rows = $this->documents($companyIds, $range, [$main, $note])
            ->groupBy('documents.company_id', 'documents.party_id', 'documents.type')
            ->select('documents.company_id', 'documents.party_id', 'documents.type')
            ->selectRaw('COUNT(*) as documents_count, SUM(documents.total - documents.tax_total) as net_total, SUM(documents.tax_total) as tax_total, SUM(documents.total) as grand_total')
            ->get();
        $names = Party::query()->whereKey($rows->pluck('party_id')->filter()->unique()->all())->pluck('name', 'id');

        return $this->combine($rows, 'party_id', $note, fn (?int $id): string => $names[$id] ?? __('No party'),
            fn (object $row): int => $row->type === $note->value ? 0 : (int) $row->documents_count);
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{0: string, 1: string}  $range
     * @return Collection<int, array{company: ?string, name: string, quantity: int, net: int, tax: int, total: int}>
     */
    private function byItem(array $companyIds, array $range, DocumentType $note): Collection
    {
        $rows = $this->documents($companyIds, $range, [DocumentType::Invoice, $note])
            ->join('document_lines', 'document_lines.document_id', '=', 'documents.id')
            ->groupBy('documents.company_id', 'document_lines.item_id', 'documents.type')
            ->select('documents.company_id', 'document_lines.item_id', 'documents.type')
            ->selectRaw('SUM(document_lines.quantity) as quantity_total, SUM(document_lines.net) as net_total, SUM(document_lines.tax) as tax_total, SUM(document_lines.total) as grand_total')
            ->get();
        $names = Item::query()->whereKey($rows->pluck('item_id')->filter()->unique()->all())->pluck('name', 'id');

        return $this->combine($rows, 'item_id', $note, fn (?int $id): string => $id !== null && isset($names[$id]) ? $names[$id] : __('Other items'),
            fn (object $row): int => ($row->type === $note->value ? -1 : 1) * (int) $row->quantity_total);
    }

    /**
     * Nets note rows against their documents per company and key, largest total first.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function combine(Collection $rows, string $key, DocumentType $note, callable $name, callable $count): Collection
    {
        $companies = Company::query()->whereKey($rows->pluck('company_id')->unique()->all())->pluck('name', 'id');

        return $rows->groupBy(fn (object $row): string => $row->company_id.'-'.($row->{$key} ?? 'none'))->map(function (Collection $group) use ($key, $note, $name, $count, $companies): array {
            $signed = fn (string $column): int => (int) $group->sum(fn (object $row): int => ($row->type === $note->value ? -1 : 1) * (int) $row->{$column});
            $first = $group->first();

            return ['company' => $companies[$first->company_id] ?? null, 'name' => $name($first->{$key} === null ? null : (int) $first->{$key}),
                'count' => (int) $group->sum($count), 'net' => $signed('net_total'), 'tax' => $signed('tax_total'), 'total' => $signed('grand_total')];
        })->sortBy([['total', 'desc'], ['name', 'asc']])->values();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{0: string, 1: string}  $range
     * @param  list<DocumentType>  $types
     */
    private function documents(array $companyIds, array $range, array $types): Builder
    {
        return DB::table('documents')->whereIn('documents.company_id', $companyIds)
            ->whereIn('documents.type', array_map(fn (DocumentType $type): string => $type->value, $types))
            ->whereNotIn('documents.status', [DocumentStatus::Draft->value, DocumentStatus::Void->value])
            ->whereBetween('documents.issue_date', $range);
    }
}
