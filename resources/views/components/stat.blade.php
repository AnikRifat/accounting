@props(['label', 'value' => null, 'hint' => null, 'emoji' => null, 'tone' => null, 'href' => null])
{{-- KPI tile. With `href` the whole tile drills down to the filtered list. --}}
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['card stat', 'stat-'.$tone => $tone]) }}>
    <div class="stat-head"><span>{{ $label }}</span>@if($emoji)<span class="stat-icon" aria-hidden="true">{{ $emoji }}</span>@endif</div>
    <p class="stat-value">{{ $value }}</p>
    @if($hint)<p class="stat-hint">{{ $hint }}</p>@endif
</{{ $tag }}>
