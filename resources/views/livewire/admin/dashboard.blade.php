<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">Workspace</p><h1>Overview</h1><p class="muted">Your application's core administration.</p></div></div>
    <div class="stats">
        @foreach(['Users' => $userCount, 'Employees' => $employeeCount, 'Assignable roles' => $roleCount, 'Media files' => $mediaCount] as $label => $count)
            @if($count !== null)<div class="panel" wire:key="stat-{{ $loop->index }}"><p class="muted">{{ $label }}</p><p class="stat-value">{{ $count }}</p></div>@endif
        @endforeach
    </div>
    <div class="panel"><h2>Start with the essentials</h2><p class="muted">Manage people, control access, update application settings, and keep your files in one media library. Add your project's modules when you need them.</p></div>
</div>
