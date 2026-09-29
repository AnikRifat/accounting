<?php

namespace App\Livewire\Admin\Sales\ItemCategories;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\ItemCategory;
use App\Support\CompanyContext;
use App\Support\Configuration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/** The item categories of the header companies. Viewing needs `sales.view`; adding, editing and deleting `sales.setup`. */
class Index extends Component
{
    use WithFormSheet, WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Deletes an unused category; one that items use can only be deactivated. */
    public function delete(int $categoryId): void
    {
        Gate::authorize('sales.setup');
        $this->resetErrorBag('delete');
        $category = ItemCategory::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($categoryId);
        if ($category->items()->exists()) {
            $this->addError('delete', __(':name is used by items. Deactivate it instead.', ['name' => $category->name]));

            return;
        }
        $category->delete();
        session()->now('success', __(':name deleted.', ['name' => $category->name]));
    }

    public function render(): View
    {
        Gate::authorize('sales.view');
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.sales.item-categories.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'categories' => ItemCategory::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->with('company:id,name')->withCount('items')
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->orderBy('name')->orderBy('id')->paginate(Configuration::get('general.rows_per_page')),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.sales.item-categories.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }
}
