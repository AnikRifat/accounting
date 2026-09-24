<div class="company-switcher">
    @if($companies->count() === 1)
        <span class="company-switcher-single"><span class="muted">{{ __('Company') }}</span> <strong>{{ $companies->first()->name }}</strong></span>
    @elseif($companies->count() > 1)
        <x-form.select name="selected" id="company-switcher" :label="__('Company')" :options="$options" wire:model.live="selected" />
    @endif
    @can('companies.create')<button type="button" class="btn btn-secondary company-switcher-add" x-on:click="$dispatch('open-create-company')" aria-haspopup="dialog" aria-controls="create-company">+ {{ __('New company') }}</button>@endcan
</div>
