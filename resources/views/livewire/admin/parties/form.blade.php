<div class="page">
    <x-notices />
    <x-page-header :title="$partyId ? __('Edit party') : __('Add party')" :back="route('admin.parties.index')" :back-label="__('Parties')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Party details')">
            <div class="form-grid">
                <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" />
                <x-form.select name="categoryId" :label="__('Category')" :options="$categories" wire:model="categoryId" :help="__('Manage the list under Party categories.')" />
                <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" maxlength="40" autocomplete="off" />
                <x-form.input name="email" :label="__('Email')" type="email" wire:model="email" maxlength="150" autocomplete="off" :help="__('Optional. Invoices are emailed here.')" />
                <x-form.input name="address" :label="__('Address')" wire:model="address" maxlength="255" autocomplete="off" />
                <x-form.input name="notes" :label="__('Notes')" wire:model="notes" maxlength="500" autocomplete="off" />
                <x-form.photo :current="$currentPhoto" :name="$name" :pending="(bool) $photo" />
            </div>
            <x-form.checkbox name="isActive" :label="__('Party is active')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save party')" :cancel="route('admin.parties.index')" />
    </form>
</div>
