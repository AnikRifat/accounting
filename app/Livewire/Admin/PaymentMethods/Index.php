<?php

namespace App\Livewire\Admin\PaymentMethods;

use App\Models\Account;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        Gate::authorize('accounts.view');
        $context = app(CompanyContext::class);
        $methods = Account::query()->whereIn('company_id', $context->companyIds())->paymentMethods()->orderBy('code')->get();
        $balances = app(LedgerService::class)->balances($methods);
        // Payment methods are separate real accounts, so they are grouped by company, never merged.
        $groups = $context->options()->whereIn('id', $context->companyIds())
            ->map(fn ($company): array => ['company' => $company, 'methods' => $methods->where('company_id', $company->id)])
            ->filter(fn (array $group): bool => $group['methods']->isNotEmpty())
            ->map(fn (array $group): array => [...$group, 'total' => $group['methods']->sum(fn (Account $method): int => $balances[$method->id] ?? 0)])
            ->values();

        return view('livewire.admin.payment-methods.index', [
            'showCompany' => $context->isAll(),
            'hasCompanies' => $context->companyIds() !== [],
            'groups' => $groups,
            'balances' => $balances,
            'grandTotal' => $groups->sum('total'),
        ])->layout('layouts.admin');
    }
}
