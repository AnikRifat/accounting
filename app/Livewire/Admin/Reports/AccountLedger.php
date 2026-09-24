<?php

namespace App\Livewire\Admin\Reports;

use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Posted lines per income or expense category of the header company context in a period. With no category chosen, one summary row
 * per category with a balance or activity; with a category, its lines with opening, running and closing balances. Payment methods,
 * receivables, payables and equity are left out for now.
 */
class AccountLedger extends Component
{
    use HasPeriod;

    /** Lines shown on one page; the closing balance is computed separately and is always complete. */
    public const ROW_LIMIT = 1000;

    #[Url(except: '')]
    public string $account = '';

    public function render(): View
    {
        Gate::authorize('reports.view');
        $context = app(CompanyContext::class);
        $companyIds = $context->companyIds();
        $accounts = Account::query()->whereIn('company_id', $companyIds)->categories()->with('company:id,code')->orderBy('code')->orderBy('company_id')->get();
        $account = $accounts->firstWhere('id', (int) $this->account);
        $this->account = (string) $account?->id;
        $range = $this->resolvePeriod();

        return view('livewire.admin.reports.account-ledger', [
            'accountOptions' => ['' => __('All accounts')] + $accounts->mapWithKeys(fn (Account $item): array => [$item->id => $item->label()
                .' ('.$item->type->label().')'.($context->isAll() ? ' · '.$item->company->code : '')])->all(),
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'hasCompanies' => $companyIds !== [],
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()?->name,
            'consolidated' => $context->isAll(),
            'selectedAccount' => $account,
            'showOpening' => $range !== null && $range[0] !== self::EARLIEST_DATE,
            'report' => $account && $range ? $this->ledger($account, $range[0], $range[1]) : null,
            'summary' => ! $account && $range ? $this->summary($accounts, $companyIds, $range[0], $range[1]) : null,
        ])->layout('layouts.admin');
    }

    /**
     * One row per account with an opening balance or lines in the period. Balances follow each account's normal side.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  list<int>  $companyIds
     * @return array{rows: Collection<int, array{account: Account, opening: int, debit: int, credit: int, closing: int}>, debit: int, credit: int}
     */
    private function summary(Collection $accounts, array $companyIds, string $from, string $to): array
    {
        $totals = JournalLine::query()->toBase()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.company_id', $companyIds)
            ->whereNull('journal_entries.voided_at')
            ->where('journal_entries.entry_date', '<=', $to)
            ->groupBy('journal_lines.account_id')->select('journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END), 0) as opening_net', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_lines.debit ELSE 0 END), 0) as period_debit', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_lines.credit ELSE 0 END), 0) as period_credit', [$from])
            ->get()->keyBy('account_id');

        $rows = $accounts->filter(fn (Account $account): bool => $totals->has($account->id))->map(function (Account $account) use ($totals): array {
            $total = $totals[$account->id];
            $sign = $account->type->isDebitNormal() ? 1 : -1;
            [$opening, $debit, $credit] = [$sign * (int) $total->opening_net, (int) $total->period_debit, (int) $total->period_credit];

            return ['account' => $account, 'opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => $opening + $sign * ($debit - $credit)];
        })->filter(fn (array $row): bool => $row['opening'] !== 0 || $row['debit'] !== 0 || $row['credit'] !== 0)->values();

        return ['rows' => $rows, 'debit' => $rows->sum('debit'), 'credit' => $rows->sum('credit')];
    }

    /** @return array{opening: int, closing: int, debit: int, credit: int, rows: list<array{entry: JournalEntry, debit: int, credit: int, balance: int}>, truncated: bool} */
    private function ledger(Account $account, string $from, string $to): array
    {
        $opening = app(LedgerService::class)->balance($account, CarbonImmutable::parse($from)->subDay());
        $sign = $account->type->isDebitNormal() ? 1 : -1;
        $posted = JournalEntry::query()->posted()->where('company_id', $account->company_id)->whereBetween('entry_date', [$from, $to])->select('id');
        $totals = JournalLine::query()->where('account_id', $account->id)->whereIn('journal_entry_id', $posted)
            ->toBase()->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')->first();
        $lines = JournalEntry::query()->posted()->where('journal_entries.company_id', $account->company_id)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.account_id', $account->id)
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->limit(self::ROW_LIMIT + 1)
            ->get(['journal_entries.id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.type', 'journal_entries.description',
                'journal_entries.reference', 'journal_lines.debit as line_debit', 'journal_lines.credit as line_credit']);

        $balance = $opening;
        $rows = [];
        foreach ($lines->take(self::ROW_LIMIT) as $entry) {
            $debit = (int) $entry->line_debit;
            $credit = (int) $entry->line_credit;
            $balance += $sign * ($debit - $credit);
            $rows[] = ['entry' => $entry, 'debit' => $debit, 'credit' => $credit, 'balance' => $balance];
        }
        $debitTotal = (int) $totals->debit_total;
        $creditTotal = (int) $totals->credit_total;

        return ['opening' => $opening, 'closing' => $opening + $sign * ($debitTotal - $creditTotal), 'debit' => $debitTotal,
            'credit' => $creditTotal, 'rows' => $rows, 'truncated' => $lines->count() > self::ROW_LIMIT];
    }
}
