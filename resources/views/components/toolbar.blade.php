{{-- Filter bar at the top of a list card. Default slot holds the filter fields; `actions` holds clear/export. --}}
<div {{ $attributes->class(['toolbar no-print']) }}>
    <div class="toolbar-filters">{{ $slot }}</div>
    @isset($actions)<div class="toolbar-actions">{{ $actions }}</div>@endisset
</div>
