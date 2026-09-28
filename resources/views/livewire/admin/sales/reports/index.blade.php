<div class="page">
    <x-page-header :title="__('Sales reports')" :description="__('Figures come from issued documents; drafts never count and void documents are left out of totals.')" :back="route('admin.sales.dashboard')" :back-label="__('Sales')" />
    <div class="quick-actions">
        @foreach($reports as $route => [$emoji, $title, $description])
            <a class="card report-card" href="{{ route('admin.sales.reports.'.$route) }}" wire:key="sales-report-{{ $route }}" wire:navigate><span class="report-card-emoji" aria-hidden="true">{{ $emoji }}</span><h2>{{ $title }}</h2><p>{{ $description }}</p></a>
        @endforeach
    </div>
</div>
