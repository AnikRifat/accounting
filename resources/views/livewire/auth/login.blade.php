<div class="guest-card stack">
    <a class="brand" href="{{ route('login') }}"><span class="brand-mark">F</span> {{ \App\Models\ApplicationSetting::values()['app_name'] }}</a>
    <div class="panel"><p class="eyebrow">{{ __('Administration') }}</p><h1>{{ __('Welcome back') }}</h1><p class="muted">{{ __('Sign in to your workspace.') }}</p>
        <form wire:submit="authenticate" class="stack spacer">
            <x-form.input name="email" :label="__('Email address')" type="email" wire:model="email" autocomplete="username" required />
            <x-form.input name="password" :label="__('Password')" type="password" wire:model="password" autocomplete="current-password" required />
            <x-form.checkbox name="remember" :label="__('Remember me')" wire:model="remember" />
            <button class="btn" type="submit" wire:loading.attr="disabled"><span wire:loading.remove>{{ __('Sign in') }}</span><span wire:loading>{{ __('Signing in…') }}</span></button>
        </form>
    </div><p class="muted">{{ __('Access is managed by your administrator.') }}</p>
</div>
