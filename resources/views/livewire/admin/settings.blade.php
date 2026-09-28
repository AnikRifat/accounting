<div class="page">
    <x-notices />
    <x-page-header :title="__('Settings')" :description="__('How each module behaves for everyone. Storage and mail credentials stay in environment configuration.')" />
    <div class="segmented" role="group" aria-label="{{ __('Module') }}">
        @foreach($sections as $key => $tabSection)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}"><span aria-hidden="true">{{ $tabSection['emoji'] }}</span> {{ $tabSection['label'] }}</button>
        @endforeach
    </div>
    <form wire:submit="save" class="stack">
        @foreach($section['groups'] as $groupLabel => $fields)
            <x-card :title="$groupLabel" wire:key="settings-{{ $tab }}-{{ $loop->index }}">
                <div class="form-grid">
                    @foreach($fields as $key => $field)
                        @php($name = 'values.'.$key)
                        @switch($field['type'])
                            @case('bool')
                                <div class="span-full"><x-form.checkbox :name="$name" :label="$field['label']" :help="$field['help'] ?? null" wire:model="{{ $name }}" /></div>
                                @break
                            @case('select')
                                <x-form.select :name="$name" :label="$field['label']" :options="$field['options']" :help="$field['help'] ?? null" wire:model="{{ $name }}" />
                                @break
                            @case('int')
                                <x-form.input :name="$name" :label="$field['label']" type="number" min="0" step="1" inputmode="numeric" :help="$field['help'] ?? null" wire:model="{{ $name }}" />
                                @break
                            @case('decimal')
                                <x-form.input :name="$name" :label="$field['label']" type="number" min="0" step="0.01" inputmode="decimal" :help="$field['help'] ?? null" wire:model="{{ $name }}" />
                                @break
                            @default
                                <x-form.input :name="$name" :label="$field['label']" :type="$field['type'] === 'email' ? 'email' : 'text'" :help="$field['help'] ?? null" wire:model="{{ $name }}" />
                        @endswitch
                    @endforeach
                </div>
            </x-card>
        @endforeach
        @can('settings.update')
            <x-form.actions :submit="__('Save settings')">
                <x-button variant="ghost" wire:click="restoreDefaults" wire:confirm="{{ __('Put every setting on this tab back to its default? Nothing changes until you save.') }}">{{ __('Restore defaults') }}</x-button>
            </x-form.actions>
        @endcan
    </form>
</div>
