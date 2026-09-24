@props(['label', 'size' => 'md'])
{{-- Off-canvas form sheet for a list page using App\Livewire\Concerns\WithFormSheet. Its slot is a form component: the form's own page header becomes the sheet header and its save bar sticks to the bottom. --}}
<div x-data="{ open: $wire.sheet !== '' }" x-effect="open = $wire.sheet !== ''" x-init="$watch('open', value => { if (! value) setTimeout(() => { if (! open && $wire.sheet !== '') $wire.closeSheet() }, 220) })" x-on:close-sheet="open = false">
    <div class="drawer-backdrop" x-show="open" x-cloak x-transition.opacity x-on:click="open = false"></div>
    <div {{ $attributes->class(['drawer sheet', 'sheet-'.$size]) }} role="dialog" aria-modal="true" aria-label="{{ $label }}" x-show="open" x-cloak x-trap.inert.noscroll="open" x-on:keydown.escape.stop="open = false" x-transition:enter="drawer-enter" x-transition:enter-start="drawer-hidden" x-transition:leave="drawer-leave" x-transition:leave-end="drawer-hidden">
        <button class="drawer-close sheet-close" type="button" x-on:click="open = false" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
        <div class="sheet-body">
            {{ $slot }}
            <div class="sheet-loading" wire:loading.flex wire:target="openSheet"><span class="spinner" aria-hidden="true"></span><span class="sr-only">{{ __('Loading…') }}</span></div>
        </div>
    </div>
</div>
