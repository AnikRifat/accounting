<div class="page">
    <x-notices />
    <x-page-header :title="__('Application settings')" :description="__('Manage application configuration. Storage credentials stay in environment configuration.')" />
    <form wire:submit="save" class="stack">
        <x-card :title="__('General')">
            <div class="form-grid">
                <x-form.input name="appName" :label="__('Application name')" wire:model="appName" required maxlength="80" />
                <x-form.input name="supportEmail" :label="__('Support email')" type="email" wire:model="supportEmail" />
            </div>
            <x-form.checkbox name="registrationEnabled" :label="__('Allow public account registration')" wire:model="registrationEnabled" />
        </x-card>
        @can('settings.update')<x-form.actions :submit="__('Save settings')" />@endcan
    </form>
</div>
