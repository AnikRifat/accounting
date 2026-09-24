<div>
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ __('Reports') }}</h1><p class="muted">{{ __('Figures include posted entries only; voided entries never count.') }}</p></div></div>
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach($reports as $route => [$ability, $title, $description])
            <a class="panel report-card" href="{{ route('admin.'.$route) }}" wire:key="report-{{ $route }}" wire:navigate><h2>{{ $title }}</h2><p class="muted">{{ $description }}</p></a>
        @endforeach
    </div>
    @if($advanced !== [])
        <h2 class="spacer">{{ __('Advanced') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach($advanced as $route => [$ability, $title, $description])
                <a class="panel report-card" href="{{ route('admin.'.$route) }}" wire:key="advanced-{{ $route }}" wire:navigate><h2>{{ $title }}</h2><p class="muted">{{ $description }}</p></a>
            @endforeach
        </div>
    @endif
</div>
