@props(['tone' => 'info', 'title' => null])
{{-- Inline message. info = guidance, success, warning, danger = failure. --}}
<div {{ $attributes->class(['alert', 'alert-'.$tone => $tone !== 'info']) }} role="{{ $tone === 'danger' ? 'alert' : 'status' }}">
    <x-icon :name="match($tone) { 'success' => 'check', 'danger', 'warning' => 'alert', default => 'info' }" />
    <div>@if($title)<p class="alert-title">{{ $title }}</p>@endif{{ $slot }}</div>
</div>
