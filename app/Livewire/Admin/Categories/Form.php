<?php

namespace App\Livewire\Admin\Categories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Services\LedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/** Income and expense categories are non-system income/expense accounts with an automatic code. */
class Form extends Component
{
    #[Locked]
    public ?int $categoryId = null;

    #[Locked]
    public bool $hasEntries = false;

    public string $companyId = '';

    public string $name = '';

    public string $type = 'expense';

    public bool $isActive = true;

    public function mount(?Account $category = null): void
    {
        Gate::authorize('accounts.manage');
        if ($category?->exists) {
            $this->guard($category);
            $this->categoryId = $category->id;
            $this->hasEntries = $category->lines()->exists();
            $this->companyId = (string) $category->company_id;
            $this->name = $category->name;
            $this->type = $category->type->value;
            $this->isActive = $category->is_active;
        } else {
            $companyIds = $this->activeCompanies()->pluck('id')->all();
            $remembered = (int) session('ledger.company_id');
            $this->companyId = (string) (in_array($remembered, $companyIds, true) ? $remembered : ($companyIds[0] ?? ''));
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('accounts.manage');
        $existing = $this->categoryId ? Account::findOrFail($this->categoryId) : null;
        if ($existing) {
            $this->guard($existing);
            $this->companyId = (string) $existing->company_id;
        }
        // Validate the company alone first, so the unique rule below never runs against a company the user cannot use.
        $this->validate(['companyId' => ['required', Rule::in($existing ? [$existing->company_id] : $this->activeCompanies()->pluck('id')->all())]], [], ['companyId' => __('company')]);
        $companyId = (int) $this->companyId;
        $this->name = trim($this->name);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('accounts', 'name')->where('company_id', $companyId)->ignore($this->categoryId)],
            'type' => ['required', Rule::in([AccountType::Income->value, AccountType::Expense->value])],
            'isActive' => ['boolean'],
        ], [], ['name' => __('name'), 'type' => __('type')]);
        $type = AccountType::from($data['type']);
        if ($existing && $type !== $existing->type && $existing->lines()->exists()) {
            $this->addError('type', __('The type of a category that already has entries cannot be changed.'));

            return null;
        }
        try {
            DB::transaction(function () use ($existing, $companyId, $data, $type): void {
                $company = Company::findOrFail($companyId);
                $account = $existing ?? $company->accounts()->make();
                // A category keeps its code unless it moves to the other side, whose codes live in another range.
                if (! $existing || $type !== $existing->type) {
                    $account->code = app(LedgerService::class)->nextCode($company, $type);
                }
                $account->fill(['name' => $data['name'], 'type' => $type, 'is_cash' => false, 'is_active' => $data['isActive']])->save();
            });
        } catch (ValidationException $exception) {
            // The only posting error here is a full code range.
            $this->addError('name', collect($exception->errors())->flatten()->first());

            return null;
        }
        session(['ledger.company_id' => $companyId]);
        session()->flash('success', __('Category saved.'));

        return redirect()->route('admin.categories.index');
    }

    public function render(): View
    {
        $companies = $this->categoryId ? Company::visibleTo(auth()->user()) : $this->activeCompanies();

        return view('livewire.admin.categories.form', [
            'companies' => ['' => __('Select a company')] + $companies->orderBy('name')->get(['id', 'name', 'code'])
                ->mapWithKeys(fn (Company $company): array => [$company->id => $company->name.' ('.$company->code.')'])->all(),
            'types' => [AccountType::Income->value => __('Income'), AccountType::Expense->value => __('Expense')],
        ])->layout('layouts.admin');
    }

    /** New categories can only be added to active companies the user can access. */
    private function activeCompanies(): Builder
    {
        return Company::visibleTo(auth()->user())->where('is_active', true);
    }

    /** Only categories of accessible companies exist on this screen. */
    private function guard(Account $account): void
    {
        abort_unless(auth()->user()->canAccessCompany($account->company_id)
            && ! $account->is_system && in_array($account->type, [AccountType::Income, AccountType::Expense], true), 404);
    }
}
