<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Organisation') }}</p><h1>{{ $companyId ? __('Edit company') : __('Add company') }}</h1><p class="muted">{{ __('Companies are never deleted. Deactivate a company you no longer use.') }}</p></div></div>
    <form wire:submit="save" class="stack">
        <div class="panel"><h2>{{ __('Company details') }}</h2><div class="form-grid">
            <x-form.input name="name" :label="__('Company name')" wire:model="name" required maxlength="255" />
            <x-form.input name="code" :label="__('Code')" wire:model="code" required minlength="2" maxlength="10" class="uppercase" :help="__('2–10 letters or digits, used in entry numbers. Must be unique.')" />
            <x-form.input name="address" :label="__('Address')" wire:model="address" maxlength="255" autocomplete="street-address" />
            <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" maxlength="40" autocomplete="tel" />
            <x-form.checkbox name="isActive" :label="__('Company is active')" wire:model="isActive" />
        </div></div>
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save company') }}</button><a class="btn btn-secondary" href="{{ route('admin.companies.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span></div>
    </form>
</div>
