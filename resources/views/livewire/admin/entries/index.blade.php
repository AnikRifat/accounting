<div class="page">
    <x-notices />
    @error('delete')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <x-page-header :title="__('Transactions')" :description="__('Income, expenses and transfers. Voided entries stay listed but never count in totals.')">
        <x-slot:actions>
            @can('entries.create')
                <x-button icon="plus" :href="route('admin.entries.create', 'income')" :navigate="false" wire:click.prevent="openSheet('create:income')">{{ __('Record income') }}</x-button>
                <x-button icon="plus" :href="route('admin.entries.create', 'expense')" :navigate="false" wire:click.prevent="openSheet('create:expense')">{{ __('Record expense') }}</x-button>
            @endcan
            @can('entries.delete')<x-button variant="secondary" icon="trash" :href="route('admin.entries.trash')">{{ __('Trash') }}</x-button>@endcan
        </x-slot:actions>
    </x-page-header>
    <div class="stats">
        <x-stat :label="__('Income')" emoji="📈" tone="success"><x-slot:value><x-money :value="$income" /></x-slot:value></x-stat>
        <x-stat :label="__('Expense')" emoji="📉" tone="danger"><x-slot:value><x-money :value="$expense" /></x-slot:value></x-stat>
        <x-stat :label="__('Net')" emoji="⚖️" tone="info"><x-slot:value><x-money :value="$income - $expense" signed /></x-slot:value></x-stat>
    </div>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar :active="collect([$type, $account, $party, $payer, $status])->filter(fn ($value) => $value !== '')->count()">
                <x-form.input name="search" :label="__('Search')" type="search" wire:model.live.debounce.300ms="search" maxlength="100" :placeholder="__('Number, description or reference.')" />
                <x-form.date-range :label="__('Date')" />
                <x-slot:filters>
                    <x-form.select name="type" :label="__('Type')" wire:model.live="type" :options="$types" />
                    <x-form.select name="account" :label="__('Account')" wire:model.live="account" :options="$accounts" />
                    <x-form.select name="party" :label="__('Party')" wire:model.live="party" :options="$parties" />
                    <x-form.select name="payer" :label="__('Paid / received by')" wire:model.live="payer" :options="$payers" />
                    <x-form.select name="status" :label="__('Due status')" wire:model.live="status" :options="$statuses" />
                </x-slot:filters>
                <x-slot:clear><x-button variant="ghost" icon="filter-x" wire:click="clearFilters">{{ __('Clear filters') }}</x-button></x-slot:clear>
                <x-slot:actions><x-table.export :columns="$this->tableColumns()" :csv="route('admin.entries.export', $this->filters())" /></x-slot:actions>
            </x-toolbar>
        </x-slot:toolbar>
        <x-table.bulk />
        <x-table :caption="__('Transactions')">
            <x-slot:head><x-table.check-all :ids="$entries->pluck('id')->all()" /><th>{{ __('Entry') }}</th><th>{{ $showCompany ? __('Party · company') : __('Party') }}</th><th>{{ __('Details') }}</th><th class="num">{{ __('Total') }}</th><th class="num">{{ __('Paid') }}</th><th class="num">{{ __('Due') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($entries as $entry)
                @php($status = $entry->dueStatus())
                <tr wire:key="entry-{{ $entry->id }}" @class(['is-voided' => $entry->isVoided()])>
                    <x-table.check :value="$entry->id" :label="$entry->number" />
                    <td class="nowrap"><strong>{{ $entry->number }}</strong><p class="muted">{{ $entry->entry_date->format('d M Y') }}</p><p class="mt-1"><x-badge.entry-type :type="$entry->type" /></p></td>
                    <td>{{ $entry->party?->name ?? '—' }}@if($showCompany)<p class="muted">{{ $entry->company->name }}</p>@endif</td>
                    <td class="min-w-48">@if($entry->type->isBill()){{ $entry->categoryAccount()?->name }}@elseif($entry->type->isSettlement()){{ __('For :number', ['number' => $entry->bill?->number]) }} · {{ $entry->paymentAccount()?->name }}@else{{ $entry->creditAccount()?->name }} → {{ $entry->debitAccount()?->name }}@endif
                        @if($entry->description)<p class="muted">{{ $entry->description }}</p>@endif @if($entry->isVoided())<p class="muted">{{ __('Void reason: :reason', ['reason' => $entry->void_reason]) }}</p>@endif</td>
                    <td class="num">@if($entry->isVoided())<s><x-money :value="$entry->amount" /></s>@else<x-money :value="$entry->amount" />@endif</td>
                    <td class="num">@if($status)<x-money :value="$entry->paidAmount()" />@else—@endif @if($entry->payer)<p class="muted">{{ in_array($entry->type, [\App\Enums\EntryType::Income, \App\Enums\EntryType::Receipt], true) ? __('Received by :name', ['name' => $entry->payer->name]) : __('Paid by :name', ['name' => $entry->payer->name]) }}</p>@endif</td>
                    <td class="num">@if($status && $entry->outstanding > 0)<x-money :value="$entry->outstanding" />@if($entry->due_date)<p class="muted">{{ $entry->due_date->format('d M Y') }}</p>@endif @else—@endif</td>
                    <td><x-badge.due-status :status="$status" :voided="$entry->isVoided()" :reason="$entry->void_reason" /></td>
                    <td><div class="row-actions">@unless($entry->isVoided())
                        @if($status && $entry->outstanding > 0)@can('entries.create')<x-button variant="secondary" size="sm" icon="wallet" :href="route('admin.entries.settle', $entry)" :navigate="false" wire:click.prevent="openSheet('settle:{{ $entry->id }}')" :label="($entry->type === \App\Enums\EntryType::Income ? __('Receive payment') : __('Make payment')).': '.$entry->number" />@endcan @endif
                        @can('entries.update')<x-button variant="ghost" size="sm" icon="pencil" :href="$entry->type->isSettlement() ? route('admin.entries.settlement.edit', $entry) : route('admin.entries.edit', $entry)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $entry->id }}')" :label="__('Edit :number', ['number' => $entry->number])" />@endcan
                        @can('entries.void')<x-button variant="ghost" size="sm" icon="ban" class="text-danger" wire:click="confirmVoid({{ $entry->id }})" :label="__('Void :number', ['number' => $entry->number])" />@endcan
                    @endunless
                    @can('entries.delete')<x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $entry->id }})" wire:confirm="{{ __('Move :number to the trash? It disappears from every total until restored.', ['number' => $entry->number]) }}" :label="__('Delete :number', ['number' => $entry->number])" />@endcan</div></td>
                </tr>
            @empty
                <x-table.empty colspan="9" emoji="🧾">{{ __('No entries found.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $entries->links() }}
    </x-card>
    @if($voidingId)
        <div x-data="{ voiding: true }" x-effect="voiding || $wire.cancelVoid()" wire:key="void-{{ $voidingId }}">
            <x-drawer id="void-entry" x-model="voiding" submit="void" :title="__('Void entry')" :description="__('The entry stays in the list for audit, but is removed from every balance and total. This cannot be undone.')">
                <x-form.input name="voidReason" :label="__('Reason')" wire:model="voidReason" required maxlength="500" autofocus />
                <x-slot:footer><x-button type="submit" variant="danger-solid" icon="ban">{{ __('Void entry') }}</x-button><x-button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-button></x-slot:footer>
            </x-drawer>
        </div>
    @endif
    <x-sheet :label="__('Transaction')">
        @if($this->sheetAction() === 'create' && in_array($this->sheetArgument(), ['income', 'expense', 'transfer'], true))
            <livewire:admin.entries.form :type="$this->sheetArgument()" :key="'sheet-'.$sheet" />
        @elseif(in_array($this->sheetAction(), ['edit', 'settle'], true))
            @php($sheetEntry = \App\Models\JournalEntry::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument()))
            @if($this->sheetAction() === 'settle' || $sheetEntry->type->isSettlement())
                <livewire:admin.entries.settle :entry="$sheetEntry" :key="'sheet-'.$sheet" />
            @else
                <livewire:admin.entries.form :entry="$sheetEntry" :key="'sheet-'.$sheet" />
            @endif
        @endif
    </x-sheet>
</div>
