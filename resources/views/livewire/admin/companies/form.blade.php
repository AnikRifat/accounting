<div class="page">
    <x-notices />
    <x-page-header :title="$companyId ? __('Edit company') : __('Add company')" :description="__('Companies are never deleted. Deactivate a company you no longer use.')" :back="route('admin.companies.index')" :back-label="__('Companies')" />
    <form wire:submit="save" class="stack">
        <x-card :title="__('Company details')">
            <div class="form-grid">
                <x-form.input name="name" :label="__('Company name')" wire:model="name" required maxlength="255" />
                <x-form.input name="code" :label="__('Code')" wire:model="code" required minlength="2" maxlength="10" class="uppercase" :help="__('2–10 letters or digits, used in entry numbers. Must be unique.')" />
                <x-form.input name="address" :label="__('Address')" wire:model="address" maxlength="255" autocomplete="street-address" />
                <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" maxlength="40" autocomplete="tel" />
            </div>
            <x-form.checkbox name="isActive" :label="__('Company is active')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save company')" :cancel="route('admin.companies.index')" />
    </form>
</div>
