<?php

namespace App\Livewire\Admin;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Accounting dashboard for the header company context: one company, or every visible company combined. Figures follow the viewer's abilities:
 * income, expense and dues need `entries.view`, payment method balances `accounts.view`, the head count `users.view`.
 */
class Dashboard extends Component
{
    /** Number of months shown in the trend chart (6 or 12). */
    public int $trendMonths = 6;

    public function setTrendMonths(int $months): void
    {
        if (in_array($months, [6, 12], true)) {
            $this->trendMonths = $months;
        }
    }

    public function render(): View
    {
        Gate::authorize('dashboard.view');
        $context = app(CompanyContext::class);
        if ($context->options()->isEmpty()) {
            return view('livewire.admin.dashboard', ['hasCompanies' => false])->layout('layouts.admin');
        }
        $companyIds = $context->companyIds();
        $showEntries = Gate::allows('entries.view');
        $months = $showEntries ? $this->monthlyTotals($companyIds) : null;
        $chartMax = $months === null ? 0 : $this->niceCeiling(max(array_merge([0], array_column($months, 'income'), array_column($months, 'expense'))));

        $cashFlowChart = $months !== null ? $this->buildCashFlowChart($months) : null;
        $expenseBreakdown = $showEntries ? $this->expenseBreakdown($companyIds) : null;

        return view('livewire.admin.dashboard', [
            'hasCompanies' => true,
            'consolidated' => $context->isAll(),
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()->name,
            'months' => $months,
            'chartMax' => $chartMax,
            'trendMonths' => $this->trendMonths,
            'cashFlowChart' => $cashFlowChart,
            'expenseBreakdown' => $expenseBreakdown,
            'recent' => $showEntries ? JournalEntry::query()->posted()->whereIn('company_id', $companyIds)->with(['company:id,name,code', 'party:id,name'])
                ->orderByDesc('entry_date')->orderByDesc('id')->limit(10)->get() : null,
            'dues' => $showEntries ? $this->dueSummary($companyIds) : null,
            'cash' => Gate::allows('accounts.view') ? $this->cashBalances($context->options()->whereIn('id', $companyIds)) : null,
            'activeEmployees' => Gate::allows('users.view') ? User::query()->where('role', '!=', Permissions::ROOT_ROLE)->where('is_active', true)
                ->whereHas('companies', fn ($companies) => $companies->whereIn('companies.id', $companyIds))->count() : null,
        ])->layout('layouts.admin');
    }

    /**
     * Builds the interactive Chart.js configuration for Income, Expenses, and Net Profit.
     *
     * @param  list<array{label: string, short: string, income: int, expense: int}>  $months
     * @return array<string, mixed>
     */
    private function buildCashFlowChart(array $months): array
    {
        $netProfits = array_map(fn (array $m): int => $m['income'] - $m['expense'], $months);

        return [
            'type' => 'bar',
            'data' => [
                'labels' => array_column($months, 'short'),
                'datasets' => [
                    [
                        'type' => 'line',
                        'label' => __('Net profit'),
                        'data' => $netProfits,
                        'borderColor' => '#2563eb',
                        'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
                        'borderWidth' => 2.5,
                        'tension' => 0.35,
                        'pointRadius' => 4,
                        'pointHoverRadius' => 6,
                        'pointBackgroundColor' => '#ffffff',
                        'pointBorderColor' => '#2563eb',
                        'pointBorderWidth' => 2,
                        'order' => 1,
                    ],
                    [
                        'type' => 'bar',
                        'label' => __('Income'),
                        'data' => array_column($months, 'income'),
                        'backgroundColor' => '#16a34a',
                        'borderRadius' => 4,
                        'order' => 2,
                    ],
                    [
                        'type' => 'bar',
                        'label' => __('Expenses'),
                        'data' => array_column($months, 'expense'),
                        'backgroundColor' => '#dc2626',
                        'borderRadius' => 4,
                        'order' => 3,
                    ],
                ],
            ],
            'options' => [
                'interaction' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
        ];
    }

    /**
     * Top expense categories for the trend period with percentages and donut chart config.
     *
     * @param  list<int>  $companyIds
     * @return array{total: int, items: list<array{name: string, amount: int, percent: float, color: string}>, chart: array<string, mixed>}|null
     */
    private function expenseBreakdown(array $companyIds): ?array
    {
        $current = CarbonImmutable::today()->startOfMonth();
        $first = $current->subMonthsNoOverflow($this->trendMonths - 1);

        $activity = app(LedgerService::class)->periodActivity(
            $companyIds,
            $first->toDateString(),
            $current->endOfMonth()->toDateString(),
            [AccountType::Expense]
        );

        if ($activity->isEmpty()) {
            return null;
        }

        $grouped = $activity->groupBy(fn (object $line): string => mb_strtolower(trim($line->name)))
            ->map(function ($rows): array {
                $amount = $rows->sum(fn (object $r): int => (int) $r->debit_total - (int) $r->credit_total);

                return [
                    'name' => $rows->first()->name,
                    'amount' => max(0, $amount),
                ];
            })
            ->filter(fn (array $c): bool => $c['amount'] > 0)
            ->sortByDesc('amount')
            ->values();

        $totalExpense = $grouped->sum('amount');
        if ($totalExpense <= 0) {
            return null;
        }

        $palette = ['#166534', '#2563eb', '#d97706', '#dc2626', '#7c3aed', '#64748b'];
        $items = [];
        $topCategories = $grouped->take(5);
        $otherTotal = $grouped->skip(5)->sum('amount');

        foreach ($topCategories as $index => $cat) {
            $items[] = [
                'name' => $cat['name'],
                'amount' => $cat['amount'],
                'percent' => round(($cat['amount'] / $totalExpense) * 100, 1),
                'color' => $palette[$index % count($palette)],
            ];
        }

        if ($otherTotal > 0) {
            $items[] = [
                'name' => __('Other expenses'),
                'amount' => $otherTotal,
                'percent' => round(($otherTotal / $totalExpense) * 100, 1),
                'color' => '#64748b',
            ];
        }

        $chart = [
            'type' => 'doughnut',
            'data' => [
                'labels' => array_column($items, 'name'),
                'datasets' => [
                    [
                        'data' => array_column($items, 'amount'),
                        'backgroundColor' => array_column($items, 'color'),
                        'borderWidth' => 2,
                        'borderColor' => '#ffffff',
                        'hoverOffset' => 4,
                    ],
                ],
            ],
            'options' => [
                'cutout' => '72%',
                'plugins' => [
                    'legend' => [
                        'display' => false,
                    ],
                ],
            ],
        ];

        return [
            'total' => $totalExpense,
            'items' => $items,
            'chart' => $chart,
        ];
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
        $first = $current->subMonthsNoOverflow($this->trendMonths - 1);
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
            if (isset($months[$key])) {
                $months[$key][$row->type] += (int) $row->total;
            }
        }

        return array_values($months);
    }

    /**
     * Outstanding receivable and payable totals, the number of overdue bills, aging buckets, and the next five dues.
     *
     * @param  list<int>  $companyIds
     * @return array{receivable: int, payable: int, overdue: int, aging: array{receivable: array<string, int>, payable: array<string, int>}, next: EloquentCollection<int, JournalEntry>}
     */
    private function dueSummary(array $companyIds): array
    {
        $open = fn () => JournalEntry::query()->whereIn('company_id', $companyIds)->open()->withOutstanding();
        $totals = DB::query()->fromSub($open(), 'bills')
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN outstanding ELSE 0 END), 0) as receivable', [EntryType::Income->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN outstanding ELSE 0 END), 0) as payable', [EntryType::Expense->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END), 0) as overdue', [CarbonImmutable::today()->toDateString()])
            ->first();

        $today = CarbonImmutable::today();
        $allOpen = $open()->get();
        $receivableAging = ['current' => 0, 'overdue_1_30' => 0, 'overdue_31_60' => 0, 'overdue_60_plus' => 0];
        $payableAging = ['current' => 0, 'overdue_1_30' => 0, 'overdue_31_60' => 0, 'overdue_60_plus' => 0];

        foreach ($allOpen as $bill) {
            $outstanding = (int) $bill->outstanding;
            $isIncome = $bill->type === EntryType::Income;
            $dueDate = $bill->due_date ? CarbonImmutable::parse($bill->due_date) : null;

            if ($dueDate === null || $dueDate->gte($today)) {
                if ($isIncome) {
                    $receivableAging['current'] += $outstanding;
                } else {
                    $payableAging['current'] += $outstanding;
                }
            } else {
                $days = (int) $dueDate->diffInDays($today);
                if ($days <= 30) {
                    if ($isIncome) {
                        $receivableAging['overdue_1_30'] += $outstanding;
                    } else {
                        $payableAging['overdue_1_30'] += $outstanding;
                    }
                } elseif ($days <= 60) {
                    if ($isIncome) {
                        $receivableAging['overdue_31_60'] += $outstanding;
                    } else {
                        $payableAging['overdue_31_60'] += $outstanding;
                    }
                } else {
                    if ($isIncome) {
                        $receivableAging['overdue_60_plus'] += $outstanding;
                    } else {
                        $payableAging['overdue_60_plus'] += $outstanding;
                    }
                }
            }
        }

        return [
            'receivable' => (int) $totals->receivable,
            'payable' => (int) $totals->payable,
            'overdue' => (int) $totals->overdue,
            'aging' => [
                'receivable' => $receivableAging,
                'payable' => $payableAging,
            ],
            'next' => $open()->with(['company:id,name,code', 'party:id,name'])->orderBy('due_date')->orderBy('id')->limit(5)->get(),
        ];
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
     * Balances of every payment method (cash, bank, mobile wallet, …), grouped by company and by payment type.
     *
     * @param  Collection<int, Company>  $companies
     * @return array{companies: list<array{company: Company, accounts: list<array{account: Account, balance: int}>, total: int}>, total: int, byType: array<string, array{label: string, icon: string, amount: int, color: string}>}
     */
    private function cashBalances(Collection $companies): array
    {
        $accounts = Account::query()->whereIn('company_id', $companies->pluck('id'))->paymentMethods()->orderBy('code')->get();
        $balances = app(LedgerService::class)->balances($accounts);

        $byType = [
            PaymentType::Cash->value => ['label' => __('Cash in hand'), 'icon' => '💵', 'amount' => 0, 'color' => '#16a34a'],
            PaymentType::Bank->value => ['label' => __('Bank accounts'), 'icon' => '🏛️', 'amount' => 0, 'color' => '#2563eb'],
            PaymentType::MobileBanking->value => ['label' => __('Mobile wallets'), 'icon' => '📱', 'amount' => 0, 'color' => '#d97706'],
            PaymentType::Other->value => ['label' => __('Other / Cards'), 'icon' => '💳', 'amount' => 0, 'color' => '#64748b'],
        ];

        foreach ($accounts as $account) {
            $bal = $balances[$account->id] ?? 0;
            $typeKey = match ($account->payment_type) {
                PaymentType::Cash => PaymentType::Cash->value,
                PaymentType::Bank => PaymentType::Bank->value,
                PaymentType::MobileBanking => PaymentType::MobileBanking->value,
                default => PaymentType::Other->value,
            };
            $byType[$typeKey]['amount'] += $bal;
        }

        $groups = $companies->map(function (Company $company) use ($accounts, $balances): array {
            $rows = $accounts->where('company_id', $company->id)
                ->filter(fn (Account $account): bool => $account->is_active || $balances[$account->id] !== 0)
                ->map(fn (Account $account): array => ['account' => $account, 'balance' => $balances[$account->id]])->values()->all();

            return ['company' => $company, 'accounts' => $rows, 'total' => array_sum(array_column($rows, 'balance'))];
        })->filter(fn (array $group): bool => $group['accounts'] !== [])->values()->all();

        return [
            'companies' => $groups,
            'total' => array_sum(array_column($groups, 'total')),
            'byType' => $byType,
        ];
    }
}
