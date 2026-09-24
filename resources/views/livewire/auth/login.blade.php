<div class="guest-card">
    <a class="brand" href="{{ route('login') }}"><span class="brand-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr(\App\Models\ApplicationSetting::values()['app_name'], 0, 1)) }}</span><span>{{ \App\Models\ApplicationSetting::values()['app_name'] }}</span></a>
    <div class="stack-sm"><h1>{{ __('Welcome back') }}</h1><p class="page-description">{{ __('Sign in to your workspace.') }}</p></div>
    <form wire:submit="authenticate" class="stack">
        <x-form.input name="email" :label="__('Email address')" type="email" wire:model="email" autocomplete="username" required autofocus />
        <x-form.input name="password" :label="__('Password')" type="password" wire:model="password" autocomplete="current-password" required />
        <x-form.checkbox name="remember" :label="__('Remember me')" wire:model="remember" />
        <x-button type="submit" class="w-full">{{ __('Sign in') }}</x-button>
    </form>
    <p class="muted">{{ __('Access is managed by your administrator.') }}</p>
</div>
