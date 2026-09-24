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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Posted bills and settlements of the parties in the header company context. With no party chosen, one summary row per party with activity;
 * with a party, its entries with a running due. Debit raises what the party owes us (an income bill's total, a payment we made); credit
 * lowers it (money received, an expense bill's total). A positive balance means the party owes us; a negative one means we owe the party.
 */
class PartyStatement extends Component
{
    use HasPeriod;

    /** Lines shown on one page; opening and closing dues are computed separately and are always complete. */
    public const ROW_LIMIT = 1000;

    #[Url(except: '')]
    public string $party = '';

    public function render(): View
    {
        Gate::authorize('reports.view');
        Gate::authorize('parties.view');
        $context = app(CompanyContext::class);
        $companyIds = $context->companyIds();
        $parties = Party::query()->whereIn('company_id', $companyIds)->with('company:id,code')->orderBy('name')->get(['id', 'company_id', 'name', 'phone', 'user_id']);
        $party = $parties->firstWhere('id', (int) $this->party);
        $this->party = (string) $party?->id;
        $range = $this->resolvePeriod();

        return view('livewire.admin.reports.party-statement', [
            'partyOptions' => ['' => __('All parties')] + $parties->mapWithKeys(fn (Party $item): array => [$item->id => $item->name
                .($item->isEmployee() ? ' ('.__('employee').')' : '').($context->isAll() ? ' · '.$item->company->code : '')])->all(),
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'hasCompanies' => $companyIds !== [],
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()?->name,
            'consolidated' => $context->isAll(),
            'selectedParty' => $party,
            'showOpening' => $range !== null && $range[0] !== self::EARLIEST_DATE,
            'report' => $party && $range ? $this->statement($party, $range[0], $range[1]) : null,
            'summary' => ! $party && $range ? $this->summary($parties, $companyIds, $range[0], $range[1]) : null,
        ])->layout('layouts.admin');
    }

    /**
     * One row per party with an opening due or entries in the period.
     *
     * @param  Collection<int, Party>  $parties
     * @param  list<int>  $companyIds
     * @return array{rows: Collection<int, array{party: Party, opening: int, debit: int, credit: int, closing: int}>, opening: int, debit: int, credit: int, closing: int}
     */
    private function summary(Collection $parties, array $companyIds, string $from, string $to): array
    {
        [$debitSql, $creditSql, $sideBindings] = $this->sideExpressions();
        $totals = DB::query()->fromSub($this->movements($companyIds)->where('entry_date', '<=', $to), 'm')
            ->groupBy('m.party_id')->select('m.party_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date < ? THEN ({$debitSql}) - ({$creditSql}) ELSE 0 END), 0) as opening", [$from, ...$sideBindings, ...$sideBindings])
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date >= ? THEN {$debitSql} ELSE 0 END), 0) as period_debit", [$from, ...$sideBindings])
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date >= ? THEN {$creditSql} ELSE 0 END), 0) as period_credit", [$from, ...$sideBindings])
            ->get()->keyBy('party_id');

        $rows = $parties->filter(fn (Party $party): bool => $totals->has($party->id))->map(function (Party $party) use ($totals): array {
            $total = $totals[$party->id];
            [$opening, $debit, $credit] = [(int) $total->opening, (int) $total->period_debit, (int) $total->period_credit];

            return ['party' => $party, 'opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => $opening + $debit - $credit];
        })->filter(fn (array $row): bool => $row['opening'] !== 0 || $row['debit'] !== 0 || $row['credit'] !== 0)
            ->sortBy([['party.name', 'asc'], ['party.company.code', 'asc']])->values();

        return ['rows' => $rows, 'opening' => $rows->sum('opening'), 'debit' => $rows->sum('debit'), 'credit' => $rows->sum('credit'), 'closing' => $rows->sum('closing')];
    }

    /** @return array{opening: int, closing: int, debit: int, credit: int, rows: list<array{entry: JournalEntry, debit: int, credit: int, balance: int}>, truncated: bool} */
    private function statement(Party $party, string $from, string $to): array
    {
        [$debitSql, $creditSql, $sideBindings] = $this->sideExpressions();
        $totals = DB::query()->fromSub($this->movements([$party->company_id], $party->id)->where('entry_date', '<=', $to), 'm')
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date < ? THEN ({$debitSql}) - ({$creditSql}) ELSE 0 END), 0) as opening", [$from, ...$sideBindings, ...$sideBindings])
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date >= ? THEN {$debitSql} ELSE 0 END), 0) as period_debit", [$from, ...$sideBindings])
            ->selectRaw("COALESCE(SUM(CASE WHEN m.entry_date >= ? THEN {$creditSql} ELSE 0 END), 0) as period_credit", [$from, ...$sideBindings])
            ->first();
        $entries = $this->movements([$party->company_id], $party->id)->whereBetween('entry_date', [$from, $to])->with('bill:id,number')
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

    /**
     * Posted bills and settlements with a party (one party when given), each with `due_part`: the receivable/payable line of a bill (0 otherwise).
     *
     * @param  list<int>  $companyIds
     */
    private function movements(array $companyIds, ?int $partyId = null): Builder
    {
        $duePart = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereColumn('journal_lines.journal_entry_id', 'journal_entries.id')
            ->where('accounts.is_system', true)->whereIn('accounts.type', [AccountType::Asset->value, AccountType::Liability->value])
            ->selectRaw('COALESCE(SUM(journal_lines.debit + journal_lines.credit), 0)');

        return JournalEntry::query()->posted()->whereIn('company_id', $companyIds)
            ->when($partyId, fn (Builder $query) => $query->where('party_id', $partyId), fn (Builder $query) => $query->whereNotNull('party_id'))
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
