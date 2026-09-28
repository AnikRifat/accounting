<div class="page">
    <x-notices />
    <x-page-header :title="$templateId ? __('Edit template') : __('Add template')" :back="route('admin.sales.templates.index')" :back-label="__('Templates')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <div class="grid items-start gap-4 xl:grid-cols-2">
        <form wire:submit="save" class="stack min-w-0">
            <x-card :title="__('Branding')">
                <div class="form-grid">
                    <div class="span-full"><x-form.input name="name" :label="__('Template name')" wire:model="name" required maxlength="100" autocomplete="off" :placeholder="__('Standard, Letterhead, Minimal…')" /></div>
                    <x-form.select name="layout" :label="__('Base layout')" :options="$layouts" wire:model="layout" />
                    <x-form.select name="font" :label="__('Font')" :options="$fonts" wire:model="font" />
                    <x-form.input type="color" name="accentColor" :label="__('Accent colour')" wire:model="accentColor" class="max-w-24 cursor-pointer px-1" :help="__('Used for headings and the item table header.')" />
                    <x-form.input name="vatNumber" :label="__('BIN/VAT number')" wire:model="vatNumber" maxlength="50" autocomplete="off" />
                    <div class="stack-sm">
                        <x-form.image name="logo" :label="__('Logo')" :current="$currentLogo" :current-name="$currentLogo" :help="__('Optional. JPG, PNG or WebP, up to 2 MB.')" />
                        @if($currentLogo && ! $logo)<x-form.checkbox name="removeLogo" :label="__('Remove the current logo')" wire:model="removeLogo" />@endif
                    </div>
                    <div class="stack-sm">
                        <x-form.image name="signature" :label="__('Signature')" :current="$currentSignature" :current-name="$currentSignature" :help="__('Optional. A scanned signature or stamp, up to 2 MB.')" />
                        @if($currentSignature && ! $signature)<x-form.checkbox name="removeSignature" :label="__('Remove the current signature')" wire:model="removeSignature" />@endif
                    </div>
                </div>
            </x-card>

            <x-card :title="__('Texts')" :description="__('Printed on every document that uses this template.')">
                <div class="stack">
                    @foreach(['headerText' => [__('Header text'), __('Under the company address, e.g. email and website.')], 'bankDetails' => [__('Bank details'), __('Account name, number, bank and branch, bKash number…')], 'footerText' => [__('Footer text'), __('At the bottom of the page, e.g. a thank-you line.')]] as $field => [$fieldLabel, $fieldHelp])
                        <div class="field">
                            <label for="{{ $field }}">{{ $fieldLabel }}</label>
                            <textarea id="{{ $field }}" name="{{ $field }}" class="form-control h-auto py-2" rows="3" maxlength="1000" wire:model="{{ $field }}" aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}" aria-describedby="{{ $field }}-{{ $errors->has($field) ? 'error' : 'help' }}"></textarea>
                            @error($field)<p id="{{ $field }}-error" class="error">{{ $message }}</p>@else<p id="{{ $field }}-help" class="field-help">{{ $fieldHelp }}</p>@enderror
                        </div>
                    @endforeach
                    <x-form.checkbox name="isDefault" :label="__('Company default template (used when a document type has none of its own)')" wire:model="isDefault" />
                </div>
            </x-card>

            <x-card :title="__('Blocks')" :description="__('Drag the handle, or use the arrows, to change the order. Untick a block to hide it.')">
                <x-slot:actions><x-button variant="secondary" size="sm" icon="plus" wire:click="addTextBlock">{{ __('Add text block') }}</x-button></x-slot:actions>
                @error('sections')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
                <ol class="stack-sm" wire:sort="sortSection" aria-label="{{ __('Blocks in print order') }}">
                    @foreach($sections as $index => $section)
                        @php($blockLabel = $blockLabels[$section['key']] ?? $section['key'])
                        <li wire:key="section-{{ $section['id'] }}" wire:sort:item="{{ $section['id'] }}" @class(['rounded-lg border border-border bg-card p-3', 'opacity-60' => ! $section['visible']])>
                            <div class="flex items-center gap-2">
                                <span wire:sort:handle class="cursor-grab select-none px-1 text-lg leading-none text-muted-foreground" title="{{ __('Drag to reorder') }}" aria-hidden="true">⠿</span>
                                <div class="min-w-0 flex-1"><x-form.checkbox :name="'sections.'.$index.'.visible'" :id="'section-'.$section['id']" :label="$blockLabel" wire:model.live="sections.{{ $index }}.visible" /></div>
                                <div class="btn-group" wire:sort:ignore>
                                    <x-button variant="ghost" size="sm" icon="chevron-down" class="rotate-180" wire:click="sortSection('{{ $section['id'] }}', {{ $index - 1 }})" :disabled="$index === 0" :label="__('Move :block up', ['block' => $blockLabel])" />
                                    <x-button variant="ghost" size="sm" icon="chevron-down" wire:click="sortSection('{{ $section['id'] }}', {{ $index + 1 }})" :disabled="$loop->last" :label="__('Move :block down', ['block' => $blockLabel])" />
                                    @if($section['key'] === \App\Models\DocumentTemplate::TEXT_BLOCK)
                                        <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="removeSection('{{ $section['id'] }}')" :label="__('Remove this text block')" />
                                    @endif
                                </div>
                            </div>
                            @if($section['key'] === \App\Models\DocumentTemplate::TEXT_BLOCK)
                                <div class="field mt-2" wire:sort:ignore>
                                    <label class="sr-only" for="section-text-{{ $section['id'] }}">{{ __('Text') }}</label>
                                    <textarea id="section-text-{{ $section['id'] }}" class="form-control h-auto py-2" rows="3" maxlength="2000" wire:model="sections.{{ $index }}.text" placeholder="{{ __('Any text, e.g. delivery instructions or a warranty note.') }}"></textarea>
                                    @error('sections.'.$index.'.text')<p class="error">{{ $message }}</p>@enderror
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </x-card>

            <x-form.actions :submit="__('Save template')" :cancel="route('admin.sales.templates.index')" />
        </form>

        <x-card :title="__('Preview')" :description="__('A sample document with the saved template. Save to update it.')" class="min-w-0 xl:sticky xl:top-4">
            @if($templateId)
                <x-slot:actions>
                    <label class="sr-only" for="previewType">{{ __('Preview as') }}</label>
                    <select id="previewType" class="form-control" wire:model.live="previewType">
                        @foreach($previewTypes as $value => $typeLabel)<option value="{{ $value }}">{{ $typeLabel }}</option>@endforeach
                    </select>
                </x-slot:actions>
                <iframe wire:key="preview-{{ $previewType }}-{{ $previewVersion }}" class="h-[75vh] w-full rounded-lg border border-border bg-surface-muted" title="{{ __('Template preview') }}"
                    src="{{ route('admin.sales.templates.preview', ['template' => $templateId, 'type' => $previewType, 'v' => $previewVersion]) }}"></iframe>
            @else
                <x-empty-state :title="__('Save to see a preview')" emoji="🖼️" :description="__('The preview appears here once the template is saved for the first time.')" />
            @endif
        </x-card>
    </div>
</div>
