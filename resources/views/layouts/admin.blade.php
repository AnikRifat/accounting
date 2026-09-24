@php($appSettings = \App\Models\ApplicationSetting::values())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $appSettings['app_name'] }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles
</head>
<body>
<a class="sr-only focus:not-sr-only" href="#main">{{ __('Skip to content') }}</a>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('admin.dashboard') }}"><span class="brand-mark">F</span> {{ $appSettings['app_name'] }}</a>
        <nav class="nav" aria-label="{{ __('Main navigation') }}">
            @foreach([
                'dashboard' => ['dashboard.view', __('Overview')],
                'entries.index' => ['entries.view', __('Transactions')],
                'reports.index' => ['reports.view', __('Reports')],
                'parties.index' => ['parties.view', __('Parties')],
                'categories.index' => ['accounts.view', __('Categories')],
                'payment-methods.index' => ['accounts.view', __('Payment methods')],
                'companies.index' => ['companies.view', __('Companies')],
                'users.index' => ['users.view', __('Employees')],
                'roles.index' => ['roles.view', __('Roles & permissions')],
                'media' => ['media.view', __('Media library')],
                'settings' => ['settings.view', __('Settings')],
            ] as $key => [$ability, $label])
                @can($ability)<a href="{{ route('admin.'.$key) }}" @if(request()->routeIs('admin.'.explode('.', $key)[0].'*')) aria-current="page" @endif wire:navigate>{{ $label }}</a>@endcan
            @endforeach
        </nav>
    </aside>
    <div>
        <header class="topbar"><livewire:company-switcher /><div class="flex items-center gap-4"><span class="text-sm font-semibold">{{ auth()->user()->name }}</span><form method="post" action="{{ route('logout') }}">@csrf <button class="btn btn-secondary" type="submit">{{ __('Sign out') }}</button></form></div></header>
        <main id="main" class="content">
            {{ $slot }}
        </main>
        @can('companies.create')<livewire:create-company-drawer />@endcan
    </div>
</div>
@livewireScripts
</body></html>
