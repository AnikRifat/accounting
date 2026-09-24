<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\EntryType;
use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Posted expense entries whose party is an employee, in a period and the header company context, largest total first. Paid and outstanding are
 * as of today: the outstanding part is the entry's payable minus its posted payments.
 */
class EmployeeCost extends Component
{
    use HasPeriod;

    public function render(): View
    {
        Gate::authorize('reports.view');
        Gate::authorize('users.view');
        $context = app(CompanyContext::class);
        $companyIds = $context->companyIds();
        $range = $this->resolvePeriod();

        $rows = collect();
        if ($range !== null && $companyIds !== []) {
            $employeeParties = Party::query()->whereIn('company_id', $companyIds)->whereNotNull('user_id')->select('id');
            $bills = JournalEntry::query()->posted()->where('type', EntryType::Expense)->whereIn('company_id', $companyIds)
                ->whereIn('party_id', $employeeParties)->whereBetween('entry_date', $range)->withOutstanding();
            $totals = DB::query()->fromSub($bills, 'bills')->groupBy('party_id')
                ->selectRaw('party_id, SUM(amount) as total, SUM(outstanding) as outstanding, COUNT(*) as entry_count')
                ->orderByDesc('total')->orderBy('party_id')->get();
            $parties = Party::query()->whereIn('company_id', $companyIds)->whereKey($totals->pluck('party_id'))
                ->with(['company:id,name,code', 'user:id,employee_code,designation'])->get()->keyBy('id');
            $rows = $totals->filter(fn (object $total): bool => $parties->has($total->party_id))->map(fn (object $total): array => [
                'party' => $parties[$total->party_id], 'total' => (int) $total->total, 'outstanding' => (int) $total->outstanding,
                'paid' => (int) $total->total - (int) $total->outstanding, 'count' => (int) $total->entry_count,
            ])->values();
        }

        $chart = null;
        if ($rows->isNotEmpty() && $rows->sum('total') > 0) {
            $topEmployees = $rows->take(8);
            $chart = [
                'type' => 'bar',
                'data' => [
                    'labels' => $topEmployees->map(fn (array $r): string => $r['party']->name)->all(),
                    'datasets' => [
                        [
                            'label' => __('Paid'),
                            'data' => $topEmployees->pluck('paid')->all(),
                            'backgroundColor' => '#16a34a',
                            'borderRadius' => 4,
                        ],
                        [
                            'label' => __('Outstanding'),
                            'data' => $topEmployees->pluck('outstanding')->all(),
                            'backgroundColor' => '#f59e0b',
                            'borderRadius' => 4,
                        ],
                    ],
                ],
                'options' => [
                    'scales' => [
                        'x' => ['stacked' => true],
                        'y' => ['stacked' => true],
                    ],
                    'plugins' => [
                        'legend' => ['display' => true],
                    ],
                ],
            ];
        }

        return view('livewire.admin.reports.employee-cost', [
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            // Open ends (All time) are left out of the transactions link rather than passed as placeholder dates.
            'transactionsRange' => $range === null ? [] : array_filter(['from' => $range[0], 'to' => $range[1]],
                fn (string $date): bool => ! in_array($date, [self::EARLIEST_DATE, self::LATEST_DATE], true)),
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()->name,
            'consolidated' => $context->isAll(),
            'rows' => $rows,
            'chart' => $chart,
        ])->layout('layouts.admin');
    }
}
