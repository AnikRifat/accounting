@props(['name', 'label', 'help' => null, 'id' => null, 'placeholder' => null, 'clearable' => null])
{{-- Calendar date picker. Bind with wire:model / x-model like a date input; the value is 'YYYY-MM-DD'. --}}
@php
    $id = $id ?? str_replace('.', '-', $name);
    $hasError = $errors->has($name);
    $required = $attributes->has('required');
    $clearable = $clearable ?? ! $required;
    $describedBy = trim(($help && ! $hasError ? $id.'-help ' : '').($hasError ? $id.'-error' : ''));
@endphp
<div class="field">
    <label id="{{ $id }}-label" for="{{ $id }}">{{ $label }}@if($required)<span class="required-mark" aria-hidden="true"> *</span>@endif</label>
    <div class="picker" x-data="datePicker" x-modelable="value" {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }} x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true) }" x-on:click.outside="close()">
        <button id="{{ $id }}" type="button" x-ref="trigger" {{ $attributes->whereDoesntStartWith(['wire:model', 'x-model'])->except('required')->merge(['class' => 'form-control picker-trigger']) }} aria-haspopup="dialog" x-bind:aria-expanded="open" aria-invalid="{{ $hasError ? 'true' : 'false' }}" @if($required) aria-required="true" @endif @if($describedBy) aria-describedby="{{ $describedBy }}" @endif x-on:click="toggle()" x-on:keydown.down.prevent="show()">
            <x-icon name="calendar" /><span x-text="label() || @js($placeholder ?? __('Pick a date'))" x-bind:class="{ 'picker-placeholder': ! value }"></span>
        </button>
        @if($clearable)<button type="button" class="picker-clear" x-show="value" x-cloak x-on:click="value = ''" aria-label="{{ __('Clear :label', ['label' => $label]) }}"><x-icon name="x" width="14" height="14" /></button>@endif
        <div class="picker-panel" role="dialog" aria-labelledby="{{ $id }}-label" x-show="open" x-cloak x-transition:enter="menu-enter" x-transition:enter-start="menu-hidden">
            <x-form.calendar />
            <div class="picker-footer">
                <button type="button" class="text-link" x-on:click="pick(new Date().toLocaleDateString('en-CA'))">{{ __('Today') }}</button>
                @if($clearable)<button type="button" class="picker-link" x-on:click="value = ''; close(true)">{{ __('Clear') }}</button>@endif
            </div>
        </div>
    </div>
    @if($hasError)<p id="{{ $id }}-error" class="error">{{ $errors->first($name) }}</p>@elseif($help)<p id="{{ $id }}-help" class="field-help">{{ $help }}</p>@endif
</div>
