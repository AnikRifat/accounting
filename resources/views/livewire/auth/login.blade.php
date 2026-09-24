<div class="guest-card stack">
    <a class="brand" href="{{ route('login') }}"><span class="brand-mark">S</span> {{ \App\Models\ApplicationSetting::values()['app_name'] }}</a>
    <div class="panel"><p class="eyebrow">Administration</p><h1>Welcome back</h1><p class="muted">Sign in to your workspace.</p>
        <form wire:submit="authenticate" class="stack spacer">
            <x-form.input name="email" label="Email address" type="email" wire:model="email" autocomplete="username" required />
            <x-form.input name="password" label="Password" type="password" wire:model="password" autocomplete="current-password" required />
            <x-form.checkbox name="remember" label="Remember me" wire:model="remember" />
            <button class="btn" type="submit" wire:loading.attr="disabled"><span wire:loading.remove>Sign in</span><span wire:loading>Signing in…</span></button>
        </form>
    </div><p class="muted">Access is managed by your administrator.</p>
</div>
