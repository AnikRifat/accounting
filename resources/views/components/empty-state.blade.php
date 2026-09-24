@props(['title', 'description' => null, 'emoji' => '📭'])
{{-- Nothing to show yet: say why in one line and offer the way forward in the default slot. --}}
<div {{ $attributes->class(['empty-state']) }}>
    <span class="empty-state-emoji" aria-hidden="true">{{ $emoji }}</span>
    <h2>{{ $title }}</h2>
    @if($description)<p>{{ $description }}</p>@endif
    @if($slot->isNotEmpty())<div class="btn-group">{{ $slot }}</div>@endif
</div>
