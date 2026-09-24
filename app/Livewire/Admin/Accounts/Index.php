<?php

namespace App\Livewire\Admin\Accounts;

use App\Enums\AccountType;
use App\Livewire\Concerns\WithFormSheet;
use App\Models\Account;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Accounts of the header's company with current balances. On "All companies" accounts are combined by
 * (type, name) with a summed balance and a per-company breakdown; each company keeps its own books.
 */
class Index extends Component
{
    use WithFormSheet;

    /** Opens an account's edit page in its own company, switching the header to that company. */
    public function edit(int $accountId): void
    {
        Gate::authorize('accounts.manage');
        $account = Account::query()->whereIn('company_id', auth()->user()->accessibleCompanyIds())->where('is_system', false)->findOrFail($accountId);
        app(CompanyContext::class)->select($account->company_id);
        $this->redirectRoute('admin.accounts.index', ['sheet' => 'edit:'.$account->id], navigate: true);
    }

    public function render(): View
    {
        Gate::authorize('accounts.view');
        $context = app(CompanyContext::class);
        $accounts = Account::query()->whereIn('company_id', $context->companyIds())->with('company:id,name,code')
            ->orderBy('code')->orderBy('company_id')->get();
        $balances = app(LedgerService::class)->balances($accounts);

        return view('livewire.admin.accounts.index', [
            'all' => $context->isAll(),
            'hasCompanies' => $context->options()->isNotEmpty(),
            'groups' => collect(AccountType::cases())->mapWithKeys(fn (AccountType $type): array => [
                $type->value => $context->isAll() ? $this->combined($accounts->where('type', $type), $balances) : $accounts->where('type', $type),
            ]),
            'balances' => $balances,
        ])->layout('layouts.admin');
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, int>  $balances
     * @return Collection<int, array{name: string, codes: string, balance: int, accounts: Collection<int, Account>}>
     */
    private function combined(Collection $accounts, array $balances): Collection
    {
        return $accounts->groupBy(fn (Account $account): string => mb_strtolower(trim($account->name)))->map(fn (Collection $same): array => [
            'name' => $same->first()->name,
            'codes' => $same->pluck('code')->unique()->implode(', '),
            'balance' => $same->sum(fn (Account $account): int => $balances[$account->id] ?? 0),
            'accounts' => $same->sortBy(fn (Account $account): string => $account->company->name)->values(),
        ])->values();
    }

    protected function sheetRoute(): string
    {
        return 'admin.accounts.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }
}
