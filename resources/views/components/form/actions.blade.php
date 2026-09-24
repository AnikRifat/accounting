@props(['submit', 'cancel' => null])
{{-- Sticky save bar at the bottom of a page form. Extra buttons go in the default slot, between save and cancel. --}}
<div class="form-footer">
    <x-button type="submit" wire:loading.attr="disabled">{{ $submit }}</x-button>
    {{ $slot }}
    @if($cancel)<x-button variant="ghost" :href="$cancel">{{ __('Cancel') }}</x-button>@endif
</div>
