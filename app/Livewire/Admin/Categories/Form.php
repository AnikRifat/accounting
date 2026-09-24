<?php

namespace App\Livewire\Admin\Categories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Income and expense categories are non-system income/expense accounts with an automatic code.
 * With "All companies" in the header, a new category is added to every active company that lacks it.
 */
class Form extends Component
{
    #[Locked]
    public ?int $categoryId = null;

    #[Locked]
    public bool $hasEntries = false;

    /** The category's company, or the header company when creating; null means all companies. */
    #[Locked]
    public ?int $companyId = null;

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
            $this->companyId = $category->company_id;
            $this->name = $category->name;
            $this->type = $category->type->value;
            $this->isActive = $category->is_active;
        } else {
            $this->companyId = app(CompanyContext::class)->selectedId();
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('accounts.manage');
        $existing = $this->categoryId ? Account::findOrFail($this->categoryId) : null;
        if ($existing) {
            $this->guard($existing);
        }
        // The target companies are settled before any rule runs, so the unique rule never probes a company the user cannot use.
        $companies = $existing ? collect([$existing->company]) : $this->targetCompanies();
        if ($companies === null) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        $this->name = trim($this->name);
        $single = $companies->count() === 1 && ($existing || $this->companyId !== null);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150', ...($single ? [Rule::unique('accounts', 'name')->where('company_id', $companies->first()->id)->ignore($this->categoryId)] : [])],
            'type' => ['required', Rule::in([AccountType::Income->value, AccountType::Expense->value])],
            'isActive' => ['boolean'],
        ], [], ['name' => __('name'), 'type' => __('type')]);
        $type = AccountType::from($data['type']);
        if ($existing && $type !== $existing->type && $existing->lines()->exists()) {
            $this->addError('type', __('The type of a category that already has entries cannot be changed.'));

            return null;
        }
        $skipped = $existing ? collect() : $companies->filter(fn (Company $company): bool => $company->accounts()->where('name', $data['name'])->exists());
        $targets = $companies->diff($skipped);
        if ($targets->isEmpty()) {
            $this->addError('name', __('Every company already has an account with this name.'));

            return null;
        }

        try {
            DB::transaction(function () use ($existing, $targets, $data, $type): void {
                $ledger = app(LedgerService::class);
                foreach ($targets as $company) {
                    $account = $existing ?? $company->accounts()->make();
                    // A category keeps its code unless it moves to the other side, whose codes live in another range.
                    if (! $existing || $type !== $existing->type) {
                        $account->code = $ledger->nextCode($company, $type);
                    }
                    $account->fill(['name' => $data['name'], 'type' => $type, 'is_cash' => false, 'is_active' => $data['isActive']])->save();
                }
            });
        } catch (ValidationException $exception) {
            // The only posting error here is a full code range.
            $this->addError('name', collect($exception->errors())->flatten()->first());

            return null;
        }
        session()->flash('success', $skipped->isEmpty() ? __('Category saved.')
            : __('Category saved. Skipped :companies, which already have an account with this name.', ['companies' => $skipped->pluck('name')->join(', ')]));

        return redirect()->route('admin.categories.index');
    }

    public function render(): View
    {
        return view('livewire.admin.categories.form', [
            'companyName' => $this->companyId ? Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name') : __('All companies'),
            'types' => [AccountType::Income->value => __('Income'), AccountType::Expense->value => __('Expense')],
        ])->layout('layouts.admin');
    }

    /**
     * The companies a new category goes to: the header company this page was opened for, or every
     * active visible company in All mode. Null when the header changed since, or the company is inactive.
     *
     * @return Collection<int, Company>|null
     */
    private function targetCompanies(): ?Collection
    {
        $context = app(CompanyContext::class);
        if ($context->selectedId() !== $this->companyId) {
            return null;
        }
        $companies = $context->options()->whereIn('id', $context->companyIds())->where('is_active', true)->values();

        return $companies->isEmpty() ? null : $companies;
    }

    /** Only categories of accessible companies exist on this screen. */
    private function guard(Account $account): void
    {
        abort_unless(auth()->user()->canAccessCompany($account->company_id)
            && ! $account->is_system && in_array($account->type, [AccountType::Income, AccountType::Expense], true), 404);
    }
}
