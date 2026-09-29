<div class="page">
    <x-notices />
    <x-page-header :title="$itemId ? __('Edit item') : __('Add item')" :back="route('admin.sales.items.index')" :back-label="__('Items')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Item details')">
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" autocomplete="off" :placeholder="__('Website design, Rice 25 kg bag…')" />
            <x-form.select name="categoryId" :label="__('Category')" wire:model="categoryId" :options="$itemCategories" :help="__('Groups items in the catalogue. Manage the list under Item categories.')" />
            <x-form.input name="description" :label="__('Description')" wire:model="description" maxlength="500" autocomplete="off" :help="__('Copied onto the invoice line; you can change it there.')" />
            <div class="form-grid">
                <x-form.input name="unit" :label="__('Unit')" wire:model="unit" maxlength="20" autocomplete="off" :placeholder="__('pcs, hour, kg…')" />
                <x-form.input name="price" :label="__('Price (৳)')" wire:model="price" required inputmode="decimal" autocomplete="off" placeholder="0.00" />
                <x-form.input name="taxRate" :label="__('VAT %')" wire:model="taxRate" required inputmode="decimal" autocomplete="off" :help="__('0 when the item carries no VAT. The standard rate in Bangladesh is 15%.')" />
            </div>
            <x-form.select name="accountId" :label="__('Income category')" wire:model="accountId" :options="$categories" :help="__('Where a sale of this item is posted when an invoice goes to the books.')" />
            <x-form.checkbox name="isActive" :label="__('Item is active (offered on new documents)')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save item')" :cancel="route('admin.sales.items.index')" />
    </form>
</div>
