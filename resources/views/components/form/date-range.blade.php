@props(['from' => 'from', 'to' => 'to', 'label' => null, 'id' => 'date-range', 'placeholder' => null])
{{-- Date range picker with presets, bound live to two Livewire properties (default `from` and `to`). --}}
@php
    $label = $label ?? __('Date range');
    $presetLabels = ['today' => __('Today'), 'yesterday' => __('Yesterday'), 'last7' => __('Last 7 days'), 'last30' => __('Last 30 days'), 'thisMonth' => __('This month'), 'lastMonth' => __('Last month'), 'thisYear' => __('This year')];
    $error = $errors->first($from) ?: $errors->first($to);
@endphp
<div class="field">
    <label id="{{ $id }}-label" for="{{ $id }}">{{ $label }}</label>
    <div class="picker" x-data="dateRange({ from: $wire.entangle('{{ $from }}').live, to: $wire.entangle('{{ $to }}').live })" x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true) }" x-on:click.outside="close()">
        <button id="{{ $id }}" type="button" x-ref="trigger" class="form-control picker-trigger" aria-haspopup="dialog" x-bind:aria-expanded="open" aria-invalid="{{ $error ? 'true' : 'false' }}" @if($error) aria-describedby="{{ $id }}-error" @endif x-on:click="toggle()" x-on:keydown.down.prevent="show()">
            <x-icon name="calendar" /><span x-text="label() || @js($placeholder ?? __('Any date'))" x-bind:class="{ 'picker-placeholder': ! from && ! to }"></span>
        </button>
        <button type="button" class="picker-clear" x-show="from || to" x-cloak x-on:click="set('', '')" aria-label="{{ __('Clear :label', ['label' => $label]) }}"><x-icon name="x" width="14" height="14" /></button>
        <div class="picker-panel picker-panel-range" role="dialog" aria-labelledby="{{ $id }}-label" x-show="open" x-cloak x-transition:enter="menu-enter" x-transition:enter-start="menu-hidden">
            <div class="picker-presets" role="group" aria-label="{{ __('Quick ranges') }}">
                <template x-for="preset in presets()" :key="preset[0]">
                    <button type="button" class="picker-preset" x-bind:class="{ 'is-active': isPreset(preset) }" x-bind:aria-pressed="isPreset(preset)" x-text="@js($presetLabels)[preset[0]]" x-on:click="applyPreset(preset)"></button>
                </template>
                <button type="button" class="picker-preset" x-on:click="clear()">{{ __('Any date') }}</button>
            </div>
            <div>
                <x-form.calendar />
                <p class="picker-hint" x-text="anchor ? @js(__('Now pick the end date.')) : @js(__('Pick the start date, then the end date.'))"></p>
            </div>
        </div>
    </div>
    @if($error)<p id="{{ $id }}-error" class="error">{{ $error }}</p>@endif
</div>
