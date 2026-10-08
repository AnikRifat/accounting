<?php

namespace App\Livewire\Admin\Sales\ItemCategories;

use App\Models\Company;
use App\Models\ItemCategory;
use App\Support\CompanyContext;
use App\Support\Modules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/** Adds or renames an item category. */
class Form extends Component
{
    #[Locked]
    public ?int $categoryId = null;

    /** The category's own company, or the header company when adding. */
    #[Locked]
    public ?int $companyId = null;

    public string $name = '';

    public bool $isActive = true;

    public function mount(?ItemCategory $itemCategory = null): void
    {
        Gate::authorize('sales.setup');
        $this->categoryId = $itemCategory?->exists ? $itemCategory->id : null;
        if ($this->categoryId) {
            abort_unless(auth()->user()->canAccessCompany($itemCategory->company_id, Modules::SALES), 404);
            [$this->companyId, $this->name, $this->isActive] = [$itemCategory->company_id, $itemCategory->name, $itemCategory->is_active];
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('sales.setup');
        $existing = $this->categoryId ? ItemCategory::visibleTo(auth()->user())->findOrFail($this->categoryId) : null;
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        $this->name = trim($this->name);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('item_categories', 'name')->where('company_id', $company->id)->ignore($existing?->id)],
            'isActive' => ['boolean'],
        ], [], ['name' => __('name')]);
        $attributes = ['name' => $data['name'], 'is_active' => $data['isActive']];
        $existing ? $existing->update($attributes) : ItemCategory::create(['company_id' => $company->id, ...$attributes]);
        session()->flash('success', __('Category saved.'));

        return redirect()->route('admin.sales.item-categories.index');
    }

    public function render(): View
    {
        return view('livewire.admin.sales.item-categories.form', [
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
        ])->layout('layouts.admin');
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();

        return $company?->is_active && $company->id === $this->companyId ? $company : null;
    }
}
