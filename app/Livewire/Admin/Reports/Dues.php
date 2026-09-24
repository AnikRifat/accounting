<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\EntryType;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Open receivables (income bills) and payables (expense bills) grouped by party, with totals per company.
 * Outstanding and overdue are as of today in the application timezone (Asia/Dhaka).
 */
class Dues extends Component
{
    /** A visible company id, or '' for all visible companies. */
    #[Url(except: '')]
    public string $company = '';

    /** 'receivable', 'payable' or '' for both. */
    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $party = '';

    #[Url(except: false)]
    public bool $overdue = false;

    public function updatedCompany(): void
    {
        $this->party = '';
    }

    public function render(): View
    {
        Gate::authorize('reports.view');
        $companies = Company::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'code']);
        if (! $companies->contains('id', (int) $this->company)) {
            $this->company = '';
        }
        if (! in_array($this->kind, ['', 'receivable', 'payable'], true)) {
            $this->kind = '';
        }
        $companyIds = $this->company === '' ? $companies->pluck('id')->all() : [(int) $this->company];
        $type = ['receivable' => EntryType::Income, 'payable' => EntryType::Expense][$this->kind] ?? null;
        $today = CarbonImmutable::today()->toDateString();

        $bills = app(LedgerService::class)->dues($companyIds, $type);
        $partyOptions = $bills->pluck('party')->filter()->unique('id')->sortBy('name')
            ->mapWithKeys(fn ($party): array => [$party->id => $party->name])->all();
        if (! array_key_exists((int) $this->party, $partyOptions)) {
            $this->party = '';
        }
        $bills = $bills->when($this->party !== '', fn (Collection $items) => $items->where('party_id', (int) $this->party))
            ->when($this->overdue, fn (Collection $items) => $items->filter(fn (JournalEntry $bill): bool => $bill->due_date !== null && $bill->due_date->toDateString() < $today))
            ->values();
        $bills->load('lines.account');

        return view('livewire.admin.reports.dues', [
            'companyOptions' => ['' => __('All my companies')] + $companies->mapWithKeys(fn (Company $item): array => [$item->id => $item->name.' ('.$item->code.')'])->all(),
            'kindOptions' => ['' => __('Receivable and payable'), 'receivable' => __('Receivable (owed to us)'), 'payable' => __('Payable (we owe)')],
            'partyOptions' => ['' => __('All parties')] + $partyOptions,
            'scopeLabel' => $this->company === '' ? __('All my companies') : $companies->firstWhere('id', (int) $this->company)->name,
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
