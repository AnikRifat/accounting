@props(['status', 'voided' => false, 'reason' => null])
{{-- The one DueStatus → colour map. A voided entry shows Voided whatever its status. --}}
@if($voided)
    <x-badge :title="$reason">{{ __('Voided') }}</x-badge>
@elseif($status)
    <x-badge dot :tone="match($status) {
        \App\Enums\DueStatus::Paid => 'success',
        \App\Enums\DueStatus::PartlyPaid => 'warning',
        \App\Enums\DueStatus::Due => 'info',
        \App\Enums\DueStatus::Overdue => 'danger',
    }">{{ $status->label() }}</x-badge>
@endif
