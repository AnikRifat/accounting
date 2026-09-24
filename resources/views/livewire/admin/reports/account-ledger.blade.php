<div class="page">
    <x-page-header :title="__('Account ledger')" :description="collect([$scopeLabel, $selectedAccount ? $selectedAccount->label().($consolidated ? ' ('.$selectedAccount->company->code.')' : '') : null, $periodLabel])->filter()->join(' · ')" :back="route('admin.reports.index')" :back-label="__('Reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    @if(! $hasCompanies)
        <x-card><x-empty-state emoji="🔒" :title="__('No company yet')" :description="__('You are not assigned to any company yet.')" /></x-card>
    @else
        <x-card flush>
            <x-slot:toolbar>
                <x-toolbar>
                    <x-form.select name="account" :label="__('Account')" wire:model.live="account" :options="$accountOptions" />
                    <x-form.select name="type" :label="__('Type')" wire:model.live="type" :options="$typeOptions" />
                    <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                    <x-form.date-range :label="__('Dates')" />
                </x-toolbar>
            </x-slot:toolbar>
            @if($report === null && $lines === null)
                <x-empty-state emoji="📒" :title="__('Pick a period')" :description="__('Choose a valid period to see the ledger.')" />
            @elseif($lines !== null)
                <p class="muted px-5 pb-3 max-sm:px-4">{{ __('Every posted line on income and expense categories in the period. The balance is net profit (income less expense) unless a type is chosen. Choose an account to see only its lines.') }}</p>
                @if($lines['truncated'])<div class="px-5 pb-3 max-sm:px-4"><x-alert tone="warning">{{ __('Showing the first :count lines. Narrow the period to see the rest; the totals and closing balance cover the whole period.', ['count' => number_format(\App\Livewire\Admin\Reports\AccountLedger::ROW_LIMIT)]) }}</x-alert></div>@endif
                <x-table :caption="__('Account ledger')">
                    <x-slot:head><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Category') }}</th><th scope="col">{{ __('Account') }}</th>@if($consolidated)<th scope="col">{{ __('Company') }}</th>@endif<th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="num">{{ __('Debit') }}</th><th scope="col" class="num">{{ __('Credit') }}</th><th scope="col" class="num">{{ $type === '' ? __('Net balance') : __('Balance') }}</th></x-slot:head>
                    @if($showOpening)<tr class="total-row"><th scope="row" colspan="{{ $consolidated ? 8 : 7 }}">{{ __('Opening balance') }}</th><td class="num"><x-money :value="$lines['opening']" :signed="$type === ''" /></td></tr>@endif
                    @forelse($lines['rows'] as $row)
                        <tr wire:key="all-line-{{ $loop->index }}-{{ $row['entry']->id }}">
                            <td class="nowrap">{{ $row['entry']->entry_date->format('d M Y') }}<p class="muted">{{ __('Recorded :time', ['time' => $row['entry']->created_at->format('d M, h:i A')]) }}</p></td>
                            <td><button type="button" class="text-link" wire:click="$set('account', '{{ $row['account']->id }}')">{{ $row['account']->name }}</button> – <span class="nowrap">@can('entries.update')<a class="text-link" href="{{ route('admin.entries.edit', $row['entry']->id) }}" wire:navigate>{{ $row['entry']->number }}</a>@else{{ $row['entry']->number }}@endcan</span></td>
                            <td>@foreach($row['counter'] as $counter)<span class="block">{{ $counter->label() }}</span>@endforeach</td>
                            @if($consolidated)<td>{{ $row['account']->company->code }}</td>@endif
                            <td><x-badge.entry-type :type="$row['entry']->type" /></td>
                            <td>{{ $row['entry']->description }}@if($row['entry']->reference)<p class="muted">{{ __('Ref: :reference', ['reference' => $row['entry']->reference]) }}</p>@endif</td>
                            <td class="num">@if($row['debit'] > 0)<x-money :value="$row['debit']" />@endif</td>
                            <td class="num">@if($row['credit'] > 0)<x-money :value="$row['credit']" />@endif</td>
                            <td class="num"><x-money :value="$row['balance']" :signed="$type === ''" /></td>
                        </tr>
                    @empty
                        <x-table.empty :colspan="$consolidated ? 9 : 8" emoji="📒">{{ __('No entries were posted in this period.') }}</x-table.empty>
                    @endforelse
                    <x-slot:foot><tr class="total-row"><th scope="row" colspan="{{ $consolidated ? 6 : 5 }}">{{ __('Period totals') }}</th>
                        <td class="num"><x-money :value="$lines['debit']" /></td><td class="num"><x-money :value="$lines['credit']" /></td><td></td></tr>
                        <tr class="grand-total-row"><th scope="row" colspan="{{ $consolidated ? 8 : 7 }}">{{ __('Closing balance') }}</th><td class="num"><x-money :value="$lines['closing']" :signed="$type === ''" /></td></tr></x-slot:foot>
                </x-table>
            @else
                @if($report['truncated'])<div class="px-5 pb-3 max-sm:px-4"><x-alert tone="warning">{{ __('Showing the first :count lines. Narrow the period to see the rest; the closing balance covers the whole period.', ['count' => number_format(\App\Livewire\Admin\Reports\AccountLedger::ROW_LIMIT)]) }}</x-alert></div>@endif
                <x-table :caption="__('Account ledger')">
                    <x-slot:head><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Category') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="num">{{ __('Debit') }}</th><th scope="col" class="num">{{ __('Credit') }}</th><th scope="col" class="num">{{ __('Balance') }}</th></x-slot:head>
                    @if($showOpening)<tr class="total-row"><th scope="row" colspan="7">{{ __('Opening balance') }}</th><td class="num"><x-money :value="$report['opening']" /></td></tr>@endif
                    @forelse($report['rows'] as $row)
                        <tr wire:key="line-{{ $loop->index }}-{{ $row['entry']->id }}">
                            <td class="nowrap">{{ $row['entry']->entry_date->format('d M Y') }}<p class="muted">{{ __('Recorded :time', ['time' => $row['entry']->created_at->format('d M, h:i A')]) }}</p></td>
                            <td>{{ $selectedAccount->name }} – <span class="nowrap">@can('entries.update')<a class="text-link" href="{{ route('admin.entries.edit', $row['entry']->id) }}" wire:navigate>{{ $row['entry']->number }}</a>@else{{ $row['entry']->number }}@endcan</span></td>
                            <td>@foreach($row['counter'] as $counter)<span class="block">{{ $counter->label() }}</span>@endforeach</td>
                            <td><x-badge.entry-type :type="$row['entry']->type" /></td>
                            <td>{{ $row['entry']->description }}@if($row['entry']->reference)<p class="muted">{{ __('Ref: :reference', ['reference' => $row['entry']->reference]) }}</p>@endif</td>
                            <td class="num">@if($row['debit'] > 0)<x-money :value="$row['debit']" />@endif</td>
                            <td class="num">@if($row['credit'] > 0)<x-money :value="$row['credit']" />@endif</td>
                            <td class="num"><x-money :value="$row['balance']" /></td>
                        </tr>
                    @empty
                        <x-table.empty colspan="8" emoji="📒">{{ __('No posted entries on this account in the period.') }}</x-table.empty>
                    @endforelse
                    <x-slot:foot>
                        <tr class="total-row"><th scope="row" colspan="5">{{ __('Period totals') }}</th><td class="num"><x-money :value="$report['debit']" /></td><td class="num"><x-money :value="$report['credit']" /></td><td></td></tr>
                        <tr class="grand-total-row"><th scope="row" colspan="7">{{ __('Closing balance') }}</th><td class="num"><x-money :value="$report['closing']" /></td></tr>
                    </x-slot:foot>
                </x-table>
            @endif
        </x-card>
    @endif
</div>
