<?php

namespace App\Livewire\Admin\Sales\Reports;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\SystemAccount;
use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Models\Account;
use App\Models\Company;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Output VAT of the header companies for a period: issued, non-void invoices minus issued, non-void credit notes, by
 * month and by line VAT rate, with the taxable net. Reconciled with the books: the VAT Payable account's net credit
 * movement in the period (LedgerService::periodActivity) equals the VAT of the posted documents, as long as nothing
 * else (such as a VAT payment to NBR) was entered against that account. Unposted documents are not in the books.
 * Months come from the Y-m-d issue date in PHP, so the SQL stays portable.
 */
class Vat extends Component
{
    use HasPeriod;

    public function render(): View
    {
        Gate::authorize('sales.reports');
        $context = app(CompanyContext::class);
        $companyIds = $context->companyIds();
        $range = $this->resolvePeriod();
        $lines = $range === null ? collect() : $this->lines($companyIds, $range);

        $sum = fn (Collection $rows): array => ['net' => (int) $rows->sum('net'), 'tax' => (int) $rows->sum('tax')];
        $byRate = $lines->groupBy('rate')->map($sum)->sortKeys();
        $byMonth = $lines->groupBy(fn (object $row): string => substr($row->issue_date, 0, 7))->map($sum)->sortKeys();
        $postedTax = (int) $lines->where('posted', true)->sum('tax');

        $vatAccounts = Account::query()->whereIn('company_id', $companyIds)->where('system_key', SystemAccount::VatPayable)->pluck('company_id', 'id');
        $ledger = $range === null ? collect() : app(LedgerService::class)->periodActivity($companyIds, $range[0], $range[1], [AccountType::Liability])
            ->filter(fn (object $row): bool => $vatAccounts->has($row->account_id));
        $ledgerByCompany = $ledger->groupBy('company_id')->map(fn (Collection $rows): int => (int) $rows->sum('credit_total') - (int) $rows->sum('debit_total'));

        $companies = $context->isAll() ? Company::query()->whereKey($companyIds)->orderBy('name')->get()->map(fn (Company $company): array => [
            'company' => $company,
            ...$sum($lines->where('company_id', $company->id)),
            'posted' => (int) $lines->where('company_id', $company->id)->where('posted', true)->sum('tax'),
            'ledger' => (int) ($ledgerByCompany[$company->id] ?? 0),
        ])->filter(fn (array $row): bool => $row['net'] !== 0 || $row['tax'] !== 0 || $row['ledger'] !== 0)->values() : collect();

        return view('livewire.admin.sales.reports.vat', [
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()->name,
            'byRate' => $byRate,
            'byMonth' => $byMonth,
            'total' => $sum($lines),
            'postedTax' => $postedTax,
            'ledgerTax' => (int) $ledgerByCompany->sum(),
            'companies' => $companies,
        ])->layout('layouts.admin');
    }

    /**
     * Signed line totals (credit notes negative) per company, type, issue date, VAT rate and posted state.
     *
     * @param  list<int>  $companyIds
     * @param  array{0: string, 1: string}  $range
     * @return Collection<int, object{company_id: int, issue_date: string, rate: int, posted: bool, net: int, tax: int}>
     */
    private function lines(array $companyIds, array $range): Collection
    {
        $query = fn (bool $posted) => DB::table('document_lines')->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->whereIn('documents.company_id', $companyIds)->whereIn('documents.type', [DocumentType::Invoice->value, DocumentType::CreditNote->value])
            ->whereNotIn('documents.status', [DocumentStatus::Draft->value, DocumentStatus::Void->value])
            ->whereBetween('documents.issue_date', $range)
            ->when($posted, fn ($q) => $q->whereNotNull('documents.journal_entry_id'), fn ($q) => $q->whereNull('documents.journal_entry_id'))
            ->groupBy('documents.company_id', 'documents.type', 'documents.issue_date', 'document_lines.tax_rate')
            ->select('documents.company_id', 'documents.type', 'documents.issue_date', 'document_lines.tax_rate')
            ->selectRaw('SUM(document_lines.net) as net_total, SUM(document_lines.tax) as tax_total')
            ->get()
            ->map(function (object $row) use ($posted): object {
                $sign = $row->type === DocumentType::CreditNote->value ? -1 : 1;

                return (object) ['company_id' => (int) $row->company_id, 'issue_date' => substr((string) $row->issue_date, 0, 10), 'rate' => (int) $row->tax_rate,
                    'posted' => $posted, 'net' => $sign * (int) $row->net_total, 'tax' => $sign * (int) $row->tax_total];
            });

        return $query(true)->concat($query(false))->values();
    }

    public static function monthLabel(string $month): string
    {
        return CarbonImmutable::parse($month.'-01')->format('M Y');
    }
}
