@props(['date', 'closed' => false])
{{-- A lead's follow-up date, flagged Today or Overdue while the lead is open. --}}
@if($date)
    @php($day = $date->toDateString())
    <span class="nowrap">{{ $date->format('d M Y') }}</span>
    @if(! $closed && $day === today()->toDateString())<x-badge tone="info">{{ __('Today') }}</x-badge>@elseif(! $closed && $day < today()->toDateString())<x-badge tone="danger">{{ __('Overdue') }}</x-badge>@endif
@else
    <span class="muted">—</span>
@endif
