@props(['ids'])
{{-- Header checkbox: ticks or clears every row on this page, keeping ticks on other pages. --}}
<th class="select-col" x-data="{ ids: @js(array_map('strval', $ids)) }">
    <input type="checkbox" class="table-check" aria-label="{{ __('Select every row on this page') }}"
        x-bind:checked="ids.length > 0 && ids.every(id => $wire.selected.includes(id))"
        x-bind:indeterminate="ids.some(id => $wire.selected.includes(id)) && ! ids.every(id => $wire.selected.includes(id))"
        x-on:change="$wire.selected = $event.target.checked ? [...new Set([...$wire.selected, ...ids])] : $wire.selected.filter(id => ! ids.includes(id))">
</th>
