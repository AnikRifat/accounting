<div class="page">
    <x-page-header :title="__('CRM reports')" :description="__('Every report follows the company in the header. Sales reps without access to every lead see their own figures only.')" />
    <div class="quick-actions">
        @foreach($reports as $route => [$emoji, $title, $description])
            <a class="card report-card" href="{{ route('admin.'.$route) }}" wire:key="report-{{ $route }}" wire:navigate><span class="report-card-emoji" aria-hidden="true">{{ $emoji }}</span><h2>{{ $title }}</h2><p>{{ $description }}</p></a>
        @endforeach
    </div>
    <h2 class="mt-2">{{ __('Detailed lists') }}</h2>
    <div class="quick-actions">
        @foreach($lists as $route => [$emoji, $title, $description])
            <a class="card report-card" href="{{ route('admin.'.$route) }}" wire:key="list-{{ $route }}" wire:navigate><span class="report-card-emoji" aria-hidden="true">{{ $emoji }}</span><h2>{{ $title }}</h2><p>{{ $description }}</p></a>
        @endforeach
    </div>
</div>
