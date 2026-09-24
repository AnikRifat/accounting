@php($appSettings = \App\Models\ApplicationSetting::values())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $appSettings['app_name'] }} · Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles
</head>
<body>
<a class="sr-only focus:not-sr-only" href="#main">Skip to content</a>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('admin.dashboard') }}"><span class="brand-mark">S</span> {{ $appSettings['app_name'] }}</a>
        <nav class="nav" aria-label="Administration">
            @foreach(['dashboard' => ['dashboard.view', 'Overview'], 'users.index' => ['users.view', 'Users'], 'employees.index' => ['employees.view', 'Employees'], 'roles.index' => ['roles.view', 'Roles & permissions'], 'media' => ['media.view', 'Media library'], 'settings' => ['settings.view', 'Settings']] as $key => [$ability, $label])
                @can($ability)<a href="{{ route('admin.'.$key) }}" @if(request()->routeIs('admin.'.explode('.', $key)[0].'*')) aria-current="page" @endif wire:navigate>{{ $label }}</a>@endcan
            @endforeach
        </nav>
    </aside>
    <div>
        <header class="topbar"><span class="muted">Administration workspace</span><div class="flex items-center gap-4"><span class="text-sm font-semibold">{{ auth()->user()->name }}</span><form method="post" action="{{ route('logout') }}">@csrf <button class="btn btn-secondary" type="submit">Sign out</button></form></div></header>
        <main id="main" class="content">
            {{ $slot }}
        </main>
    </div>
</div>
@livewireScripts
</body></html>
