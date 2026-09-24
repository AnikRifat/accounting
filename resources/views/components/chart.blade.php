@props([
    'type' => 'bar',
    'data' => ['labels' => [], 'datasets' => []],
    'options' => [],
    'height' => 260,
    'isMoney' => true,
    'legend' => null,
    'legendPosition' => null,
    'horizontal' => false,
])

@php
    $chartKey = 'chart-'.substr(md5(json_encode([$type, $data, $options, $horizontal])), 0, 10);
@endphp

<div wire:key="{{ $chartKey }}" {{ $attributes->class(['chart-container relative w-full']) }} style="height: {{ is_numeric($height) ? $height.'px' : $height }}" wire:ignore
     x-data="chart({
        type: '{{ $type }}',
        data: {{ Js::from($data) }},
        options: {{ Js::from($options) }},
        isMoney: {{ $isMoney ? 'true' : 'false' }},
        legend: {{ $legend !== null ? ($legend ? 'true' : 'false') : 'null' }},
        legendPosition: '{{ $legendPosition ?? ($type === 'doughnut' ? 'bottom' : 'top') }}',
        horizontal: {{ $horizontal ? 'true' : 'false' }}
     })">
    <canvas x-ref="canvas"></canvas>
</div>
