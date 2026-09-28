@props(['seesAll', 'people', 'personLabel'])
{{-- Filter bar of a CRM report (App\Livewire\Concerns\WithCrmReportFilters): person and date range. --}}
<x-toolbar>
    @if($seesAll)<x-form.select name="person" :label="$personLabel" wire:model.live="person" :options="['' => __('Everyone')] + $people" />@endif
    <x-form.date-range id="report-range" :label="__('Date range')" :placeholder="__('All time')" />
</x-toolbar>
