<?php

namespace App\Livewire\Admin\PaymentMethods;

use App\Models\Account;
use App\Models\Company;
use App\Services\LedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Index extends Component
{
    public string $companyId = '';

    public function mount(): void
    {
        $this->companyId = (string) session('ledger.company_id', '');
    }

    public function render(): View
    {
        Gate::authorize('accounts.view');
        $companies = Company::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'code']);
        // A forged company id falls back to a visible company.
        $company = $companies->firstWhere('id', (int) $this->companyId) ?? $companies->first();
        $this->companyId = (string) $company?->id;
        if ($company) {
            session(['ledger.company_id' => $company->id]);
        }
        $methods = $company ? Account::query()->where('company_id', $company->id)->paymentMethods()->orderBy('code')->get() : collect();

        return view('livewire.admin.payment-methods.index', [
            'companies' => $companies->mapWithKeys(fn (Company $item): array => [$item->id => $item->name.' ('.$item->code.')'])->all(),
            'methods' => $methods,
            'balances' => app(LedgerService::class)->balances($methods),
        ])->layout('layouts.admin');
    }
}
