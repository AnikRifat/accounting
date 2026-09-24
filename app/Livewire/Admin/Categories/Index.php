<?php

namespace App\Livewire\Admin\Categories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Index extends Component
{
    /** Opens a category of another company: selects that company in the header, then edits the category there. */
    public function editIn(int $accountId): void
    {
        Gate::authorize('accounts.manage');
        $category = Account::query()->categories()->whereIn('company_id', auth()->user()->accessibleCompanyIds())->findOrFail($accountId);
        app(CompanyContext::class)->select($category->company_id);
        $this->redirectRoute('admin.categories.edit', ['category' => $category], navigate: true);
    }

    public function render(): View
    {
        Gate::authorize('accounts.view');
        $context = app(CompanyContext::class);
        $categories = Account::query()->whereIn('company_id', $context->companyIds())->categories()->with('company')->orderBy('name')->get();
        $sides = [AccountType::Income->value => __('Income categories'), AccountType::Expense->value => __('Expense categories')];

        return view('livewire.admin.categories.index', [
            'isAll' => $context->isAll(),
            'hasCompanies' => $context->companyIds() !== [],
            // In All mode one row stands for every company's category of the same type and name.
            'groups' => collect($sides)->mapWithKeys(fn (string $heading, string $type): array => [$heading => $categories
                ->filter(fn (Account $category): bool => $category->type->value === $type)
                ->groupBy(fn (Account $category): string => mb_strtolower($category->name))
                ->map(fn ($rows) => $rows->sortBy(fn (Account $category): string => $category->company->name)->values())]),
        ])->layout('layouts.admin');
    }
}
