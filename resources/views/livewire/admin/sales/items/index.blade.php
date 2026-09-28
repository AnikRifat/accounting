<div class="page">
    <x-notices />
    <x-page-header :title="__('Items')" :description="__('Products and services you sell, with their price, VAT and income category. Picking one on an invoice line fills the line in.')" :back="route('admin.sales.dashboard')" :back-label="__('Sales')">
        @can('sales.setup')<x-slot:actions><x-button icon="plus" :href="route('admin.sales.items.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add item') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.input name="search" :label="__('Search items')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Name or description…')" />
                <x-form.select name="status" :label="__('Status')" wire:model.live="status" :options="$statusOptions" />
            </x-toolbar>
        </x-slot:toolbar>
        <x-table :caption="__('Items')">
            <x-slot:head><th>{{ __('Item') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Unit') }}</th><th class="num">{{ __('Price') }}</th><th class="num">{{ __('VAT') }}</th><th>{{ __('Income category') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($items as $item)
                <tr wire:key="item-{{ $item->id }}">
                    <td><strong>{{ $item->name }}</strong>@if($item->description)<p class="muted">{{ $item->description }}</p>@endif</td>
                    @if($showCompany)<td>{{ $item->company->name }}</td>@endif
                    <td>{{ $item->unit ?? '—' }}</td>
                    <td class="num"><x-money :value="$item->price" /></td>
                    <td class="num">{{ \App\Support\DocumentMath::formatBasisPoints($item->tax_rate) }}%</td>
                    <td>{{ $item->account?->name ?? '—' }}</td>
                    <td><x-badge.active :active="$item->is_active" /></td>
                    <td><div class="row-actions">
                        @can('sales.setup')
                            <x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.sales.items.edit', $item)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $item->id }}')" :label="__('Edit :name', ['name' => $item->name])">{{ __('Edit') }}</x-button>
                            <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $item->id }})" wire:confirm="{{ __('Delete :name? Documents that used it keep their lines.', ['name' => $item->name]) }}" :label="__('Delete :name', ['name' => $item->name])" />
                        @endcan
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 8 : 7" emoji="📦">{{ $search !== '' || $status !== '' ? __('No items match these filters.') : __('No items yet.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $items->links() }}
    </x-card>
    <x-sheet :label="__('Item')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.sales.items.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.sales.items.form :item="\App\Models\Item::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
