@props(['title', 'description' => null, 'back' => null, 'backLabel' => null])
{{-- Top of every page: optional back link, title, one-line description and the page's primary actions. --}}
<header class="page-header">
    <div class="page-header-text">
        @if($back)<a class="page-back" href="{{ $back }}" wire:navigate><x-icon name="chevron-left" width="14" height="14" />{{ $backLabel ?? __('Back') }}</a>@endif
        <h1>{{ $title }}</h1>
        @if($description)<p class="page-description">{{ $description }}</p>@endif
        {{ $meta ?? '' }}
    </div>
    @isset($actions)<div class="page-actions">{{ $actions }}</div>@endisset
</header>
