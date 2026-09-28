<?php

namespace App\Livewire\Admin\Sales\Items;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\Item;
use App\Support\CompanyContext;
use App\Support\Configuration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The item catalogue of the header companies. Viewing needs `sales.view`; adding, editing and deleting `sales.setup`. */
class Index extends Component
{
    use WithFormSheet, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** '', 'active' or 'inactive'. */
    #[Url(except: '')]
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /** Deletes an item; document lines that used it keep their text and figures and lose only the link. */
    public function delete(int $itemId): void
    {
        Gate::authorize('sales.setup');
        $item = Item::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($itemId);
        $item->delete();
        session()->now('success', __(':name deleted.', ['name' => $item->name]));
    }

    public function render(): View
    {
        Gate::authorize('sales.view');
        if (! in_array($this->status, ['', 'active', 'inactive'], true)) {
            $this->status = '';
        }
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.sales.items.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'statusOptions' => ['' => __('Active and inactive'), 'active' => __('Active'), 'inactive' => __('Inactive')],
            'items' => Item::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->with(['company:id,name', 'account:id,name'])
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%')))
                ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'))
                ->orderBy('name')->orderBy('id')->paginate(Configuration::get('general.rows_per_page')),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.sales.items.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }
}
