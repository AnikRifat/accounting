<?php

namespace App\Livewire\Admin\Accounts;

use App\Enums\AccountType;
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
        $company = $companies->firstWhere('id', (int) $this->companyId) ?? $companies->first();
        $this->companyId = (string) $company?->id;
        if ($company) {
            session(['ledger.company_id' => $company->id]);
        }
        $accounts = $company ? $company->accounts()->orderBy('code')->get() : collect();

        return view('livewire.admin.accounts.index', [
            'companies' => $companies->mapWithKeys(fn (Company $item): array => [$item->id => $item->name.' ('.$item->code.')'])->all(),
            'groups' => collect(AccountType::cases())->mapWithKeys(fn (AccountType $type): array => [$type->value => $accounts->where('type', $type)]),
            'balances' => app(LedgerService::class)->balances($accounts),
        ])->layout('layouts.admin');
    }
}
