<div class="page">
    <x-notices />
    <x-page-header :title="__('Categories')" :description="$isAll ? __('What money is earned from or spent on, combined across companies. Each company keeps its own categories.') : __('What money is earned from or spent on.')">
        @can('accounts.manage')<x-slot:actions><x-button icon="plus" :href="route('admin.categories.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ $isAll ? __('Add category to all companies') : __('Add category') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    @if(! $hasCompanies)
        <x-card><x-empty-state emoji="🔒" :title="__('No company yet')" :description="__('You are not assigned to any company yet.')" /></x-card>
    @else
        <div class="grid-2">
            @foreach($groups as $heading => $rows)
                <x-card flush wire:key="group-{{ $loop->index }}">
                    <x-table :caption="$heading">
                        <x-slot:head><th>{{ $heading }}</th>@if($isAll)<th>{{ __('Companies') }}</th>@else<th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th>@endif</x-slot:head>
                        @forelse($rows as $key => $categories)
                            @if($isAll)
                                <tr wire:key="category-{{ $loop->parent->index }}-{{ $loop->index }}"><td><strong>{{ $categories->first()->name }}</strong></td><td><ul class="stack-sm">
                                    @foreach($categories as $category)<li wire:key="in-{{ $category->id }}" class="flex flex-wrap items-center gap-2">{{ $category->company->name }} <x-badge.active :active="$category->is_active" /> @can('accounts.manage')<x-button variant="ghost" size="sm" icon="pencil" wire:click="editIn({{ $category->id }})" :label="__('Edit :name in :company', ['name' => $category->name, 'company' => $category->company->name])" />@endcan</li>@endforeach
                                </ul></td></tr>
                            @else
                                @php($category = $categories->first())
                                <tr wire:key="category-{{ $category->id }}"><td><strong>{{ $category->name }}</strong><p class="muted">{{ $category->code }}</p></td><td><x-badge.active :active="$category->is_active" /></td><td><div class="row-actions">@can('accounts.manage')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.categories.edit', $category)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $category->id }}')" :label="__('Edit :name', ['name' => $category->name])">{{ __('Edit') }}</x-button>@endcan</div></td></tr>
                            @endif
                        @empty
                            <x-table.empty :colspan="$isAll ? 2 : 3" emoji="🏷️">{{ __('No categories yet.') }}</x-table.empty>
                        @endforelse
                    </x-table>
                </x-card>
            @endforeach
        </div>
    @endif
    <x-sheet :label="__('Category')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.categories.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.categories.form :category="\App\Models\Account::query()->whereIn('company_id', auth()->user()->accessibleCompanyIds())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
