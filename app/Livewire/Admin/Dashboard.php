<?php

namespace App\Livewire\Admin;

use App\Enums\EntryType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Accounting overview for the visible companies (or one of them). Figures follow the viewer's abilities:
 * income, expense and dues need `entries.view`, payment method balances `accounts.view`, the head count `employees.view`.
 */
class Dashboard extends Component
{
    /** Months shown in the income versus expense chart, including the current month. */
    private const TREND_MONTHS = 6;

    /** A visible company id, or '' for all visible companies. */
    public string $company = '';

    public function render(): View
    {
        Gate::authorize('dashboard.view');
        $user = auth()->user();
        $companies = Company::visibleTo($user)->orderBy('name')->get(['id', 'name', 'code']);
        if ($companies->isEmpty()) {
            return view('livewire.admin.dashboard', ['hasCompanies' => false])->layout('layouts.admin');
        }
        if ($this->company !== '' && ! $companies->contains('id', (int) $this->company)) {
            $this->company = '';
        }
        $companyIds = $this->company === '' ? $companies->pluck('id')->all() : [(int) $this->company];
        $showEntries = Gate::allows('entries.view');
        $months = $showEntries ? $this->monthlyTotals($companyIds) : null;
        $chartMax = $months === null ? 0 : $this->niceCeiling(max(array_merge(array_column($months, 'income'), array_column($months, 'expense'))));

        return view('livewire.admin.dashboard', [
            'hasCompanies' => true,
            'companyOptions' => ['' => __('All my companies')] + $companies->mapWithKeys(fn (Company $item): array => [$item->id => $item->name.' ('.$item->code.')'])->all(),
            'months' => $months,
            'chartMax' => $chartMax,
            'recent' => $showEntries ? JournalEntry::query()->posted()->whereIn('company_id', $companyIds)->with(['company:id,name,code', 'party:id,name'])
                ->orderByDesc('entry_date')->orderByDesc('id')->limit(10)->get() : null,
            'dues' => $showEntries ? $this->dueSummary($companyIds) : null,
            'cash' => Gate::allows('accounts.view') ? $this->cashBalances($companies->whereIn('id', $companyIds)) : null,
            'activeEmployees' => Gate::allows('employees.view')
                ? Employee::visibleTo($user)->whereIn('company_id', $companyIds)->where('is_active', true)->count() : null,
        ])->layout('layouts.admin');
    }

    /**
     * Posted income and expense per calendar month, oldest first, in one grouped query.
     *
     * @param  list<int>  $companyIds
     * @return list<array{label: string, short: string, income: int, expense: int}>
     */
    private function monthlyTotals(array $companyIds): array
    {
        $current = CarbonImmutable::today()->startOfMonth();
        $first = $current->subMonthsNoOverflow(self::TREND_MONTHS - 1);
        $daily = JournalEntry::query()->posted()->whereIn('company_id', $companyIds)
            ->whereIn('type', [EntryType::Income, EntryType::Expense])
            ->whereBetween('entry_date', [$first->toDateString(), $current->endOfMonth()->toDateString()])
            ->toBase()->groupBy('entry_date', 'type')->selectRaw('entry_date, type, SUM(amount) as total')->get();

        $months = [];
        for ($month = $first; $month <= $current; $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = ['label' => $month->format('F Y'), 'short' => $month->format('M'), 'income' => 0, 'expense' => 0];
        }
        foreach ($daily as $row) {
            $key = substr((string) $row->entry_date, 0, 7);
            $months[$key][$row->type] += (int) $row->total;
        }

        return array_values($months);
    }

    /**
     * Outstanding receivable and payable totals, the number of overdue bills and the next five dues, in two queries.
     *
     * @param  list<int>  $companyIds
     * @return array{receivable: int, payable: int, overdue: int, next: EloquentCollection<int, JournalEntry>}
     */
    private function dueSummary(array $companyIds): array
    {
        $open = fn () => JournalEntry::query()->whereIn('company_id', $companyIds)->open()->withOutstanding();
        $totals = DB::query()->fromSub($open(), 'bills')
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN outstanding ELSE 0 END), 0) as receivable', [EntryType::Income->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN outstanding ELSE 0 END), 0) as payable', [EntryType::Expense->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END), 0) as overdue', [CarbonImmutable::today()->toDateString()])
            ->first();

        return ['receivable' => (int) $totals->receivable, 'payable' => (int) $totals->payable, 'overdue' => (int) $totals->overdue,
            'next' => $open()->with(['company:id,name,code', 'party:id,name'])->orderBy('due_date')->orderBy('id')->limit(5)->get()];
    }

    /** Rounds a paisa amount up to a clean axis maximum in whole taka (1, 2 or 5 × a power of ten); 0 stays 0. */
    private function niceCeiling(int $paisa): int
    {
        $taka = intdiv($paisa + 99, 100);
        if ($taka <= 0) {
            return 0;
        }
        $magnitude = 10 ** (strlen((string) $taka) - 1);
        foreach ([1, 2, 5] as $step) {
            if ($step * $magnitude >= $taka) {
                return $step * $magnitude * 100;
            }
        }

        return 10 * $magnitude * 100;
    }

    /**
     * Balances of every payment method (cash, bank, mobile wallet, …), grouped by company.
     *
     * @param  Collection<int, Company>  $companies
     * @return array{companies: list<array{company: Company, accounts: list<array{account: Account, balance: int}>, total: int}>, total: int}
     */
    private function cashBalances(Collection $companies): array
    {
        $accounts = Account::query()->whereIn('company_id', $companies->pluck('id'))->paymentMethods()->orderBy('code')->get();
        $balances = app(LedgerService::class)->balances($accounts);
        $groups = $companies->map(function (Company $company) use ($accounts, $balances): array {
            $rows = $accounts->where('company_id', $company->id)
                ->filter(fn (Account $account): bool => $account->is_active || $balances[$account->id] !== 0)
                ->map(fn (Account $account): array => ['account' => $account, 'balance' => $balances[$account->id]])->values()->all();

            return ['company' => $company, 'accounts' => $rows, 'total' => array_sum(array_column($rows, 'balance'))];
        })->filter(fn (array $group): bool => $group['accounts'] !== [])->values()->all();

        return ['companies' => $groups, 'total' => array_sum(array_column($groups, 'total'))];
    }
}
