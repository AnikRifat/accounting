@props(['type'])
{{-- The one EntryType → colour map. --}}
<x-badge :tone="match($type) {
    \App\Enums\EntryType::Income, \App\Enums\EntryType::Receipt => 'success',
    \App\Enums\EntryType::Expense, \App\Enums\EntryType::Payment => 'danger',
    \App\Enums\EntryType::Transfer => 'info',
    \App\Enums\EntryType::CreditNote, \App\Enums\EntryType::DebitNote => 'warning',
    default => 'neutral',
}">{{ $type->label() }}</x-badge>
