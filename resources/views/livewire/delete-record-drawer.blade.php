<div x-on:open-delete.window="$wire.load($event.detail.kind, $event.detail.id)">
    <x-drawer id="delete-record" wire:model="open" :title="$record ? __('Delete :name', ['name' => $name]) : __('Delete')" :description="$record && ! $blocked && $usage['count'] > 0 ? trans_choice('Used in :count transaction totalling :total.|Used in :count transactions totalling :total.', $usage['count'], ['count' => $usage['count'], 'total' => \App\Support\Money::format($usage['total'])]) : null">
        @if($record)
            @error('record')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
            @if($blocked)
                <x-alert tone="warning">{{ $blocked }}</x-alert>
            @elseif($record instanceof \App\Models\Company)
                <x-alert tone="danger" :title="__('This permanently deletes the company and its whole books.')">
                    <p>{{ trans_choice(':count transaction totalling :total, with every party, account and user assignment of :company, will be erased. This cannot be undone.|:count transactions totalling :total, with every party, account and user assignment of :company, will be erased. This cannot be undone.', $usage['count'], ['count' => $usage['count'], 'total' => \App\Support\Money::format($usage['total']), 'company' => $record->name]) }}</p>
                </x-alert>
                @if($usage['count'] > 0 && ! auth()->user()->hasPermission('entries.purge'))
                    <x-alert tone="warning">{{ __('Deleting a company with transactions needs permission to delete transactions permanently.') }}</x-alert>
                @else
                    <form wire:submit="hardDelete" class="stack">
                        <x-form.input name="confirmCode" :label="__('Type :code to confirm', ['code' => $record->code])" wire:model="confirmCode" required autocomplete="off" class="uppercase" />
                        <div><x-button type="submit" variant="danger-solid" icon="trash" wire:loading.attr="disabled" wire:target="hardDelete">{{ __('Delete company permanently') }}</x-button></div>
                    </form>
                @endif
            @elseif($record instanceof \App\Models\User)
                <p class="muted">{{ __('No transaction names this employee. Their login, company assignments and employee parties will be removed. This cannot be undone.') }}</p>
                <div><x-button variant="danger-solid" icon="trash" wire:click="deleteUnused" wire:loading.attr="disabled" wire:target="deleteUnused">{{ __('Delete employee') }}</x-button></div>
            @elseif($usage['count'] === 0)
                <p class="muted">{{ __('No transaction uses this record, so it can simply be deleted.') }}</p>
                <div><x-button variant="danger-solid" icon="trash" wire:click="deleteUnused" wire:loading.attr="disabled" wire:target="deleteUnused">{{ __('Delete') }}</x-button></div>
            @else
                <section class="stack" aria-labelledby="delete-transfer-title">
                    <h3 id="delete-transfer-title" class="font-semibold">{{ __('Move the transactions, then delete') }}</h3>
                    @if($targets === [])
                        <p class="muted">{{ __('There is no other active record of the same kind in this company to move the transactions to.') }}</p>
                    @else
                        <x-form.select name="targetId" :label="__('Move transactions to')" wire:model="targetId" :options="['' => __('Choose…')] + $targets"
                            :help="$record instanceof \App\Models\Account && $record->isPaymentMethod() ? __('Not possible for a payment method that has transfers with this one: they would become transfers to itself.') : null" />
                        <div><x-button wire:click="transfer" wire:loading.attr="disabled" wire:target="transfer">{{ __('Transfer and delete') }}</x-button></div>
                    @endif
                </section>
                @can('entries.purge')
                    <section class="stack border-t pt-4" aria-labelledby="delete-purge-title">
                        <h3 id="delete-purge-title" class="font-semibold">{{ __('Or delete everything') }}</h3>
                        <x-alert tone="danger" :title="trans_choice('Delete permanently with :count transaction|Delete permanently with :count transactions', $usage['count'], ['count' => $usage['count']])">
                            {{ __('This erases :name and every transaction that uses it (:total in total), including receipts and payments of those bills. Balances and reports change. This cannot be undone.', ['name' => $name, 'total' => \App\Support\Money::format($usage['total'])]) }}
                        </x-alert>
                        <div><x-button variant="danger-solid" icon="trash" wire:click="hardDelete" wire:confirm="{{ __('Delete :name and its transactions permanently?', ['name' => $name]) }}" wire:loading.attr="disabled" wire:target="hardDelete">{{ trans_choice('Delete permanently with :count transaction|Delete permanently with :count transactions', $usage['count'], ['count' => $usage['count']]) }}</x-button></div>
                    </section>
                @endcan
            @endif
        @endif
        <x-slot:footer><x-button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-button></x-slot:footer>
    </x-drawer>
</div>
