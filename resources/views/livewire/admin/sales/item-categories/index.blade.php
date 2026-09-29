<div class="page">
    <x-notices />
    <x-page-header :title="__('Item categories')" :description="__('Groups for the products and services you sell. Each company keeps its own list.')" :back="route('admin.sales.items.index')" :back-label="__('Items')">
        @can('sales.setup')<x-slot:actions><x-button icon="plus" :href="route('admin.sales.item-categories.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add category') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    @error('delete')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar><x-form.input name="search" :label="__('Search categories')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Category name…')" /></x-toolbar>
        </x-slot:toolbar>
        <x-table :caption="__('Item categories')">
            <x-slot:head><th>{{ __('Category') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th class="num">{{ __('Items') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($categories as $category)
                <tr wire:key="item-category-{{ $category->id }}">
                    <td><strong>{{ $category->name }}</strong></td>
                    @if($showCompany)<td>{{ $category->company->name }}</td>@endif
                    <td class="num">@if($category->items_count > 0)<a class="text-link" href="{{ route('admin.sales.items.index', ['category' => $category->name]) }}" wire:navigate>{{ $category->items_count }}</a>@else 0 @endif</td>
                    <td><x-badge.active :active="$category->is_active" /></td>
                    <td><div class="row-actions">
                        @can('sales.setup')
                            <x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.sales.item-categories.edit', $category)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $category->id }}')" :label="__('Edit :name', ['name' => $category->name])">{{ __('Edit') }}</x-button>
                            <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $category->id }})" wire:confirm="{{ __('Delete :name?', ['name' => $category->name]) }}" :label="__('Delete :name', ['name' => $category->name])" />
                        @endcan
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 5 : 4" emoji="🗂️">{{ $search !== '' ? __('No categories match this search.') : __('No item categories yet.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $categories->links() }}
    </x-card>
    <x-sheet :label="__('Item category')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.sales.item-categories.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.sales.item-categories.form :item-category="\App\Models\ItemCategory::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
