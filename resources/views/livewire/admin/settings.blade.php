<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">Configuration</p><h1>Application settings</h1><p class="muted">Manage public application configuration. Storage credentials stay in environment configuration.</p></div></div>
    <form wire:submit="save"><div class="panel stack">
        <x-form.input name="appName" label="Application name" wire:model="appName" required maxlength="80" />
        <x-form.input name="supportEmail" label="Support email" type="email" wire:model="supportEmail" />
        <x-form.checkbox name="registrationEnabled" label="Allow public account registration" wire:model="registrationEnabled" />
        @can('settings.update')<div><button class="btn" type="submit" wire:loading.attr="disabled">Save settings</button><span class="muted ml-3" wire:loading>Saving…</span></div>@endcan
    </div></form>
</div>
