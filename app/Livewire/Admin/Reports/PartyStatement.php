<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every posted bill and settlement of one party of the header company, with a running due. Needs one company. Debit raises what the party owes us
 * (an income bill's total, a payment we made); credit lowers it (money received, an expense bill's total).
 * A positive balance means the party owes us; a negative one means we owe the party.
 */
class PartyStatement extends Component
{
    use HasPeriod;

    /** Lines shown on one page; opening and closing dues are computed separately and are always complete. */
    public const ROW_LIMIT = 1000;

    #[Url(except: '')]
    public string $party = '';

    public function mount(): void
    {
        if ($this->party !== '' && ! array_key_exists('period', request()->query())) {
            $this->period = 'this_fiscal_year';
        }
    }

    public function render(): View
    {
        Gate::authorize('reports.view');
        Gate::authorize('parties.view');
        $context = app(CompanyContext::class);
        $company = $context->isAll() ? null : $context->company();
        $parties = $company ? Party::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'company_id', 'name', 'phone', 'employee_id']) : collect();
        $party = $parties->firstWhere('id', (int) $this->party);
        $this->party = (string) $party?->id;
        $range = $this->resolvePeriod();

        return view('livewire.admin.reports.party-statement', [
            'partyOptions' => ['' => __('Choose a party')] + $parties->mapWithKeys(fn (Party $item): array => [$item->id => $item->name.($item->isEmployee() ? ' ('.__('employee').')' : '')])->all(),
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'hasCompanies' => $context->options()->isNotEmpty(),
            'selectedCompany' => $company,
            'selectedParty' => $party,
            'chooseCompanyUrl' => route('admin.choose-company', ['next' => route('admin.reports.party-statement', $this->periodQuery(), false)]),
            'report' => $party && $range ? $this->statement($party, $range[0], $range[1]) : null,
        ])->layout('layouts.admin');
    }

    /** @return array{opening: int, closing: int, debit: int, credit: int, rows: list<array{entry: JournalEntry, debit: int, credit: int, balance: int}>, truncated: bool} */
    private function statement(Party $party, string $from, string $to): array
    {
        [$debitSql, $creditSql, $sideBindings] = $this->sideExpressions();
        $totals = DB::query()->fromSub($this->movements($party)->where('entry_date', '<=', $to), 'm')
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date < ? THEN ({$debitSql}) - ({$creditSql}) ELSE 0 END), 0) as opening", [$from, ...$sideBindings, ...$sideBindings])
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date >= ? THEN {$debitSql} ELSE 0 END), 0) as period_debit", [$from, ...$sideBindings])
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date >= ? THEN {$creditSql} ELSE 0 END), 0) as period_credit", [$from, ...$sideBindings])
            ->first();
        $entries = $this->movements($party)->whereBetween('entry_date', [$from, $to])->with('bill:id,number')
            ->orderBy('entry_date')->orderBy('id')->limit(self::ROW_LIMIT + 1)->get();

        $opening = (int) $totals->opening;
        $balance = $opening;
        $rows = [];
        foreach ($entries->take(self::ROW_LIMIT) as $entry) {
            [$debit, $credit] = $this->sides($entry);
            $balance += $debit - $credit;
            $rows[] = ['entry' => $entry, 'debit' => $debit, 'credit' => $credit, 'balance' => $balance];
        }

        return ['opening' => $opening, 'closing' => $opening + (int) $totals->period_debit - (int) $totals->period_credit,
            'debit' => (int) $totals->period_debit, 'credit' => (int) $totals->period_credit, 'rows' => $rows,
            'truncated' => $entries->count() > self::ROW_LIMIT];
    }

    /** Posted bills and settlements of the party, each with `due_part`: the receivable/payable line of a bill (0 otherwise). */
    private function movements(Party $party): Builder
    {
        $duePart = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereColumn('journal_lines.journal_entry_id', 'journal_entries.id')
            ->where('accounts.is_system', true)->whereIn('accounts.type', [AccountType::Asset->value, AccountType::Liability->value])
            ->selectRaw('COALESCE(SUM(journal_lines.debit + journal_lines.credit), 0)');

        return JournalEntry::query()->posted()->where('company_id', $party->company_id)->where('party_id', $party->id)
            ->whereIn('type', [EntryType::Income, EntryType::Expense, EntryType::Receipt, EntryType::Payment])
            ->select('journal_entries.*')->selectSub($duePart, 'due_part');
    }

    /**
     * Debit and credit toward the party for one entry; the SQL twin is sideExpressions().
     *
     * @return array{0: int, 1: int}
     */
    private function sides(JournalEntry $entry): array
    {
        $paidNow = $entry->amount - (int) $entry->due_part;

        return match ($entry->type) {
            EntryType::Income => [$entry->amount, $paidNow],
            EntryType::Expense => [$paidNow, $entry->amount],
            EntryType::Receipt => [0, $entry->amount],
            EntryType::Payment => [$entry->amount, 0],
            default => [0, 0],
        };
    }

    /** @return array{0: string, 1: string, 2: list<string>} debit SQL, credit SQL and the bindings each of them takes */
    private function sideExpressions(): array
    {
        $bindings = [EntryType::Income->value, EntryType::Expense->value, EntryType::Payment->value, EntryType::Receipt->value];

        return [
            'CASE m.type WHEN ? THEN m.amount WHEN ? THEN m.amount - m.due_part WHEN ? THEN m.amount WHEN ? THEN 0 ELSE 0 END',
            'CASE m.type WHEN ? THEN m.amount - m.due_part WHEN ? THEN m.amount WHEN ? THEN 0 WHEN ? THEN m.amount ELSE 0 END',
            $bindings,
        ];
    }
}
