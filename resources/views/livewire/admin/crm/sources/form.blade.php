<div class="page">
    <x-notices />
    <x-page-header :title="$sourceId ? __('Edit source') : __('Add source')" :back="route('admin.crm.sources.index')" :back-label="__('Sources')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Source details')">
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="100" autocomplete="off" />
            <x-form.checkbox name="isActive" :label="__('Source is active (offered on new leads)')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save source')" :cancel="route('admin.crm.sources.index')" />
    </form>
</div>
