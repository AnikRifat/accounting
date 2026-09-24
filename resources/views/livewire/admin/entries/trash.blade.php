<div class="page">
    <x-notices />
    <x-page-header :title="__('Trash')" :description="__('Deleted transactions count in no balance, report or total. Restore one to bring it back, or delete it permanently.')" :back="route('admin.entries.index')" :back-label="__('Transactions')">
        @can('entries.purge')@if($entries->total() > 0)
            <x-slot:actions>
                <x-button variant="danger" icon="trash" wire:click="emptyTrash" wire:confirm="{{ __('Permanently delete every entry in the Trash for the companies shown? This cannot be undone.') }}" wire:loading.attr="disabled">{{ __('Empty trash') }}</x-button>
            </x-slot:actions>
        @endif @endcan
    </x-page-header>
    @error('trash')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <x-card flush>
        <x-table :caption="__('Deleted transactions')">
            <x-slot:head>
                <th>{{ __('Number') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Party') }}</th>
                <th class="text-right">{{ __('Amount') }}</th><th>{{ __('Deleted by') }}</th><th>{{ __('Deleted at') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th>
            </x-slot:head>
            @forelse($entries as $entry)
                <tr wire:key="trashed-{{ $entry->id }}">
                    <td><strong>{{ $entry->number }}</strong>@if($entry->isVoided()) <x-badge>{{ __('Voided') }}</x-badge>@endif</td>
                    @if($showCompany)<td>{{ $entry->company->name }}</td>@endif
                    <td class="whitespace-nowrap">{{ $entry->entry_date->format('d M Y') }}</td>
                    <td><x-badge.entry-type :type="$entry->type" /></td>
                    <td>{{ $entry->party?->name ?? '—' }}</td>
                    <td class="text-right"><x-money :value="$entry->amount" /></td>
                    <td>{{ $entry->deleter?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap">{{ $entry->deleted_at->format('d M Y, g:i a') }}</td>
                    <td class="whitespace-nowrap text-right">
                        <x-button variant="secondary" size="sm" icon="rotate" wire:click="restore({{ $entry->id }})" wire:loading.attr="disabled">{{ __('Restore') }}</x-button>
                        @can('entries.purge')<x-button variant="danger" size="sm" icon="trash" wire:click="purge({{ $entry->id }})" wire:confirm="{{ __('Permanently delete :number? A bill\'s receipts or payments are deleted with it. This cannot be undone.', ['number' => $entry->number]) }}" wire:loading.attr="disabled">{{ __('Delete permanently') }}</x-button>@endcan
                    </td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 9 : 8" emoji="🗑️">{{ __('The Trash is empty.') }}</x-table.empty>
            @endforelse
        </x-table>
    </x-card>
    {{ $entries->links() }}
</div>
