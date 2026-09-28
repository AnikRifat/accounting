<div class="page">
    <x-notices />
    <x-page-header :title="$callId ? __('Edit call') : __('Log a call')" :back="route('admin.crm.leads.show', $lead)" :back-label="$lead->displayName()">
        <x-slot:meta><p class="btn-group"><x-badge tone="primary">{{ __('Company: :name', ['name' => $lead->company->name]) }}</x-badge><a class="text-link" href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a><x-badge :tone="$lead->status->tone">{{ $lead->status->name }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack" x-data="{ closed: @js($closedStatusIds) }">
        <x-card :title="__('What happened')">
            <div class="form-grid">
                <x-form.select name="type" :label="__('Type')" :options="$types" wire:model="type" required />
                <x-form.select name="callStatusId" :label="__('Call result')" :options="$callStatuses" wire:model="callStatusId" />
                <x-form.date name="calledOn" :label="__('Date')" wire:model="calledOn" required />
                <x-form.input name="calledTime" :label="__('Time')" type="time" wire:model="calledTime" required />
                <div class="span-full"><x-form.input name="summary" :label="__('Summary')" wire:model="summary" maxlength="1000" autocomplete="off" :placeholder="__('What was discussed, what they asked for…')" /></div>
            </div>
        </x-card>
        <x-card :title="__('Next step')">
            <div class="form-grid">
                <x-form.select name="leadStatusId" :label="__('Lead status')" :options="$leadStatuses" wire:model="leadStatusId" required :help="__('The latest call sets the status of the lead.')" />
                <div x-show="! closed.includes(String($wire.leadStatusId))"><x-form.date name="nextCallOn" :label="__('Next call')" wire:model="nextCallOn" :help="__('Leave empty when no follow-up is needed.')" /></div>
            </div>
        </x-card>
        <x-form.actions :submit="$callId ? __('Save call') : __('Log call')" :cancel="route('admin.crm.leads.show', $lead)" />
    </form>
</div>
