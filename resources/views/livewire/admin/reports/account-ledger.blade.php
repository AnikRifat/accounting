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
                    <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                    <x-form.date-range :label="__('Dates')" />
                </x-toolbar>
            </x-slot:toolbar>
            @if($report === null && $summary === null)
                <x-empty-state emoji="📒" :title="__('Pick a period')" :description="__('Choose a valid period to see the ledger.')" />
            @elseif($summary !== null)
                <p class="muted px-5 pb-3 max-sm:px-4">{{ __('Every account with a balance or entries in the period, including categories. Choose an account to see its lines.') }}</p>
                @if($summary['rows']->isEmpty())
                    <x-empty-state emoji="📒" :title="__('Nothing to show')" :description="__('No entries were posted in this period.')" />
                @else
                    <x-table :caption="__('Account ledger')">
                        <x-slot:head><th scope="col">{{ __('Account') }}</th>@if($consolidated)<th scope="col">{{ __('Company') }}</th>@endif<th scope="col">{{ __('Type') }}</th>@if($showOpening)<th scope="col" class="num">{{ __('Opening balance') }}</th>@endif<th scope="col" class="num">{{ __('Debit') }}</th><th scope="col" class="num">{{ __('Credit') }}</th><th scope="col" class="num">{{ __('Closing balance') }}</th></x-slot:head>
                        @foreach($summary['rows'] as $row)
                            <tr wire:key="account-summary-{{ $row['account']->id }}">
                                <th scope="row" class="row-label"><button type="button" class="text-link" wire:click="$set('account', '{{ $row['account']->id }}')">{{ $row['account']->label() }}</button></th>
                                @if($consolidated)<td>{{ $row['account']->company->code }}</td>@endif
                                <td>{{ $row['account']->type->label() }}</td>
                                @if($showOpening)<td class="num"><x-money :value="$row['opening']" /></td>@endif
                                <td class="num"><x-money :value="$row['debit']" /></td>
                                <td class="num"><x-money :value="$row['credit']" /></td>
                                <td class="num"><x-money :value="$row['closing']" /></td>
                            </tr>
                        @endforeach
                        <x-slot:foot><tr class="grand-total-row"><th scope="row" colspan="{{ ($consolidated ? 3 : 2) + ($showOpening ? 1 : 0) }}">{{ __('Period totals') }}</th>
                            <td class="num"><x-money :value="$summary['debit']" /></td>
                            <td class="num"><x-money :value="$summary['credit']" /></td><td></td></tr></x-slot:foot>
                    </x-table>
                @endif
            @else
                @if($report['truncated'])<div class="px-5 pb-3 max-sm:px-4"><x-alert tone="warning">{{ __('Showing the first :count lines. Narrow the period to see the rest; the closing balance covers the whole period.', ['count' => number_format(\App\Livewire\Admin\Reports\AccountLedger::ROW_LIMIT)]) }}</x-alert></div>@endif
                <x-table :caption="__('Account ledger')">
                    <x-slot:head><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="num">{{ __('Debit') }}</th><th scope="col" class="num">{{ __('Credit') }}</th><th scope="col" class="num">{{ __('Balance') }}</th></x-slot:head>
                    @if($showOpening)<tr class="total-row"><th scope="row" colspan="6">{{ __('Opening balance') }}</th><td class="num"><x-money :value="$report['opening']" /></td></tr>@endif
                    @forelse($report['rows'] as $row)
                        <tr wire:key="line-{{ $loop->index }}-{{ $row['entry']->id }}">
                            <td class="nowrap">{{ $row['entry']->entry_date->format('d M Y') }}</td>
                            <td class="nowrap">@can('entries.update')<a class="text-link" href="{{ route('admin.entries.edit', $row['entry']->id) }}" wire:navigate>{{ $row['entry']->number }}</a>@else{{ $row['entry']->number }}@endcan</td>
                            <td><x-badge.entry-type :type="$row['entry']->type" /></td>
                            <td>{{ $row['entry']->description }}@if($row['entry']->reference)<p class="muted">{{ __('Ref: :reference', ['reference' => $row['entry']->reference]) }}</p>@endif</td>
                            <td class="num">@if($row['debit'] > 0)<x-money :value="$row['debit']" />@endif</td>
                            <td class="num">@if($row['credit'] > 0)<x-money :value="$row['credit']" />@endif</td>
                            <td class="num"><x-money :value="$row['balance']" /></td>
                        </tr>
                    @empty
                        <x-table.empty colspan="7" emoji="📒">{{ __('No posted entries on this account in the period.') }}</x-table.empty>
                    @endforelse
                    <x-slot:foot>
                        <tr class="total-row"><th scope="row" colspan="4">{{ __('Period totals') }}</th><td class="num"><x-money :value="$report['debit']" /></td><td class="num"><x-money :value="$report['credit']" /></td><td></td></tr>
                        <tr class="grand-total-row"><th scope="row" colspan="6">{{ __('Closing balance') }}</th><td class="num"><x-money :value="$report['closing']" /></td></tr>
                    </x-slot:foot>
                </x-table>
            @endif
        </x-card>
    @endif
</div>
