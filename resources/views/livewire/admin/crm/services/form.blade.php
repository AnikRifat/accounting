<div class="page">
    <x-notices />
    <x-page-header :title="$serviceId ? __('Edit service') : __('Add service')" :back="route('admin.crm.services.index')" :back-label="__('Services')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Service details')">
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="100" autocomplete="off" />
            <x-form.checkbox name="isActive" :label="__('Service is active (offered on new leads)')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save service')" :cancel="route('admin.crm.services.index')" />
    </form>
</div>
