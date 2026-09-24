<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Organisation') }}</p><h1>{{ $partyId ? __('Edit party') : __('Add party') }}</h1><p class="muted">{{ __('Company: :name', ['name' => $companyName]) }}</p></div></div>
    @error('company')<p class="error mb-4" role="alert">{{ $message }}</p>@enderror
    <form wire:submit="save" class="stack">
        <div class="panel"><h2>{{ __('Party details') }}</h2><div class="form-grid">
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" />
            <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" maxlength="40" autocomplete="off" />
            <x-form.input name="address" :label="__('Address')" wire:model="address" maxlength="255" autocomplete="off" />
            <x-form.input name="notes" :label="__('Notes')" wire:model="notes" maxlength="500" autocomplete="off" />
            <x-form.checkbox name="isActive" :label="__('Party is active')" wire:model="isActive" />
        </div></div>
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save party') }}</button><a class="btn btn-secondary" href="{{ route('admin.parties.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span></div>
    </form>
</div>
