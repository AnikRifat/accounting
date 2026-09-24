@props(['name', 'label', 'options' => [], 'help' => null, 'id' => null, 'placeholder' => null])
{{-- Searchable single select (Alpine `searchSelect` in app.js). Bind with wire:model / x-model like a native select. --}}
@php
    $id = $id ?? str_replace('.', '-', $name);
    $hasError = $errors->has($name);
    $describedBy = trim(($help && ! $hasError ? $id.'-help ' : '').($hasError ? $id.'-error' : ''));
@endphp
<div class="field">
    <label id="{{ $id }}-label" for="{{ $id }}">{{ $label }}@if($attributes->has('required'))<span class="required-mark" aria-hidden="true"> *</span>@endif</label>
    <div class="search-select" x-data="searchSelect" x-modelable="value" {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }} x-on:focusout="closeOnFocusOut($event)" x-on:keydown.escape="close(true)">
        <button id="{{ $id }}" type="button" x-ref="trigger" {{ $attributes->whereDoesntStartWith(['wire:model', 'x-model'])->except('required')->merge(['class' => 'form-control search-select-trigger']) }} aria-haspopup="listbox" x-bind:aria-expanded="open" aria-controls="{{ $id }}-listbox" aria-labelledby="{{ $id }}-label {{ $id }}" aria-invalid="{{ $hasError ? 'true' : 'false' }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif x-on:mousedown.prevent x-on:click="toggle()" x-on:keydown.down.prevent="show()" x-on:keydown.up.prevent="show()">
            <span x-text="selectedLabel()"></span>
        </button>
        <div class="search-select-panel" x-show="open" x-cloak x-transition:enter="menu-enter" x-transition:enter-start="menu-hidden">
            <input type="search" class="form-control" x-ref="search" x-model="query" role="combobox" aria-autocomplete="list" aria-controls="{{ $id }}-listbox" x-bind:aria-expanded="open" x-bind:aria-activedescendant="activeId()" aria-label="{{ __('Search :label', ['label' => $label]) }}" placeholder="{{ __('Search…') }}" autocomplete="off" x-on:input="activateFirst()" x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)" x-on:keydown.enter.prevent="chooseActive()">
            <ul id="{{ $id }}-listbox" class="search-select-list" role="listbox" aria-labelledby="{{ $id }}-label" x-ref="list" data-placeholder="{{ $placeholder ?? __('Select an option') }}" x-on:mousedown.prevent>
                @foreach($options as $value => $text)
                    <li id="{{ $id }}-option-{{ $loop->index }}" role="option" data-value="{{ $value }}" x-show="matches($el)" x-bind:aria-selected="isSelected($el)" x-bind:class="{ 'is-active': isActive($el) }" x-on:click="choose($el)" x-on:mousemove="activate($el)">{{ $text }}</li>
                @endforeach
            </ul>
            <p class="muted search-select-empty" x-show="! hasMatches()">{{ __('No matches found.') }}</p>
        </div>
    </div>
    @if($hasError)<p id="{{ $id }}-error" class="error">{{ $errors->first($name) }}</p>@elseif($help)<p id="{{ $id }}-help" class="field-help">{{ $help }}</p>@endif
</div>
