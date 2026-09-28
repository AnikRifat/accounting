<div class="page">
    <x-notices />
    <x-page-header :title="__('Sources')" :description="__('Where your leads come from. Each company keeps its own list.')">
        @can('crm.setup.manage')<x-slot:actions><x-button icon="plus" :href="route('admin.crm.sources.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add source') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    @error('delete')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar><x-form.input name="search" :label="__('Search sources')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Source name…')" /></x-toolbar>
        </x-slot:toolbar>
        <x-table :caption="__('Sources')">
            <x-slot:head><th>{{ __('Source') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th class="num">{{ __('Leads') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($sources as $source)
                <tr wire:key="source-{{ $source->id }}">
                    <td><strong>{{ $source->name }}</strong></td>
                    @if($showCompany)<td>{{ $source->company->name }}</td>@endif
                    <td class="num">@if($source->leads_count > 0)<a class="text-link" href="{{ route('admin.crm.leads.index', ['source' => $source->name]) }}" wire:navigate>{{ $source->leads_count }}</a>@else 0 @endif</td>
                    <td><x-badge.active :active="$source->is_active" /></td>
                    <td><div class="row-actions">
                        @can('crm.setup.manage')
                            <x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.crm.sources.edit', $source)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $source->id }}')" :label="__('Edit :name', ['name' => $source->name])">{{ __('Edit') }}</x-button>
                            <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $source->id }})" wire:confirm="{{ __('Delete :name?', ['name' => $source->name]) }}" :label="__('Delete :name', ['name' => $source->name])" />
                        @endcan
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 5 : 4" emoji="📣">{{ __('No sources yet.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $sources->links() }}
    </x-card>
    <x-sheet :label="__('Source')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.crm.sources.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.crm.sources.form :source="\App\Models\CrmSource::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
