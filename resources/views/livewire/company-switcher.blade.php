<div class="company-switcher">
    @if($companies->count() === 1)
        <span class="company-switcher-single" title="{{ __('Company') }}"><span class="nav-emoji" aria-hidden="true">🏢</span><span><span class="sr-only">{{ __('Company') }}: </span>{{ $companies->first()->name }}</span></span>
    @elseif($companies->count() > 1)
        <x-form.select name="selected" id="company-switcher" :label="__('Company')" :options="$options" wire:model.live="selected" />
    @endif
    @can('companies.create')<x-button variant="secondary" icon="plus" x-on:click="$dispatch('open-create-company')" aria-haspopup="dialog" aria-controls="create-company" :title="__('New company')"><span class="company-switcher-add-label">{{ __('New company') }}</span></x-button>@endcan
</div>
