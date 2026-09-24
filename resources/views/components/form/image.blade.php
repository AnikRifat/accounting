@props(['name', 'label', 'help' => null, 'id' => null, 'accept' => 'image/jpeg,image/png,image/webp', 'aspect' => null, 'current' => null, 'currentName' => null])
{{-- Image (or document) uploader with a cropper, uploading to the Livewire property `name`. `aspect` locks the crop ratio (e.g. 1 for square); without it the ratio is free. `current` / `currentName` show the file already saved. --}}
@php
    $id = $id ?? str_replace('.', '-', $name);
    $hasError = $errors->has($name);
    $ratios = ['free' => __('Free'), '1' => '1:1', '1.3333' => '4:3', '0.75' => '3:4', '1.7778' => '16:9'];
@endphp
<div class="field" x-data="imageUpload({ name: @js($name), aspect: @js($aspect) })" data-failed="{{ __('The upload failed. Try again, or pick a smaller file.') }}">
    <span class="field-label" id="{{ $id }}-label">{{ $label }}</span>
    <input type="file" class="sr-only" id="{{ $id }}" x-ref="input" accept="{{ $accept }}" x-on:change="picked($event)" aria-labelledby="{{ $id }}-label" tabindex="-1">
    <div class="uploader" x-bind:class="{ 'is-dragging': dragging }" x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false" x-on:drop.prevent="dropped($event)">
        <template x-if="! fileName">
            <button type="button" class="uploader-empty" x-on:click="choose()" aria-describedby="{{ $hasError ? $id.'-error' : ($help ? $id.'-help' : '') }}">
                @if($current)
                    <span class="uploader-current"><x-icon name="image" /> {{ $currentName ?? __('Current file') }}</span>
                @endif
                <span class="uploader-emoji" aria-hidden="true">📷</span>
                <span><strong>{{ __('Choose a file') }}</strong> <span class="muted">{{ __('or drop it here') }}</span></span>
            </button>
        </template>
        <template x-if="fileName">
            <div class="uploader-file">
                <template x-if="preview"><img class="uploader-thumb" x-bind:src="preview" alt=""></template>
                <template x-if="! preview"><span class="uploader-thumb uploader-doc" aria-hidden="true">📄</span></template>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold text-heading" x-text="fileName"></p>
                    <p class="muted" x-show="! uploading" x-text="fileSize"></p>
                    <div class="uploader-progress" x-show="uploading" role="progressbar" aria-label="{{ __('Uploading…') }}" x-bind:aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100"><span x-bind:style="`width: ${progress}%`"></span></div>
                </div>
                <div class="btn-group">
                    <x-button variant="ghost" size="sm" icon="pencil" x-on:click="choose()" :label="__('Replace')" />
                    <x-button variant="ghost" size="sm" icon="trash" x-on:click="remove()" :label="__('Remove')" />
                </div>
            </div>
        </template>
    </div>
    <p class="error" x-show="error" x-text="error" x-cloak></p>
    @if($hasError)<p id="{{ $id }}-error" class="error">{{ $errors->first($name) }}</p>@elseif($help)<p id="{{ $id }}-help" class="field-help">{{ $help }}</p>@endif

    <div x-show="editing" x-cloak>
        <div class="drawer-backdrop cropper-backdrop" x-on:click="cancelEdit()"></div>
        <div class="drawer cropper-sheet" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-crop-title" x-trap.inert.noscroll="editing" x-on:keydown.escape.stop="cancelEdit()">
            <div class="drawer-header">
                <div><h2 id="{{ $id }}-crop-title">{{ __('Adjust the image') }}</h2><p class="muted">{{ __('Keep the whole image or crop it, then save.') }}</p></div>
                <button class="drawer-close" type="button" x-on:click="cancelEdit()" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
            </div>
            <div class="drawer-body">
                <div class="segmented" role="group" aria-label="{{ __('Mode') }}">
                    <button type="button" x-bind:aria-pressed="mode === 'whole'" x-on:click="setMode('whole')">{{ __('Whole image') }}</button>
                    <button type="button" x-bind:aria-pressed="mode === 'crop'" x-on:click="setMode('crop')"><x-icon name="crop" width="14" height="14" /> {{ __('Crop') }}</button>
                </div>
                <div class="cropper-stage"><img x-ref="image" alt="{{ __('Image being cropped') }}"></div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap gap-1" x-show="mode === 'crop' && fixedAspect === null" role="group" aria-label="{{ __('Crop ratio') }}">
                        @foreach($ratios as $value => $ratioLabel)
                            <button type="button" class="chip" x-bind:aria-pressed="{{ $value === 'free' ? 'Number.isNaN(ratio)' : 'ratio === '.$value }}" x-on:click="setRatio({{ $value === 'free' ? 'NaN' : $value }})">{{ $ratioLabel }}</button>
                        @endforeach
                    </div>
                    <div class="btn-group ml-auto">
                        <x-button variant="secondary" size="sm" icon="rotate" x-on:click="rotate(-90)" :label="__('Rotate left')" />
                        <x-button variant="secondary" size="sm" icon="rotate" class="-scale-x-100" x-on:click="rotate(90)" :label="__('Rotate right')" />
                        <x-button variant="secondary" size="sm" icon="plus" x-on:click="zoom(0.1)" :label="__('Zoom in')" />
                        <x-button variant="secondary" size="sm" icon="minus" x-on:click="zoom(-0.1)" :label="__('Zoom out')" />
                    </div>
                </div>
            </div>
            <div class="drawer-footer"><x-button icon="check" x-on:click="apply()">{{ __('Use this image') }}</x-button><x-button variant="ghost" x-on:click="cancelEdit()">{{ __('Cancel') }}</x-button></div>
        </div>
    </div>
</div>
