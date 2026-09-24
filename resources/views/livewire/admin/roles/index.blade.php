<div class="page">
    <x-notices />
    <x-page-header :title="__('Roles & permissions')" :description="__('System roles are fixed. Custom roles grant explicit abilities.')">
        @can('roles.create')@can('permissions.manage')<x-slot:actions><x-button icon="plus" :href="route('admin.roles.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Create custom role') }}</x-button></x-slot:actions> @endcan @endcan
    </x-page-header>
    @error('role')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <x-card flush>
        <x-table :caption="__('Roles & permissions')">
            <x-slot:head><th>{{ __('Role') }}</th><th>{{ __('Type') }}</th><th>{{ __('Abilities') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach($roles as $role)
                <tr wire:key="role-{{ $role }}">
                    <td><strong>{{ $registry->label($role) }}</strong><p class="muted">{{ $role }}</p></td>
                    <td>@if($registry->isCustom($role))<x-badge tone="primary">{{ __('Custom') }}</x-badge>@else<x-badge tone="info">{{ __('System') }}</x-badge>@endif</td>
                    <td class="nowrap">{{ count($registry->forRole($role)) }} / {{ count($registry->catalogue()) }}</td>
                    <td><x-badge.active :active="$registry->isActive($role)" :on="__('Enabled')" :off="__('Disabled')" /></td>
                    <td><div class="row-actions">
                        @can('roles.update')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.roles.edit', $role)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $role }}')" :label="__('Edit :name', ['name' => $registry->label($role)])">{{ __('Edit') }}</x-button>@endcan
                        @if($registry->isCustom($role))@can('roles.delete')<x-button variant="danger" size="sm" icon="trash" wire:click="delete('{{ $role }}')" wire:confirm="{{ __('Delete this custom role? Assigned roles cannot be deleted.') }}" :label="__('Delete :name', ['name' => $registry->label($role)])">{{ __('Delete') }}</x-button>@endcan @endif
                    </div></td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
    <x-sheet :label="__('Role')" size="lg">
        @if($this->sheetAction() === 'create')
            @can('permissions.manage')<livewire:admin.roles.form :key="'sheet-'.$sheet" />@endcan
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.roles.form :role="$this->sheetArgument()" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
