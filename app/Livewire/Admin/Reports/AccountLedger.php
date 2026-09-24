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
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Posted lines of one account of the header company in a period, with opening, running and closing balances. Needs one company. */
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
        $company = $context->isAll() ? null : $context->company();
        $accounts = $company ? Account::query()->where('company_id', $company->id)->orderBy('code')->get() : collect();
        $account = $accounts->firstWhere('id', (int) $this->account);
        $this->account = (string) $account?->id;
        $range = $this->resolvePeriod();

        return view('livewire.admin.reports.account-ledger', [
            'accountOptions' => ['' => __('Choose an account')] + $accounts->mapWithKeys(fn (Account $item): array => [$item->id => $item->label().' ('.$item->type->label().')'])->all(),
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'hasCompanies' => $context->options()->isNotEmpty(),
            'selectedCompany' => $company,
            'selectedAccount' => $account,
            'chooseCompanyUrl' => route('admin.choose-company', ['next' => route('admin.reports.account-ledger', $this->periodQuery(), false)]),
            'report' => $account && $range ? $this->ledger($account, $range[0], $range[1]) : null,
        ])->layout('layouts.admin');
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
