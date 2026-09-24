@props(['active' => 0, 'id' => 'list-filters'])
{{-- Top of a list card. Default slot: inline fields (search). `filters`: the rest, in an off-canvas drawer behind a Filters button with the active count. `clear`: the drawer's reset button. `actions`: export and friends. --}}
<div {{ $attributes->class(['toolbar no-print']) }}>
    <div class="toolbar-main">
        @if($slot->isNotEmpty())<div class="toolbar-filters">{{ $slot }}</div>@endif
        <div class="toolbar-actions">
            @isset($filters)
                <div x-data="{ filtersOpen: false }">
                    <x-button variant="secondary" size="sm" icon="sliders" x-on:click="filtersOpen = true" aria-haspopup="dialog" :aria-controls="$id">{{ __('Filters') }}@if($active > 0) <span class="filter-count">{{ $active }}</span>@endif</x-button>
                    <x-drawer :id="$id" x-model="filtersOpen" :title="__('Filters')" :description="__('The list updates as you change them.')">
                        {{ $filters }}
                        <x-slot:footer><x-button x-on:click="open = false">{{ __('Show results') }}</x-button>{{ $clear ?? '' }}</x-slot:footer>
                    </x-drawer>
                </div>
            @endisset
            {{ $actions ?? '' }}
        </div>
    </div>
</div>
