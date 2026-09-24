@props(['id', 'title', 'description' => null, 'submit' => null])
{{-- Off-canvas sheet (bottom sheet on phones). Bind its open state with wire:model (or x-model); focus moves to the first [autofocus] field. With `submit`, the body and footer form one Livewire form. --}}
@php($tag = $submit ? 'form' : 'div')
<div x-data="{ open: false }" x-modelable="open" {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }}>
    <div class="drawer-backdrop" x-show="open" x-cloak x-transition.opacity x-on:click="open = false"></div>
    <div id="{{ $id }}" class="drawer" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" @if($description) aria-describedby="{{ $id }}-description" @endif x-show="open" x-cloak x-trap.inert.noscroll="open" x-on:keydown.escape.stop="open = false" x-transition:enter="drawer-enter" x-transition:enter-start="drawer-hidden" x-transition:leave="drawer-leave" x-transition:leave-end="drawer-hidden">
        <div class="drawer-header">
            <div><h2 id="{{ $id }}-title">{{ $title }}</h2>@if($description)<p id="{{ $id }}-description" class="muted">{{ $description }}</p>@endif</div>
            <button class="drawer-close" type="button" x-on:click="open = false" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
        </div>
        <{{ $tag }} class="drawer-content" @if($submit) wire:submit="{{ $submit }}" @endif>
            <div class="drawer-body">{{ $slot }}</div>
            @isset($footer)<div class="drawer-footer">{{ $footer }}</div>@endisset
        </{{ $tag }}>
    </div>
</div>
