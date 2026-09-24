@props(['variant' => 'primary', 'size' => null, 'href' => null, 'icon' => null, 'type' => 'button', 'navigate' => true, 'label' => null])
{{-- The one button. With `href` it renders a link (wire:navigate unless navigate=false). Icon-only buttons pass `label` for their accessible name. --}}
@php
    $classes = collect(['btn', $variant === 'primary' ? null : 'btn-'.$variant, $size ? 'btn-'.$size : null, $label !== null && $slot->isEmpty() ? 'btn-icon' : null])->filter()->join(' ');
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }} @if($navigate) wire:navigate @endif @if($label) aria-label="{{ $label }}" title="{{ $label }}" @endif>@if($icon)<x-icon :name="$icon" />@endif @if($slot->isNotEmpty())<span>{{ $slot }}</span>@endif</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }} @if($label) aria-label="{{ $label }}" title="{{ $label }}" @endif>@if($icon)<x-icon :name="$icon" />@endif @if($slot->isNotEmpty())<span>{{ $slot }}</span>@endif</button>
@endif
