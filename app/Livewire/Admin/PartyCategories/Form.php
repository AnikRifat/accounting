<?php

namespace App\Livewire\Admin\PartyCategories;

use App\Models\Company;
use App\Models\PartyCategory;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Form extends Component
{
    #[Locked]
    public ?int $categoryId = null;

    /** The category's own company, or the header company when creating. */
    #[Locked]
    public ?int $companyId = null;

    public string $name = '';

    public bool $isActive = true;

    public function mount(?PartyCategory $partyCategory = null): void
    {
        Gate::authorize('parties.update');
        $this->categoryId = $partyCategory?->exists ? $partyCategory->id : null;
        if ($this->categoryId) {
            // The built-in Employee category is maintained by the application only.
            abort_unless(auth()->user()->canAccessCompany($partyCategory->company_id) && ! $partyCategory->is_system, 404);
            [$this->companyId, $this->name, $this->isActive] = [$partyCategory->company_id, $partyCategory->name, $partyCategory->is_active];
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('parties.update');
        $existing = $this->categoryId ? PartyCategory::visibleTo(auth()->user())->custom()->findOrFail($this->categoryId) : null;
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        $this->name = trim($this->name);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('party_categories', 'name')->where('company_id', $company->id)->ignore($existing?->id)],
            'isActive' => ['boolean'],
        ], [], ['name' => __('name')]);
        $attributes = ['name' => $data['name'], 'is_active' => $data['isActive']];
        $existing ? $existing->update($attributes) : PartyCategory::create(['company_id' => $company->id, ...$attributes]);
        session()->flash('success', __('Party category saved.'));

        return redirect()->route('admin.party-categories.index');
    }

    public function render(): View
    {
        return view('livewire.admin.party-categories.form', [
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
