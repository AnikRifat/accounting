<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\AccountType;
use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Models\Company;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Income and expense categories for the header company context: one company in a single column, or all visible
 * companies side by side with a Total column. Accrual basis: a bill's category line counts in full on its entry
 * date, paid or not. Receipts and payments only move money between payment methods and receivables/payables, so
 * they never appear here. Accounts are grouped by name across companies; only accounts with activity appear.
 */
class IncomeStatement extends Component
{
    use HasPeriod;

    public function render(): View
    {
        Gate::authorize('reports.view');
        $context = app(CompanyContext::class);
        $consolidated = $context->isAll();
        $selected = $context->options()->whereIn('id', $context->companyIds())->values();
        $range = $this->resolvePeriod();

        $activity = $range === null || $selected->isEmpty() ? collect()
            : app(LedgerService::class)->periodActivity($selected->pluck('id')->all(), $range[0], $range[1], [AccountType::Income, AccountType::Expense]);
        $columns = $consolidated
            ? $selected->filter(fn (Company $company): bool => $company->is_active || $activity->contains('company_id', $company->id))->values()
            : $selected;

        $sections = [];
        $totals = [];
        foreach ([AccountType::Income, AccountType::Expense] as $type) {
            // Same merge key as the chart of accounts and categories: case and surrounding spaces are ignored.
            $rows = $activity->where('type', $type->value)->groupBy(fn (object $line): string => mb_strtolower(trim($line->name)))->map(function ($accounts) use ($type): array {
                $amounts = $accounts->groupBy('company_id')->map(fn ($lines): int => $lines->sum(fn (object $line): int => $type === AccountType::Income
                    ? (int) $line->credit_total - (int) $line->debit_total
                    : (int) $line->debit_total - (int) $line->credit_total))->all();

                return ['code' => $accounts->min('code'), 'name' => $accounts->sortBy('code')->first()->name, 'amounts' => $amounts, 'total' => array_sum($amounts)];
            })->sortBy([['code', 'asc'], ['name', 'asc']])->values();
            $sections[$type->value] = $rows;
            $totals[$type->value] = $columns->mapWithKeys(fn (Company $company): array => [$company->id => $rows->sum(fn (array $row): int => $row['amounts'][$company->id] ?? 0)])->all();
        }

        $grandTotalIncome = array_sum($totals[AccountType::Income->value]);
        $grandTotalExpense = array_sum($totals[AccountType::Expense->value]);
        $grandNet = $grandTotalIncome - $grandTotalExpense;

        $overviewChart = null;
        if ($grandTotalIncome > 0 || $grandTotalExpense > 0) {
            if ($consolidated && $columns->count() > 1) {
                $overviewChart = [
                    'type' => 'bar',
                    'data' => [
                        'labels' => $columns->pluck('code')->all(),
                        'datasets' => [
                            [
                                'label' => __('Income'),
                                'data' => $columns->map(fn (Company $c): int => $totals[AccountType::Income->value][$c->id] ?? 0)->all(),
                                'backgroundColor' => '#16a34a',
                                'borderRadius' => 4,
                            ],
                            [
                                'label' => __('Expenses'),
                                'data' => $columns->map(fn (Company $c): int => $totals[AccountType::Expense->value][$c->id] ?? 0)->all(),
                                'backgroundColor' => '#dc2626',
                                'borderRadius' => 4,
                            ],
                        ],
                    ],
                    'options' => [
                        'plugins' => ['legend' => ['display' => true]],
                    ],
                ];
            } else {
                $overviewChart = [
                    'type' => 'bar',
                    'data' => [
                        'labels' => [__('Income'), __('Expenses'), __('Net profit')],
                        'datasets' => [
                            [
                                'label' => __('Total'),
                                'data' => [$grandTotalIncome, $grandTotalExpense, $grandNet],
                                'backgroundColor' => [
                                    '#16a34a',
                                    '#dc2626',
                                    $grandNet >= 0 ? '#2563eb' : '#e11d48',
                                ],
                                'borderRadius' => 6,
                            ],
                        ],
                    ],
                    'options' => [
                        'plugins' => ['legend' => ['display' => false]],
                    ],
                ];
            }
        }

        $topExpenseChart = null;
        if ($sections[AccountType::Expense->value]->isNotEmpty() && $grandTotalExpense > 0) {
            $sortedExpenses = $sections[AccountType::Expense->value]->sortByDesc('total')->take(5);
            $topExpenseChart = [
                'type' => 'doughnut',
                'data' => [
                    'labels' => $sortedExpenses->pluck('name')->all(),
                    'datasets' => [
                        [
                            'data' => $sortedExpenses->pluck('total')->all(),
                            'backgroundColor' => ['#dc2626', '#ea580c', '#d97706', '#2563eb', '#7c3aed'],
                            'borderWidth' => 2,
                            'borderColor' => '#ffffff',
                        ],
                    ],
                ],
                'options' => [
                    'cutout' => '70%',
                    'plugins' => [
                        'legend' => ['position' => 'bottom'],
                    ],
                ],
            ];
        }

        return view('livewire.admin.reports.income-statement', [
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'scopeLabel' => $consolidated ? __('All companies') : $context->company()->name,
            'consolidated' => $consolidated,
            'columns' => $columns,
            'income' => $sections[AccountType::Income->value],
            'expense' => $sections[AccountType::Expense->value],
            'totalIncome' => $totals[AccountType::Income->value],
            'totalExpense' => $totals[AccountType::Expense->value],
            'grandNet' => $grandNet,
            'overviewChart' => $overviewChart,
            'topExpenseChart' => $topExpenseChart,
        ])->layout('layouts.admin');
    }
}
