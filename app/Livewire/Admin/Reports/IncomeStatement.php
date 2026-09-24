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
        ])->layout('layouts.admin');
    }
}
