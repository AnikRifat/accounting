@php($appSettings = \App\Models\ApplicationSetting::values())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('Sign in') }} · {{ $appSettings['app_name'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400..700&display=swap">
    @vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles
</head>
<body>
<div class="guest">
    <aside class="guest-hero" aria-hidden="true">
        <span class="brand"><span class="brand-mark">{{ mb_strtoupper(mb_substr($appSettings['app_name'], 0, 1)) }}</span><span>{{ $appSettings['app_name'] }}</span></span>
        <div><h2>{{ __('Every taka, every company, one ledger.') }}</h2><p>{{ __('Record income, expenses and dues across all your companies, and see where the money stands today.') }}</p></div>
        <p>© {{ now()->year }} {{ $appSettings['app_name'] }}</p>
    </aside>
    <main class="guest-panel">{{ $slot }}</main>
</div>
@livewireScripts
</body></html>
