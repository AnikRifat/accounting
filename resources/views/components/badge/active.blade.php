@props(['active', 'on' => null, 'off' => null])
<x-badge :tone="$active ? 'success' : 'neutral'" dot>{{ $active ? ($on ?? __('Active')) : ($off ?? __('Inactive')) }}</x-badge>
