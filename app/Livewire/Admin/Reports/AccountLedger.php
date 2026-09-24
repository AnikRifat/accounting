<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\AccountType;
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
 * Posted lines on the income and expense categories of the header company context in a period, optionally narrowed to one type.
 * With no category chosen, every line across those categories; with a category, its lines with opening, running and closing
 * balances. Payment methods, receivables, payables and equity are left out for now.
 */
class AccountLedger extends Component
{
    use HasPeriod;

    /** Lines shown on one page; the closing balance is computed separately and is always complete. */
    public const ROW_LIMIT = 1000;

    #[Url(except: '')]
    public string $account = '';

    #[Url(except: '')]
    public string $type = '';

    public function render(): View
    {
        Gate::authorize('reports.view');
        $context = app(CompanyContext::class);
        $companyIds = $context->companyIds();
        $type = in_array($this->type, [AccountType::Income->value, AccountType::Expense->value], true) ? AccountType::from($this->type) : null;
        $this->type = (string) $type?->value;
        $accounts = Account::query()->whereIn('company_id', $companyIds)->categories($type)->with('company:id,code')->orderBy('code')->orderBy('company_id')->get();
        $account = $accounts->firstWhere('id', (int) $this->account);
        $this->account = (string) $account?->id;
        $range = $this->resolvePeriod();

        return view('livewire.admin.reports.account-ledger', [
            'accountOptions' => ['' => __('All accounts')] + $accounts->mapWithKeys(fn (Account $item): array => [$item->id => $item->label()
                .' ('.$item->type->label().')'.($context->isAll() ? ' · '.$item->company->code : '')])->all(),
            'typeOptions' => ['' => __('All types'), AccountType::Income->value => AccountType::Income->label(), AccountType::Expense->value => AccountType::Expense->label()],
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'hasCompanies' => $companyIds !== [],
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()?->name,
            'consolidated' => $context->isAll(),
            'selectedAccount' => $account,
            'showOpening' => $range !== null && $range[0] !== self::EARLIEST_DATE,
            'report' => $account && $range ? $this->ledger($account, $range[0], $range[1]) : null,
            'lines' => ! $account && $range ? $this->lines($accounts, $companyIds, $type, $range[0], $range[1]) : null,
        ])->layout('layouts.admin');
    }

    /**
     * Every posted line on the given categories in the period, oldest first, with a running balance. Across both types the balance is
     * net profit (income less expense); narrowed to one type it follows that type's normal side. Totals and the closing balance are
     * computed separately and are always complete.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  list<int>  $companyIds
     * @return array{opening: int, closing: int, rows: list<array{entry: JournalEntry, account: Account, counter: Collection<int, Account>, debit: int, credit: int, balance: int}>, debit: int, credit: int, truncated: bool}
     */
    private function lines(Collection $accounts, array $companyIds, ?AccountType $type, string $from, string $to): array
    {
        $byId = $accounts->keyBy('id');
        $sign = $type?->isDebitNormal() ? 1 : -1;
        $before = JournalEntry::query()->posted()->whereIn('company_id', $companyIds)->where('entry_date', '<', $from)->select('id');
        $opening = $sign * (int) JournalLine::query()->whereIn('account_id', $byId->keys())->whereIn('journal_entry_id', $before)
            ->toBase()->selectRaw('COALESCE(SUM(debit - credit), 0) as net')->value('net');
        $posted = JournalEntry::query()->posted()->whereIn('company_id', $companyIds)->whereBetween('entry_date', [$from, $to])->select('id');
        $totals = JournalLine::query()->whereIn('account_id', $byId->keys())->whereIn('journal_entry_id', $posted)
            ->toBase()->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')->first();
        $lines = JournalEntry::query()->posted()->whereIn('journal_entries.company_id', $companyIds)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereIn('journal_lines.account_id', $byId->keys())
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->limit(self::ROW_LIMIT + 1)
            ->get(['journal_entries.id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.created_at', 'journal_entries.type',
                'journal_entries.description', 'journal_entries.reference', 'journal_lines.account_id', 'journal_lines.debit as line_debit',
                'journal_lines.credit as line_credit']);

        $shown = $lines->take(self::ROW_LIMIT);
        $counters = $this->counterLines($shown);
        $balance = $opening;
        $rows = [];
        foreach ($shown as $entry) {
            $debit = (int) $entry->line_debit;
            $credit = (int) $entry->line_credit;
            $balance += $sign * ($debit - $credit);
            $rows[] = ['entry' => $entry, 'account' => $byId[$entry->account_id], 'counter' => $this->counterAccounts($counters, $entry->id, $entry->account_id),
                'debit' => $debit, 'credit' => $credit, 'balance' => $balance];
        }
        $debitTotal = (int) $totals->debit_total;
        $creditTotal = (int) $totals->credit_total;

        return ['opening' => $opening, 'closing' => $opening + $sign * ($debitTotal - $creditTotal), 'rows' => $rows, 'debit' => $debitTotal,
            'credit' => $creditTotal, 'truncated' => $lines->count() > self::ROW_LIMIT];
    }

    /** @return array{opening: int, closing: int, debit: int, credit: int, rows: list<array{entry: JournalEntry, counter: Collection<int, Account>, debit: int, credit: int, balance: int}>, truncated: bool} */
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
            ->get(['journal_entries.id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.created_at', 'journal_entries.type',
                'journal_entries.description', 'journal_entries.reference', 'journal_lines.debit as line_debit', 'journal_lines.credit as line_credit']);

        $shown = $lines->take(self::ROW_LIMIT);
        $counters = $this->counterLines($shown);
        $balance = $opening;
        $rows = [];
        foreach ($shown as $entry) {
            $debit = (int) $entry->line_debit;
            $credit = (int) $entry->line_credit;
            $balance += $sign * ($debit - $credit);
            $rows[] = ['entry' => $entry, 'counter' => $this->counterAccounts($counters, $entry->id, $account->id), 'debit' => $debit, 'credit' => $credit, 'balance' => $balance];
        }
        $debitTotal = (int) $totals->debit_total;
        $creditTotal = (int) $totals->credit_total;

        return ['opening' => $opening, 'closing' => $opening + $sign * ($debitTotal - $creditTotal), 'debit' => $debitTotal,
            'credit' => $creditTotal, 'rows' => $rows, 'truncated' => $lines->count() > self::ROW_LIMIT];
    }

    /**
     * Every line of the shown entries with its account, grouped by entry, for the Account column.
     *
     * @param  Collection<int, JournalEntry>  $entries
     * @return Collection<int, Collection<int, JournalLine>>
     */
    private function counterLines(Collection $entries): Collection
    {
        return JournalLine::query()->whereIn('journal_entry_id', $entries->pluck('id')->unique()->values())
            ->with('account:id,code,name')->orderBy('id')->get(['id', 'journal_entry_id', 'account_id'])->groupBy('journal_entry_id');
    }

    /**
     * The other side of an entry: the accounts it posted to besides the ledger's own category (cash, bank, receivable or payable).
     *
     * @param  Collection<int, Collection<int, JournalLine>>  $counters
     * @return Collection<int, Account>
     */
    private function counterAccounts(Collection $counters, int $entryId, int $accountId): Collection
    {
        return $counters->get($entryId, collect())->where('account_id', '!=', $accountId)->pluck('account')->unique('id')->values();
    }
}
