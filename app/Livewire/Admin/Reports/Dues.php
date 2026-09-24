<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\EntryType;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Open receivables (income bills) and payables (expense bills) of the header company context, grouped by party. In
 * All mode every visible company is combined, the company is shown per party, and totals are given per company.
 * Outstanding and overdue are as of today in the application timezone (Asia/Dhaka).
 */
class Dues extends Component
{
    /** 'receivable', 'payable' or '' for both. */
    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $party = '';

    #[Url(except: false)]
    public bool $overdue = false;

    /**
     * Opens a party's statement in the party's company: in All mode this switches the header context to that company
     * first. The party must belong to a company in the current scope.
     */
    public function openStatement(int $partyId): Redirector|RedirectResponse
    {
        Gate::authorize('reports.view');
        Gate::authorize('parties.view');
        $context = app(CompanyContext::class);
        $party = Party::query()->whereIn('company_id', $context->companyIds())->findOrFail($partyId);
        $context->select($party->company_id);

        return redirect()->route('admin.reports.party-statement', ['party' => $party->id]);
    }

    public function render(): View
    {
        Gate::authorize('reports.view');
        $context = app(CompanyContext::class);
        if (! in_array($this->kind, ['', 'receivable', 'payable'], true)) {
            $this->kind = '';
        }
        $companyIds = $context->companyIds();
        $type = ['receivable' => EntryType::Income, 'payable' => EntryType::Expense][$this->kind] ?? null;
        $today = CarbonImmutable::today()->toDateString();
        $todayDate = CarbonImmutable::today();

        $bills = app(LedgerService::class)->dues($companyIds, $type);
        $partyOptions = $bills->pluck('party')->filter()->unique('id')->sortBy('name')
            ->mapWithKeys(fn (Party $party): array => [$party->id => $party->name.($context->isAll() ? ' ('.$bills->firstWhere('party_id', $party->id)->company->code.')' : '')])->all();
        if (! array_key_exists((int) $this->party, $partyOptions)) {
            $this->party = '';
        }
        $bills = $bills->when($this->party !== '', fn (Collection $items) => $items->where('party_id', (int) $this->party))
            ->when($this->overdue, fn (Collection $items) => $items->filter(fn (JournalEntry $bill): bool => $bill->due_date !== null && $bill->due_date->toDateString() < $today))
            ->values();
        $bills->load('lines.account');

        $receivableAging = ['current' => 0, 'overdue_1_30' => 0, 'overdue_31_60' => 0, 'overdue_60_plus' => 0];
        $payableAging = ['current' => 0, 'overdue_1_30' => 0, 'overdue_31_60' => 0, 'overdue_60_plus' => 0];

        foreach ($bills as $bill) {
            $outstanding = (int) $bill->outstanding;
            $isIncome = $bill->type === EntryType::Income;
            $dueDate = $bill->due_date ? CarbonImmutable::parse($bill->due_date) : null;

            if ($dueDate === null || $dueDate->gte($todayDate)) {
                if ($isIncome) {
                    $receivableAging['current'] += $outstanding;
                } else {
                    $payableAging['current'] += $outstanding;
                }
            } else {
                $days = (int) $dueDate->diffInDays($todayDate);
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

        $agingChart = null;
        if ($bills->isNotEmpty()) {
            $datasets = [];
            if ($this->kind !== 'payable') {
                $datasets[] = [
                    'label' => __('Receivables (owed to us)'),
                    'data' => array_values($receivableAging),
                    'backgroundColor' => '#16a34a',
                    'borderRadius' => 4,
                ];
            }
            if ($this->kind !== 'receivable') {
                $datasets[] = [
                    'label' => __('Payables (we owe)'),
                    'data' => array_values($payableAging),
                    'backgroundColor' => '#dc2626',
                    'borderRadius' => 4,
                ];
            }
            $agingChart = [
                'type' => 'bar',
                'data' => [
                    'labels' => [__('Current / Not due'), __('1–30d overdue'), __('31–60d overdue'), __('60d+ overdue')],
                    'datasets' => $datasets,
                ],
                'options' => [
                    'plugins' => ['legend' => ['display' => $this->kind === '']],
                ],
            ];
        }

        $topParties = $bills->whereNotNull('party_id')->groupBy('party_id')
            ->map(fn (Collection $items): array => [
                'name' => $items->first()->party?->name ?? __('No party'),
                'total' => (int) $items->sum('outstanding'),
            ])
            ->sortByDesc('total')
            ->take(5)
            ->values();

        $topPartiesChart = null;
        if ($topParties->isNotEmpty() && $topParties->sum('total') > 0) {
            $topPartiesChart = [
                'type' => 'bar',
                'data' => [
                    'labels' => $topParties->pluck('name')->all(),
                    'datasets' => [
                        [
                            'label' => __('Outstanding'),
                            'data' => $topParties->pluck('total')->all(),
                            'backgroundColor' => '#2563eb',
                            'borderRadius' => 4,
                        ],
                    ],
                ],
                'options' => [
                    'indexAxis' => 'y',
                    'plugins' => ['legend' => ['display' => false]],
                ],
            ];
        }

        return view('livewire.admin.reports.dues', [
            'kindOptions' => ['' => __('Receivable and payable'), 'receivable' => __('Receivable (owed to us)'), 'payable' => __('Payable (we owe)')],
            'partyOptions' => ['' => __('All parties')] + $partyOptions,
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()->name,
            'consolidated' => $context->isAll(),
            'sections' => [
                'receivable' => $this->groupByParty($bills->where('type', EntryType::Income), $today),
                'payable' => $this->groupByParty($bills->where('type', EntryType::Expense), $today),
            ],
            'companyTotals' => $bills->groupBy('company_id')->map(fn (Collection $items): array => [
                'company' => $items->first()->company,
                'receivable' => (int) $items->where('type', EntryType::Income)->sum('outstanding'),
                'payable' => (int) $items->where('type', EntryType::Expense)->sum('outstanding'),
            ])->sortBy('company.name')->values(),
            'todayLabel' => CarbonImmutable::today()->format('d M Y'),
            'agingChart' => $agingChart,
            'topPartiesChart' => $topPartiesChart,
        ])->layout('layouts.admin');
    }

    /**
     * @param  Collection<int, JournalEntry>  $bills
     * @return Collection<int, array{party: mixed, company: Company, bills: list<array<string, mixed>>, total: int}>
     */
    private function groupByParty(Collection $bills, string $today): Collection
    {
        return $bills->groupBy(fn (JournalEntry $bill): int => (int) $bill->party_id)->map(fn (Collection $items): array => [
            'party' => $items->first()->party,
            'company' => $items->first()->company,
            'bills' => $items->map(fn (JournalEntry $bill): array => [
                'entry' => $bill,
                'category' => $bill->categoryAccount()?->name,
                'paid' => $bill->paidAmount(),
                'outstanding' => (int) $bill->outstanding,
                'days_overdue' => $bill->due_date !== null && $bill->due_date->toDateString() < $today
                    ? (int) $bill->due_date->diffInDays(CarbonImmutable::parse($today)) : 0,
            ])->all(),
            'total' => (int) $items->sum('outstanding'),
        ])->sortBy([['company.name', 'asc'], ['party.name', 'asc']])->values();
    }
}
