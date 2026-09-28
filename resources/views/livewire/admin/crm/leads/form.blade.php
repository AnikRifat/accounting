<div class="page">
    <x-notices />
    <x-page-header :title="$leadId ? __('Edit lead') : __('Add lead')" :back="route('admin.crm.leads.index')" :back-label="__('Leads')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Contact')">
            <div class="form-grid">
                <x-form.input name="name" :label="__('Name')" wire:model="name" maxlength="150" autocomplete="off" :help="__('Leave empty if you only have a number.')" />
                <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" required maxlength="25" autocomplete="off" />
                <x-form.input name="email" :label="__('Email')" type="email" wire:model="email" maxlength="150" autocomplete="off" />
                <x-form.input name="organization" :label="__('Organisation')" wire:model="organization" maxlength="150" autocomplete="off" />
                <x-form.input name="address" :label="__('Address')" wire:model="address" maxlength="255" autocomplete="off" />
                <x-form.photo :current="$currentPhoto" :name="$name" :pending="(bool) $photo" />
                <x-form.select name="sourceId" :label="__('Source')" :options="$sources" wire:model="sourceId" :help="__('Manage the list under CRM setup, Sources.')" />
            </div>
        </x-card>
        <x-card :title="__('Pipeline')">
            <div class="form-grid">
                <x-form.select name="statusId" :label="__('Status')" :options="$statuses" wire:model="statusId" required />
                <x-form.select name="serviceId" :label="__('Service')" :options="$services" wire:model="serviceId" />
                @if($canAssign)<x-form.select name="assignedTo" :label="__('Assigned to')" :options="$assignees" wire:model="assignedTo" />@endif
                <x-form.date name="nextCallOn" :label="__('Next call')" wire:model="nextCallOn" :help="__('Ignored while the status is closed.')" />
                <div class="span-full"><x-form.input name="notes" :label="__('Notes')" wire:model="notes" maxlength="1000" autocomplete="off" /></div>
            </div>
        </x-card>
        <x-form.actions :submit="__('Save lead')" :cancel="route('admin.crm.leads.index')" />
    </form>
</div>
