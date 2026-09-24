@php($emojis = ['reports.income-statement' => '📈', 'reports.trial-balance' => '⚖️', 'reports.account-ledger' => '📒', 'reports.dues' => '⏳', 'reports.party-statement' => '🤝', 'reports.employee-cost' => '👥'])
<div class="page">
    <x-page-header :title="__('Reports')" :description="__('Figures include posted entries only; voided entries never count.')" />
    <div class="quick-actions">
        @foreach($reports as $route => [$ability, $title, $description])
            <a class="card report-card" href="{{ route('admin.'.$route) }}" wire:key="report-{{ $route }}" wire:navigate><span class="report-card-emoji" aria-hidden="true">{{ $emojis[$route] ?? '📄' }}</span><h2>{{ $title }}</h2><p>{{ $description }}</p></a>
        @endforeach
    </div>
    @if($advanced !== [])
        <h2 class="mt-2">{{ __('Advanced') }}</h2>
        <div class="quick-actions">
            @foreach($advanced as $route => [$ability, $title, $description])
                <a class="card report-card" href="{{ route('admin.'.$route) }}" wire:key="advanced-{{ $route }}" wire:navigate><span class="report-card-emoji" aria-hidden="true">{{ $emojis[$route] ?? '📄' }}</span><h2>{{ $title }}</h2><p>{{ $description }}</p></a>
            @endforeach
        </div>
    @endif
</div>
