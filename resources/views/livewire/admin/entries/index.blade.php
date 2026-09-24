<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ __('Transactions') }}</h1><p class="muted">{{ __('Income, expenses and transfers. Voided entries stay listed but never count in totals.') }}</p></div>
        @can('entries.create')<div class="flex flex-wrap gap-3">
            <a class="btn" href="{{ route('admin.entries.create', 'income') }}" wire:navigate>{{ __('Record income') }}</a>
            <a class="btn" href="{{ route('admin.entries.create', 'expense') }}" wire:navigate>{{ __('Record expense') }}</a>
            <a class="btn btn-secondary" href="{{ route('admin.entries.create', 'transfer') }}" wire:navigate>{{ __('Record transfer') }}</a>
        </div>@endcan
    </div>
    <div class="stats">
        <div class="panel"><p class="muted">{{ __('Income') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($income) }}</p></div>
        <div class="panel"><p class="muted">{{ __('Expense') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($expense) }}</p></div>
        <div class="panel"><p class="muted">{{ __('Net') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($income - $expense) }}</p></div>
    </div>
    @if($voidingId)
        <form class="panel stack mb-6" wire:submit="void" wire:key="void-{{ $voidingId }}">
            <h2>{{ __('Void entry') }}</h2>
            <p class="muted">{{ __('The entry stays in the list for audit, but is removed from every balance and total. This cannot be undone.') }}</p>
            <x-form.input name="voidReason" :label="__('Reason')" wire:model="voidReason" required maxlength="500" autofocus />
            <div class="actions"><button class="btn btn-danger" type="submit" wire:loading.attr="disabled">{{ __('Void entry') }}</button><button class="btn btn-secondary" type="button" wire:click="cancelVoid">{{ __('Cancel') }}</button></div>
        </form>
    @endif
    <div class="panel stack">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-form.input name="from" :label="__('From date')" type="date" wire:model.live="from" />
            <x-form.input name="to" :label="__('To date')" type="date" wire:model.live="to" />
            <x-form.select name="type" :label="__('Type')" wire:model.live="type" :options="$types" />
            <x-form.select name="account" :label="__('Account')" wire:model.live="account" :options="$accounts" />
            <x-form.select name="party" :label="__('Party')" wire:model.live="party" :options="$parties" />
            <x-form.select name="status" :label="__('Due status')" wire:model.live="status" :options="$statuses" />
            <x-form.input name="search" :label="__('Search')" type="search" wire:model.live.debounce.300ms="search" maxlength="100" :help="__('Number, description or reference.')" />
            <div class="flex items-center gap-3"><button class="btn btn-secondary" type="button" wire:click="clearFilters">{{ __('Clear filters') }}</button><a class="btn btn-secondary" href="{{ route('admin.entries.export', $this->filters()) }}">{{ __('Export CSV') }}</a></div>
        </div>
        <div class="table-wrap"><table><thead><tr><th>{{ __('Number') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Party') }}</th><th>{{ __('Details') }}</th><th class="text-right">{{ __('Total') }}</th><th class="text-right">{{ __('Paid') }}</th><th class="text-right">{{ __('Due') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
            @forelse($entries as $entry)
                @php($status = $entry->dueStatus())
                <tr wire:key="entry-{{ $entry->id }}" @class(['opacity-60' => $entry->isVoided()])>
                    <td><strong>{{ $entry->number }}</strong></td>@if($showCompany)<td>{{ $entry->company->name }}</td>@endif
                    <td class="whitespace-nowrap">{{ $entry->entry_date->format('d M Y') }}</td>
                    <td>{{ $entry->type->label() }}</td>
                    <td>{{ $entry->party?->name ?? '—' }}</td>
                    <td>@if($entry->type->isBill()){{ $entry->categoryAccount()?->name }}@elseif($entry->type->isSettlement()){{ __('For :number', ['number' => $entry->bill?->number]) }} · {{ $entry->paymentAccount()?->name }}@else{{ $entry->creditAccount()?->name }} → {{ $entry->debitAccount()?->name }}@endif
                        @if($entry->description)<p class="muted">{{ $entry->description }}</p>@endif @if($entry->isVoided())<p class="muted">{{ __('Void reason: :reason', ['reason' => $entry->void_reason]) }}</p>@endif</td>
                    <td class="text-right tabular-nums whitespace-nowrap">@if($entry->isVoided())<s>{{ \App\Support\Money::format($entry->amount) }}</s>@else{{ \App\Support\Money::format($entry->amount) }}@endif</td>
                    <td class="text-right tabular-nums whitespace-nowrap">{{ $status ? \App\Support\Money::format($entry->paidAmount()) : '—' }}</td>
                    <td class="text-right tabular-nums whitespace-nowrap">{{ $status && $entry->outstanding > 0 ? \App\Support\Money::format($entry->outstanding) : '—' }}@if($status && $entry->outstanding > 0 && $entry->due_date)<p class="muted">{{ $entry->due_date->format('d M Y') }}</p>@endif</td>
                    <td class="whitespace-nowrap">@if($entry->isVoided())<span class="badge badge-neutral" title="{{ $entry->void_reason }}">{{ __('Voided') }}</span>@elseif($status)<span @class(['badge', 'badge-neutral' => $status === \App\Enums\DueStatus::Paid]) @if($status === \App\Enums\DueStatus::Overdue) style="background:#fdecea;color:var(--danger)" @endif>{{ $status->label() }}</span>@endif</td>
                    <td class="whitespace-nowrap">@unless($entry->isVoided())
                        @if($status && $entry->outstanding > 0)@can('entries.create')<a class="text-link mr-3" href="{{ route('admin.entries.settle', $entry) }}" wire:navigate>{{ $entry->type === \App\Enums\EntryType::Income ? __('Receive payment') : __('Make payment') }}</a>@endcan @endif
                        @can('entries.update')<a class="text-link" href="{{ $entry->type->isSettlement() ? route('admin.entries.settlement.edit', $entry) : route('admin.entries.edit', $entry) }}" aria-label="{{ __('Edit :number', ['number' => $entry->number]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan
                        @can('entries.void')<button class="text-link ml-3" type="button" wire:click="confirmVoid({{ $entry->id }})" aria-label="{{ __('Void :number', ['number' => $entry->number]) }}">{{ __('Void') }}</button>@endcan
                    @endunless</td>
                </tr>
            @empty<tr><td colspan="{{ $showCompany ? 11 : 10 }}"><p class="muted">{{ __('No entries found.') }}</p></td></tr>@endforelse
        </tbody></table></div>{{ $entries->links() }}
    </div>
</div>
