@props(['columns', 'id' => 'table-export', 'csv' => null])
{{-- Export & print options for a list using App\Livewire\Concerns\WithTableTools: format, which rows, which columns, orientation. --}}
<div x-data="tableExport(@js(array_map('strval', array_keys($columns))))" x-on:open-table-export.window="show($event.detail?.scope ?? 'all')">
    <x-button variant="secondary" size="sm" icon="download" x-on:click="show('all')" aria-haspopup="dialog" :aria-controls="$id">{{ __('Export') }}</x-button>
    <x-drawer :id="$id" x-model="visible" :title="__('Export & print')" :description="__('Choose the format, the rows and the columns.')">
        <fieldset class="stack-sm"><legend>{{ __('Format') }}</legend>
            <div class="choice-grid">
                <label class="choice"><input type="radio" value="xlsx" x-model="format"><span class="choice-emoji" aria-hidden="true">📗</span><span><strong>{{ __('Excel') }}</strong><span class="muted">{{ __('.xlsx workbook') }}</span></span></label>
                <label class="choice"><input type="radio" value="print" x-model="format"><span class="choice-emoji" aria-hidden="true">🖨️</span><span><strong>{{ __('PDF / Print') }}</strong><span class="muted">{{ __('Print or save as PDF') }}</span></span></label>
                @if($csv)<label class="choice"><input type="radio" value="csv" x-model="format"><span class="choice-emoji" aria-hidden="true">📄</span><span><strong>{{ __('CSV') }}</strong><span class="muted">{{ __('Plain text, every column') }}</span></span></label>@endif
            </div>
        </fieldset>
        <fieldset class="stack-sm" x-show="format !== 'csv'"><legend>{{ __('Rows') }}</legend>
            <label class="check"><input type="radio" value="all" x-model="scope"><span>{{ __('Everything matching the current filters') }}</span></label>
            <label class="check" x-bind:class="{ 'opacity-50': ! $wire.selected.length }"><input type="radio" value="selected" x-model="scope" x-bind:disabled="! $wire.selected.length"><span x-text="@js(__('Only the selected rows')) + ' (' + $wire.selected.length + ')'"></span></label>
        </fieldset>
        <fieldset class="stack-sm" x-show="format !== 'csv'"><legend class="flex w-full items-center justify-between gap-3"><span>{{ __('Columns') }}</span><span class="flex gap-3 text-xs font-semibold"><button type="button" class="text-link" x-on:click="picked = [...keys]">{{ __('All') }}</button><button type="button" class="text-link" x-on:click="picked = []">{{ __('None') }}</button></span></legend>
            <div class="columns-grid">
                @foreach($columns as $key => $label)
                    <label class="check" wire:key="{{ $id }}-column-{{ $key }}"><input type="checkbox" value="{{ $key }}" x-model="picked"><span>{{ $label }}</span></label>
                @endforeach
            </div>
            <p class="error" x-show="! picked.length" x-cloak>{{ __('Pick at least one column.') }}</p>
        </fieldset>
        <fieldset class="stack-sm" x-show="format === 'print'"><legend>{{ __('Page') }}</legend>
            <div class="flex flex-wrap gap-x-6 gap-y-2">
                <label class="check"><input type="radio" value="portrait" x-model="orientation"><span>{{ __('Portrait') }}</span></label>
                <label class="check"><input type="radio" value="landscape" x-model="orientation"><span>{{ __('Landscape') }}</span></label>
            </div>
        </fieldset>
        <x-slot:footer>
            <x-button x-on:click="run({{ \Illuminate\Support\Js::from($csv) }})" x-bind:disabled="busy || (format !== 'csv' && ! picked.length)" x-bind:data-loading="busy || null"><span x-text="format === 'print' ? @js(__('Print')) : @js(__('Download'))"></span></x-button>
            <x-button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-button>
        </x-slot:footer>
    </x-drawer>
</div>
