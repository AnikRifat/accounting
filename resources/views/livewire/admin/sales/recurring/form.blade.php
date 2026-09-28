<div class="page">
    <x-notices />
    <x-page-header :title="$scheduleId ? __('Edit recurring invoice') : __('Add recurring invoice')" :back="route('admin.sales.recurring.index')" :back-label="__('Recurring invoices')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Schedule')" :description="__('On every run date a copy of the invoice is created as a draft, dated that day, for you to check and issue.')">
            <x-form.select name="sourceId" :label="__('Invoice to copy')" wire:model.live="sourceId" :options="$sourceOptions" required :placeholder="__('Choose an invoice')" :help="__('Its customer, lines, discount, VAT, notes and terms are copied each time.')" />
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="100" autocomplete="off" :placeholder="__('Monthly retainer, Office rent…')" />
            <div class="form-grid">
                <x-form.select name="frequency" :label="__('Repeats')" wire:model.live="frequency" :options="$frequencyOptions" required />
                @if($frequency !== 'weekly')
                    <x-form.input name="day" type="number" min="1" max="31" :label="__('Day of the month')" wire:model="day" required :help="__('31 means the last day in shorter months.')" />
                @endif
                <x-form.date name="startsOn" :label="__('Starts on')" wire:model="startsOn" required :help="$frequency === 'weekly' ? __('Repeats every 7 days from this date.') : null" />
                <x-form.date name="endsOn" :label="__('Ends on')" wire:model="endsOn" :help="__('Leave empty to repeat until you switch it off.')" />
            </div>
            <x-form.checkbox name="isActive" :label="__('Schedule is active')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save schedule')" :cancel="route('admin.sales.recurring.index')" />
    </form>
</div>
