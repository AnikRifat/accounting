{{-- Appears while rows are ticked: how many, and what to do with them. Extra actions go in the slot. --}}
<div class="bulk-bar no-print" x-show="$wire.selected.length" x-cloak x-transition:enter="toast-enter" x-transition:enter-start="toast-hidden" role="region" aria-label="{{ __('Selected rows') }}">
    <span class="bulk-count" x-text="$wire.selected.length"></span><span class="font-semibold">{{ __('selected') }}</span>
    <div class="btn-group ml-auto">
        {{ $slot }}
        <x-button size="sm" variant="secondary" icon="download" x-on:click="$dispatch('open-table-export', { scope: 'selected' })">{{ __('Export or print') }}</x-button>
        <x-button size="sm" variant="ghost" icon="x" x-on:click="$wire.selected = []">{{ __('Clear') }}</x-button>
    </div>
</div>
