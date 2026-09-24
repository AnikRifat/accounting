<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\EntryType;
use App\Livewire\Admin\Reports\Concerns\HasPeriod;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Posted expense entries whose party is an employee, in a period, largest total first. Paid and outstanding are
 * as of today: the outstanding part is the entry's payable minus its posted payments.
 */
class EmployeeCost extends Component
{
    use HasPeriod;

    /** A visible company id, or '' for all visible companies. */
    #[Url(except: '')]
    public string $company = '';

    public function render(): View
    {
        Gate::authorize('reports.view');
        Gate::authorize('employees.view');
        $companies = Company::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'code']);
        if (! $companies->contains('id', (int) $this->company)) {
            $this->company = '';
        }
        $companyIds = $this->company === '' ? $companies->pluck('id')->all() : [(int) $this->company];
        $range = $this->resolvePeriod();

        $rows = collect();
        if ($range !== null && $companyIds !== []) {
            $employeeParties = Party::query()->whereIn('company_id', $companyIds)->whereNotNull('employee_id')->select('id');
            $bills = JournalEntry::query()->posted()->where('type', EntryType::Expense)->whereIn('company_id', $companyIds)
                ->whereIn('party_id', $employeeParties)->whereBetween('entry_date', $range)->withOutstanding();
            $totals = DB::query()->fromSub($bills, 'bills')->groupBy('party_id')
                ->selectRaw('party_id, SUM(amount) as total, SUM(outstanding) as outstanding, COUNT(*) as entry_count')
                ->orderByDesc('total')->orderBy('party_id')->get();
            $parties = Party::query()->whereIn('company_id', $companyIds)->whereKey($totals->pluck('party_id'))
                ->with(['company:id,name,code', 'employee:id,employee_code,designation'])->get()->keyBy('id');
            $rows = $totals->filter(fn (object $total): bool => $parties->has($total->party_id))->map(fn (object $total): array => [
                'party' => $parties[$total->party_id], 'total' => (int) $total->total, 'outstanding' => (int) $total->outstanding,
                'paid' => (int) $total->total - (int) $total->outstanding, 'count' => (int) $total->entry_count,
            ])->values();
        }

        return view('livewire.admin.reports.employee-cost', [
            'companyOptions' => ['' => __('All my companies')] + $companies->mapWithKeys(fn (Company $item): array => [$item->id => $item->name.' ('.$item->code.')'])->all(),
            'periodOptions' => $this->periodOptions(),
            'periodLabel' => $this->periodLabel($range),
            'range' => $range,
            'scopeLabel' => $this->company === '' ? __('All my companies') : $companies->firstWhere('id', (int) $this->company)->name,
            'rows' => $rows,
        ])->layout('layouts.admin');
    }
}
