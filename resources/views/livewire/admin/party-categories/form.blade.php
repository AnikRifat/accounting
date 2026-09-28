<div class="page">
    <x-notices />
    <x-page-header :title="$categoryId ? __('Edit party category') : __('Add party category')" :back="route('admin.party-categories.index')" :back-label="__('Party categories')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Category details')">
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="100" autocomplete="off" :placeholder="__('Customer, Supplier, Landlord…')" />
            <x-form.checkbox name="isActive" :label="__('Category is active (offered on the party form)')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save category')" :cancel="route('admin.party-categories.index')" />
    </form>
</div>
