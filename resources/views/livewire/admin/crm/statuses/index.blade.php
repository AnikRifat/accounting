<div class="page">
    <x-notices />
    <x-page-header :title="__('Statuses')" :description="__('Lead statuses say where a lead stands; closed ones end its follow-ups. Call results say how a call went.')">
        @can('crm.setup.manage')<x-slot:actions><x-button icon="plus" :href="route('admin.crm.statuses.create')" :navigate="false" wire:click.prevent="openSheet('create:{{ $statusType->value }}')">{{ $statusType === \App\Enums\CrmStatusType::Lead ? __('Add lead status') : __('Add call result') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    @error('delete')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <div class="segmented" role="group" aria-label="{{ __('Status type') }}">
        @foreach(\App\Enums\CrmStatusType::cases() as $case)
            <button type="button" wire:click="$set('type', '{{ $case->value }}')" aria-pressed="{{ $statusType === $case ? 'true' : 'false' }}">{{ $case === \App\Enums\CrmStatusType::Lead ? __('Lead statuses') : __('Call results') }}</button>
        @endforeach
    </div>
    <x-card flush>
        <x-table :caption="$statusType->label()">
            <x-slot:head><th class="num">{{ __('Order') }}</th><th>{{ __('Status') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif @if($statusType === \App\Enums\CrmStatusType::Lead)<th>{{ __('Follow-ups') }}</th>@endif<th>{{ __('Active') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($statuses as $status)
                <tr wire:key="status-{{ $status->id }}">
                    <td class="num">{{ $status->position }}</td>
                    <td><x-badge :tone="$status->tone">{{ $status->name }}</x-badge></td>
                    @if($showCompany)<td>{{ $status->company->name }}</td>@endif
                    @if($statusType === \App\Enums\CrmStatusType::Lead)<td>@if($status->is_closed)<x-badge>{{ __('Closed: no calls') }}</x-badge>@else<span class="muted">{{ __('Open') }}</span>@endif</td>@endif
                    <td><x-badge.active :active="$status->is_active" /></td>
                    <td><div class="row-actions">
                        @can('crm.setup.manage')
                            <x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.crm.statuses.edit', $status)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $status->id }}')" :label="__('Edit :name', ['name' => $status->name])">{{ __('Edit') }}</x-button>
                            <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $status->id }})" wire:confirm="{{ __('Delete :name?', ['name' => $status->name]) }}" :label="__('Delete :name', ['name' => $status->name])" />
                        @endcan
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="6" emoji="🏷️">{{ __('No statuses yet.') }}</x-table.empty>
            @endforelse
        </x-table>
    </x-card>
    <x-sheet :label="__('Status')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.crm.statuses.form :type="$this->sheetArgument() ?: 'lead'" :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.crm.statuses.form :status="\App\Models\CrmStatus::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
