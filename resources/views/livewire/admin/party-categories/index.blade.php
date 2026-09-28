<div class="page">
    <x-notices />
    <x-page-header :title="__('Party categories')" :description="__('Your own groups for parties, such as Customer, Supplier or Landlord. Each company keeps its own list.')" :back="route('admin.parties.index')" :back-label="__('Parties')">
        @can('parties.update')<x-slot:actions><x-button icon="plus" :href="route('admin.party-categories.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add category') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar><x-form.input name="search" :label="__('Search categories')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Category name…')" /></x-toolbar>
        </x-slot:toolbar>
        <x-table :caption="__('Party categories')">
            <x-slot:head><th>{{ __('Category') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th class="num">{{ __('Parties') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($categories as $category)
                <tr wire:key="party-category-{{ $category->id }}">
                    <td><strong>{{ $category->name }}</strong>@if($category->is_system) <x-badge tone="info">{{ __('Built-in') }}</x-badge><p class="muted">{{ __('Given to every employee automatically, for tracking.') }}</p>@endif</td>
                    @if($showCompany)<td>{{ $category->company->name }}</td>@endif
                    <td class="num">@if($category->parties_count > 0)<a class="text-link" href="{{ route('admin.parties.index', ['category' => $category->name]) }}" wire:navigate>{{ $category->parties_count }}</a>@else 0 @endif</td>
                    <td><x-badge.active :active="$category->is_active" /></td>
                    <td><div class="row-actions">
                        @if(! $category->is_system)@can('parties.update')
                            <x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.party-categories.edit', $category)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $category->id }}')" :label="__('Edit :name', ['name' => $category->name])">{{ __('Edit') }}</x-button>
                            <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $category->id }})" wire:confirm="{{ trans_choice('Delete :name? Its :count party stays, without a category.|Delete :name? Its :count parties stay, without a category.', $category->parties_count, ['name' => $category->name, 'count' => $category->parties_count]) }}" :label="__('Delete :name', ['name' => $category->name])" />
                        @endcan @endif
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 5 : 4" emoji="🗂️">{{ __('No party categories yet.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $categories->links() }}
    </x-card>
    <x-sheet :label="__('Party category')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.party-categories.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.party-categories.form :party-category="\App\Models\PartyCategory::visibleTo(auth()->user())->custom()->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
