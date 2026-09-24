<div class="page">
    <x-page-header :title="__('Account ledger')" :description="collect([$selectedCompany?->name, $selectedAccount?->label(), $periodLabel])->filter()->join(' · ')" :back="route('admin.reports.index')" :back-label="__('Reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    @if(! $hasCompanies)
        <x-card><x-empty-state emoji="🔒" :title="__('No company yet')" :description="__('You are not assigned to any company yet.')" /></x-card>
    @elseif($selectedCompany === null)
        <x-card><x-empty-state emoji="🏢" :title="__('Choose a company')" :description="__('An account ledger belongs to one company. Choose a company to see its accounts.')"><x-button :href="$chooseCompanyUrl">{{ __('Choose a company') }}</x-button></x-empty-state></x-card>
    @else
        <x-card flush>
            <x-slot:toolbar>
                <x-toolbar>
                    <x-form.select name="account" :label="__('Account')" wire:model.live="account" :options="$accountOptions" />
                    <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                    <x-form.input name="from" :label="__('From date')" type="date" wire:model.live="from" />
                    <x-form.input name="to" :label="__('To date')" type="date" wire:model.live="to" />
                </x-toolbar>
            </x-slot:toolbar>
            @if($report === null)
                <x-empty-state emoji="📒" :title="$selectedAccount ? __('Pick a period') : __('Pick an account')" :description="$selectedAccount ? __('Choose a valid period to see the ledger.') : __('Choose an account to see its ledger.')" />
            @else
                @if($report['truncated'])<div class="px-5 pb-3 max-sm:px-4"><x-alert tone="warning">{{ __('Showing the first :count lines. Narrow the period to see the rest; the closing balance covers the whole period.', ['count' => number_format(\App\Livewire\Admin\Reports\AccountLedger::ROW_LIMIT)]) }}</x-alert></div>@endif
                <x-table :caption="__('Account ledger')">
                    <x-slot:head><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="num">{{ __('Debit') }}</th><th scope="col" class="num">{{ __('Credit') }}</th><th scope="col" class="num">{{ __('Balance') }}</th></x-slot:head>
                    <tr class="total-row"><th scope="row" colspan="6">{{ __('Opening balance') }}</th><td class="num"><x-money :value="$report['opening']" /></td></tr>
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
