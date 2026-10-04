<div class="page">
    <x-notices />
    <x-page-header :title="__('Settings')" :description="__('How each module behaves for everyone. Storage credentials stay in environment configuration.')" />
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
                            @case('secret')
                                <x-form.input :name="$name" :label="$field['label']" type="password" autocomplete="new-password" wire:model="{{ $name }}"
                                    :placeholder="in_array($key, $savedSecrets, true) ? '••••••••' : null"
                                    :help="in_array($key, $savedSecrets, true) ? __('A password is saved. Leave empty to keep it.') : ($field['help'] ?? null)" />
                                @break
                            @default
                                <x-form.input :name="$name" :label="$field['label']" :type="$field['type'] === 'email' ? 'email' : 'text'" :help="$field['help'] ?? null" wire:model="{{ $name }}" />
                        @endswitch
                    @endforeach
                </div>
            </x-card>
        @endforeach
        @if($tab === 'mail')
            @can('settings.update')
                <x-card :title="__('Send a test email')" :description="__('Sends with the saved settings. Save your changes first.')" wire:key="settings-mail-test">
                    <div class="form-grid">
                        <x-form.input name="testEmail" :label="__('Send to')" type="email" autocomplete="email" wire:model="testEmail" wire:keydown.enter.prevent="sendTestEmail" />
                        <div class="span-full">
                            <x-button variant="secondary" wire:click="sendTestEmail" wire:loading.attr="disabled" wire:target="sendTestEmail">
                                <span wire:loading.remove wire:target="sendTestEmail">{{ __('Send test email') }}</span>
                                <span wire:loading wire:target="sendTestEmail">{{ __('Sending…') }}</span>
                            </x-button>
                        </div>
                        @if($testResult)
                            <x-alert :tone="$testResult['tone']" class="span-full">{{ $testResult['message'] }}</x-alert>
                        @endif
                    </div>
                </x-card>
            @endcan
        @endif
        @can('settings.update')
            <x-form.actions :submit="__('Save settings')">
                <x-button variant="ghost" wire:click="restoreDefaults" wire:confirm="{{ __('Put every setting on this tab back to its default? Nothing changes until you save.') }}">{{ __('Restore defaults') }}</x-button>
            </x-form.actions>
        @endcan
    </form>
</div>
