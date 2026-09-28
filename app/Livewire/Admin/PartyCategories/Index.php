<?php

namespace App\Livewire\Admin\PartyCategories;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\PartyCategory;
use App\Support\CompanyContext;
use App\Support\Configuration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/** Party categories of the header companies. Managing them needs `parties.update`. */
class Index extends Component
{
    use WithFormSheet, WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Deletes a custom category; its parties stay, uncategorised. The built-in Employee category is never deleted. */
    public function delete(int $categoryId): void
    {
        Gate::authorize('parties.update');
        $category = PartyCategory::visibleTo(auth()->user())->custom()->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($categoryId);
        $category->delete();
        session()->now('success', __(':name deleted.', ['name' => $category->name]));
    }

    public function render(): View
    {
        Gate::authorize('parties.view');
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.party-categories.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'categories' => PartyCategory::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->with('company:id,name')->withCount('parties')
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->orderByDesc('is_system')->orderBy('name')->paginate(Configuration::get('general.rows_per_page')),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.party-categories.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }
}
