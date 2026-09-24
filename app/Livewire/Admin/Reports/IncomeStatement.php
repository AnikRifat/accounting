<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\AccountType;
use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Models\Company;
use App\Services\LedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Income and expense categories for one company or all visible companies combined, on an accrual basis: a bill's
 * category line counts in full on its entry date, paid or not. Receipts and payments only move money between
 * payment methods and receivables/payables, so they never appear here. Accounts are grouped by name across
 * companies, one column per company. Only accounts with posted activity in the period appear.
 */
class IncomeStatement extends Component
{
    use HasPeriod;

    /** A visible company id, or '' for all visible companies (consolidated). */
    #[Url(except: '')]
    public string $company = '';

    public function render(): View
    {
        Gate::authorize('reports.view');
        $companies = Company::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'code', 'is_active']);
        if (! $companies->contains('id', (int) $this->company)) {
            $this->company = '';
        }
        $consolidated = $this->company === '';
        $selected = $consolidated ? $companies : $companies->where('id', (int) $this->company)->values();
        $range = $this->resolvePeriod();

        $activity = $range === null || $selected->isEmpty() ? collect()
            : app(LedgerService::class)->periodActivity($selected->pluck('id')->all(), $range[0], $range[1], [AccountType::Income, AccountType::Expense]);
        $columns = $consolidated
            ? $selected->filter(fn (Company $company): bool => $company->is_active || $activity->contains('company_id', $company->id))->values()
            : $selected;

        $sections = [];
        $totals = [];
        foreach ([AccountType::Income, AccountType::Expense] as $type) {
            $rows = $activity->where('type', $type->value)->groupBy('name')->map(function ($accounts, string $name) use ($type): array {
                $amounts = $accounts->groupBy('company_id')->map(fn ($lines): int => $lines->sum(fn (object $line): int => $type === AccountType::Income
                    ? (int) $line->credit_total - (int) $line->debit_total
                    : (int) $line->debit_total - (int) $line->credit_total))->all();

                return ['code' => $accounts->min('code'), 'name' => $name, 'amounts' => $amounts, 'total' => array_sum($amounts)];
            })->sortBy([['code', 'asc'], ['name', 'asc']])->values();
            $sections[$type->value] = $rows;
            $totals[$type->value] = $columns->mapWithKeys(fn (Company $company): array => [$company->id => $rows->sum(fn (array $row): int => $row['amounts'][$company->id] ?? 0)])->all();
        }

        return view('livewire.admin.reports.income-statement', [
            'companyOptions' => ['' => __('All my companies')] + $companies->mapWithKeys(fn (Company $item): array => [$item->id => $item->name.' ('.$item->code.')'])->all(),
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'scopeLabel' => $consolidated ? __('All my companies') : ($selected->first()?->name ?? ''),
            'consolidated' => $consolidated,
            'columns' => $columns,
            'income' => $sections[AccountType::Income->value],
            'expense' => $sections[AccountType::Expense->value],
            'totalIncome' => $totals[AccountType::Income->value],
            'totalExpense' => $totals[AccountType::Expense->value],
        ])->layout('layouts.admin');
    }
}
