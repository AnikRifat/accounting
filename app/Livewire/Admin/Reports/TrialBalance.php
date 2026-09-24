<?php

namespace App\Livewire\Admin\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every account with a non-zero balance as of a date, split into debit and credit columns, for the header company
 * context. In All mode accounts are consolidated by (type, name) across companies; each company's books balance, so
 * the consolidated totals balance too.
 */
class TrialBalance extends Component
{
    #[Url(as: 'as_of')]
    public string $asOf = '';

    public function mount(): void
    {
        if ($this->asOf === '') {
            $this->asOf = CarbonImmutable::today()->toDateString();
        }
    }

    public function render(): View
    {
        Gate::authorize('reports.view');
        $context = app(CompanyContext::class);
        $this->resetErrorBag('asOf');
        $asOf = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->asOf, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            ? CarbonImmutable::parse($this->asOf) : null;
        if ($asOf === null) {
            $this->addError('asOf', __('Enter a valid date.'));
        }

        $rows = collect();
        if ($asOf && $context->companyIds() !== []) {
            $accounts = Account::query()->whereIn('company_id', $context->companyIds())->with('company:id,code')->orderBy('code')->get();
            $balances = app(LedgerService::class)->balances($accounts, $asOf);
            $groups = $context->isAll()
                ? $accounts->groupBy(fn (Account $account): string => $account->type->value.'|'.mb_strtolower(trim($account->name)))
                : $accounts->mapWithKeys(fn (Account $account): array => [$account->id => collect([$account])]);
            $rows = $groups->map(fn (Collection $group): array => $this->row($group, $balances))
                ->filter(fn (array $row): bool => $row['debit'] !== 0 || $row['credit'] !== 0)
                ->sortBy([['code', 'asc'], ['name', 'asc']])->values();
        }
        $debitTotal = $rows->sum('debit');
        $creditTotal = $rows->sum('credit');

        return view('livewire.admin.reports.trial-balance', [
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()->name,
            'consolidated' => $context->isAll(),
            'asOfLabel' => $asOf?->format('d M Y'),
            'rows' => $rows,
            'debitTotal' => $debitTotal,
            'creditTotal' => $creditTotal,
            'balanced' => $debitTotal === $creditTotal,
        ])->layout('layouts.admin');
    }

    /**
     * One trial balance row for accounts of the same type (a single account, or one name across companies).
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, int>  $balances  normal-side balances keyed by account id
     * @return array{code: string, name: string, type: AccountType, companies: list<string>, debit: int, credit: int}
     */
    private function row(Collection $accounts, array $balances): array
    {
        $first = $accounts->first();
        $balance = $accounts->sum(fn (Account $account): int => $balances[$account->id]);
        // A debit-normal account with a positive balance sits in the debit column; a negative one flips sides.
        $debitSide = $first->type->isDebitNormal() === ($balance > 0);

        return ['code' => $accounts->min('code'), 'name' => $first->name, 'type' => $first->type,
            'companies' => $accounts->filter(fn (Account $account): bool => $balances[$account->id] !== 0)->pluck('company.code')->all(),
            'debit' => $debitSide ? abs($balance) : 0, 'credit' => $debitSide ? 0 : abs($balance)];
    }
}
