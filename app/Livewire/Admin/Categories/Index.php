<?php

namespace App\Livewire\Admin\Categories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
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
        $categories = $company ? Account::query()->where('company_id', $company->id)->categories()->orderBy('name')->get() : collect();

        return view('livewire.admin.categories.index', [
            'companies' => $companies->mapWithKeys(fn (Company $item): array => [$item->id => $item->name.' ('.$item->code.')'])->all(),
            'groups' => [
                __('Income categories') => $categories->where('type', AccountType::Income),
                __('Expense categories') => $categories->where('type', AccountType::Expense),
            ],
        ])->layout('layouts.admin');
    }
}
