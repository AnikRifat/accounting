<?php

namespace App\Livewire\Admin\Reports;

use App\Models\Account;
use App\Models\Company;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Every account of one company with a non-zero balance as of a date, split into debit and credit columns. */
class TrialBalance extends Component
{
    #[Url(except: '')]
    public string $company = '';

    #[Url(as: 'as_of')]
    public string $asOf = '';

    public function mount(): void
    {
        if ($this->company === '') {
            $this->company = (string) session('ledger.company_id', '');
        }
        if ($this->asOf === '') {
            $this->asOf = CarbonImmutable::today()->toDateString();
        }
    }

    public function render(): View
    {
        Gate::authorize('reports.view');
        $companies = Company::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'code']);
        $company = $companies->firstWhere('id', (int) $this->company) ?? $companies->first();
        $this->company = (string) $company?->id;
        if ($company) {
            session(['ledger.company_id' => $company->id]);
        }
        $this->resetErrorBag('asOf');
        $asOf = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->asOf, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            ? CarbonImmutable::parse($this->asOf) : null;
        if ($asOf === null) {
            $this->addError('asOf', __('Enter a valid date.'));
        }

        $rows = collect();
        if ($company && $asOf) {
            $accounts = Account::query()->where('company_id', $company->id)->orderBy('code')->get();
            $balances = app(LedgerService::class)->balances($accounts, $asOf);
            $rows = $accounts->filter(fn (Account $account): bool => $balances[$account->id] !== 0)->map(function (Account $account) use ($balances): array {
                // A debit-normal account with a positive balance sits in the debit column; a negative one flips sides.
                $debitSide = $account->type->isDebitNormal() === ($balances[$account->id] > 0);

                return ['account' => $account, 'debit' => $debitSide ? abs($balances[$account->id]) : 0, 'credit' => $debitSide ? 0 : abs($balances[$account->id])];
            })->values();
        }
        $debitTotal = $rows->sum('debit');
        $creditTotal = $rows->sum('credit');

        return view('livewire.admin.reports.trial-balance', [
            'companyOptions' => $companies->mapWithKeys(fn (Company $item): array => [$item->id => $item->name.' ('.$item->code.')'])->all(),
            'selectedCompany' => $company,
            'asOfLabel' => $asOf?->format('d M Y'),
            'rows' => $rows,
            'debitTotal' => $debitTotal,
            'creditTotal' => $creditTotal,
            'balanced' => $debitTotal === $creditTotal,
        ])->layout('layouts.admin');
    }
}
