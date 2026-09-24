<div x-on:open-create-company.window="$wire.open = true">
    <x-drawer id="create-company" wire:model="open" submit="save" :title="__('New company')" :description="__('Creates the company with the default chart of accounts, categories and payment methods, then switches the header to it.')">
        <x-form.input name="name" :label="__('Company name')" wire:model="name" required maxlength="255" autocomplete="organization" autofocus />
        <x-form.input name="code" :label="__('Short code')" wire:model="code" required maxlength="10" autocomplete="off" :help="__('2–10 letters or digits, used in entry numbers (e.g. MTL-000001).')" />
        <x-form.input name="address" :label="__('Address')" wire:model="address" maxlength="255" autocomplete="street-address" />
        <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" maxlength="40" autocomplete="tel" />
        <x-form.checkbox name="isActive" :label="__('Active')" wire:model="isActive" />
        <x-slot:footer><button class="btn" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Create company') }}</button><button class="btn btn-secondary" type="button" x-on:click="open = false">{{ __('Cancel') }}</button></x-slot:footer>
    </x-drawer>
</div>
