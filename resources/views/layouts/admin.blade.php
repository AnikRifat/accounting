@php
    $appSettings = \App\Models\ApplicationSetting::values();
    $user = auth()->user();
    $navSections = collect([
        '' => [
            'dashboard' => ['dashboard.view', __('Overview'), '🏠'],
        ],
        __('Accounting') => [
            'entries.index' => ['entries.view', __('Transactions'), '💸'],
            'reports.index' => ['reports.view', __('Reports'), '📊'],
            'categories.index' => ['accounts.view', __('Categories'), '🏷️'],
            'payment-methods.index' => ['accounts.view', __('Payment methods'), '💳'],
        ],
        __('Organisation') => [
            'parties.index' => ['parties.view', __('Parties'), '🤝'],
            'companies.index' => ['companies.view', __('Companies'), '🏢'],
            'users.index' => ['users.view', __('Employees'), '👥'],
        ],
        __('Administration') => [
            'roles.index' => ['roles.view', __('Roles & permissions'), '🔐'],
            'media' => ['media.view', __('Media library'), '🖼️'],
            'settings' => ['settings.view', __('Settings'), '⚙️'],
        ],
    ])->map(fn (array $links) => array_filter($links, fn (array $link) => $user->can($link[0])))->filter();
    $initials = collect(preg_split('/\s+/', trim($user->name)))->take(2)->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $appSettings['app_name'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400..700&display=swap">
    @vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles
</head>
<body>
<a class="sr-only focus:not-sr-only" href="#main">{{ __('Skip to content') }}</a>
<div class="shell" x-data="{ nav: false }" x-on:keydown.escape.window="nav = false">
    <div class="sidebar-backdrop" x-show="nav" x-cloak x-transition.opacity x-on:click="nav = false"></div>
    <aside id="sidebar" class="sidebar" x-bind:data-open="nav || null" aria-label="{{ __('Sidebar') }}">
        <div class="sidebar-brand"><a class="brand" href="{{ route('admin.dashboard') }}" wire:navigate><span class="brand-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($appSettings['app_name'], 0, 1)) }}</span><span>{{ $appSettings['app_name'] }}</span></a></div>
        <nav class="sidebar-nav" aria-label="{{ __('Main navigation') }}">
            @foreach($navSections as $section => $links)
                <div class="nav-section" @if($section !== '') role="group" aria-labelledby="nav-section-{{ $loop->index }}" @endif>
                    @if($section !== '')<p class="nav-section-title" id="nav-section-{{ $loop->index }}">{{ $section }}</p>@endif
                    <div class="nav-list">
                        @foreach($links as $key => [$ability, $label, $emoji])
                            <a class="nav-link" href="{{ route('admin.'.$key) }}" @if(request()->routeIs('admin.'.explode('.', $key)[0].'*')) aria-current="page" @endif wire:navigate wire:current.ignore><span class="nav-emoji" aria-hidden="true">{{ $emoji }}</span>{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>
    </aside>
    <div class="shell-main">
        <header class="topbar">
            <div class="topbar-start">
                <x-button variant="ghost" icon="menu" class="menu-toggle" :label="__('Open navigation')" x-on:click="nav = true" aria-controls="sidebar" x-bind:aria-expanded="nav" />
                <livewire:company-switcher />
            </div>
            <div class="topbar-end">
                @can('entries.create')
                    <div class="menu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                        <x-button icon="plus" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open"><span class="quick-add-label">{{ __('New') }}</span></x-button>
                        <div class="menu-panel" role="menu" x-show="open" x-cloak x-transition:enter="menu-enter" x-transition:enter-start="menu-hidden" x-transition:leave="menu-enter" x-transition:leave-end="menu-hidden">
                            <a class="menu-item" role="menuitem" href="{{ route('admin.entries.index', ['sheet' => 'create:income']) }}" wire:navigate><span class="nav-emoji" aria-hidden="true">💰</span>{{ __('Record income') }}</a>
                            <a class="menu-item" role="menuitem" href="{{ route('admin.entries.index', ['sheet' => 'create:expense']) }}" wire:navigate><span class="nav-emoji" aria-hidden="true">🧾</span>{{ __('Record expense') }}</a>
                        </div>
                    </div>
                @endcan
                <div class="menu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                    <button type="button" class="user-trigger" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open" aria-label="{{ __('Account menu for :name', ['name' => $user->name]) }}">
                        <span class="avatar" aria-hidden="true">{{ $initials }}</span><span class="user-trigger-name">{{ $user->name }}</span><x-icon name="chevron-down" width="14" height="14" />
                    </button>
                    <div class="menu-panel" role="menu" x-show="open" x-cloak x-transition:enter="menu-enter" x-transition:enter-start="menu-hidden" x-transition:leave="menu-enter" x-transition:leave-end="menu-hidden">
                        <div class="menu-header"><p class="font-semibold text-heading">{{ $user->name }}</p><p class="muted">{{ $user->email }}</p><p class="mt-2"><x-badge tone="primary">{{ app(\App\Support\Permissions::class)->label($user->role) }}</x-badge></p></div>
                        @can('settings.view')<a class="menu-item" role="menuitem" href="{{ route('admin.settings') }}" wire:navigate><span class="nav-emoji" aria-hidden="true">⚙️</span>{{ __('Settings') }}</a>@endcan
                        <form method="post" action="{{ route('logout') }}">@csrf <button class="menu-item menu-item-danger" role="menuitem" type="submit"><x-icon name="log-out" />{{ __('Sign out') }}</button></form>
                    </div>
                </div>
            </div>
        </header>
        <main id="main" class="content">
            {{ $slot }}
        </main>
        @can('companies.create')<livewire:create-company-drawer />@endcan
    </div>
</div>
@livewireScripts
</body></html>
