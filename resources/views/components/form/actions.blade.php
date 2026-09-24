@props(['submit', 'cancel' => null])
{{-- Sticky save bar at the bottom of a form page or sheet. Extra buttons go in the default slot, between save and cancel. Inside a sheet, Cancel closes it instead of leaving the page. --}}
<div class="form-footer">
    <x-button type="submit" wire:loading.attr="disabled">{{ $submit }}</x-button>
    {{ $slot }}
    @if($cancel)<x-button variant="ghost" :href="$cancel" :navigate="false" x-on:click.prevent="$el.closest('.sheet') ? $dispatch('close-sheet') : Livewire.navigate($el.href)">{{ __('Cancel') }}</x-button>@endif
</div>
