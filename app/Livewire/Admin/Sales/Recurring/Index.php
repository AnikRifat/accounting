<?php

namespace App\Livewire\Admin\Sales\Recurring;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\RecurringInvoice;
use App\Services\RecurringInvoices;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Recurring invoice schedules of the header companies. The daily cron generates due drafts; "Generate due now"
 * (`sales.update`) runs the header companies' schedules for today straight away.
 */
class Index extends Component
{
    use WithFormSheet, WithPagination;

    /** @var list<array{schedule: string, reason: string}> schedules the last "Generate due now" skipped */
    public array $skipped = [];

    public function generate(): void
    {
        Gate::authorize('sales.update');
        $summary = app(RecurringInvoices::class)->run(CarbonImmutable::today(), app(CompanyContext::class)->companyIds());
        $this->skipped = $summary['skipped'];
        session()->now('success', trans_choice('{0} No draft invoices were due.|{1} Created :count draft invoice.|[2,*] Created :count draft invoices.',
            $summary['created'], ['count' => $summary['created']]));
    }

    /** Deletes a schedule; the drafts and invoices it made stay, without the link. */
    public function delete(int $scheduleId): void
    {
        Gate::authorize('sales.update');
        $schedule = RecurringInvoice::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($scheduleId);
        $schedule->delete();
        session()->now('success', __(':name deleted.', ['name' => $schedule->name]));
    }

    public function render(): View
    {
        Gate::authorize('sales.view');

        return view('livewire.admin.sales.recurring.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'schedules' => RecurringInvoice::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())
                ->with(['company:id,name', 'source:id,number,party_id', 'source.party:id,name'])->withCount('documents')
                ->orderByDesc('is_active')->orderBy('next_run_on')->orderBy('name')->paginate(20),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.sales.recurring.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }
}
