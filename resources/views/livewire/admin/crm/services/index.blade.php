<div class="page">
    <x-notices />
    <x-page-header :title="__('Services')" :description="__('What your leads are interested in. Each company keeps its own list.')">
        @can('crm.setup.manage')<x-slot:actions><x-button icon="plus" :href="route('admin.crm.services.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add service') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    @error('delete')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar><x-form.input name="search" :label="__('Search services')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Service name…')" /></x-toolbar>
        </x-slot:toolbar>
        <x-table :caption="__('Services')">
            <x-slot:head><th>{{ __('Service') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th class="num">{{ __('Leads') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($services as $service)
                <tr wire:key="service-{{ $service->id }}">
                    <td><strong>{{ $service->name }}</strong></td>
                    @if($showCompany)<td>{{ $service->company->name }}</td>@endif
                    <td class="num">@if($service->leads_count > 0)<a class="text-link" href="{{ route('admin.crm.leads.index', ['service' => $service->name]) }}" wire:navigate>{{ $service->leads_count }}</a>@else 0 @endif</td>
                    <td><x-badge.active :active="$service->is_active" /></td>
                    <td><div class="row-actions">
                        @can('crm.setup.manage')
                            <x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.crm.services.edit', $service)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $service->id }}')" :label="__('Edit :name', ['name' => $service->name])">{{ __('Edit') }}</x-button>
                            <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $service->id }})" wire:confirm="{{ __('Delete :name?', ['name' => $service->name]) }}" :label="__('Delete :name', ['name' => $service->name])" />
                        @endcan
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 5 : 4" emoji="🧰">{{ __('No services yet.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $services->links() }}
    </x-card>
    <x-sheet :label="__('Service')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.crm.services.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.crm.services.form :service="\App\Models\CrmService::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
