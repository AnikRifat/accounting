<div class="page">
    <x-page-header :title="$isPurchase ? __('Payables ageing') : __('Receivables ageing')" :description="$scopeLabel.' · '.__('Days overdue as of :date', ['date' => $asOfLabel])" :back="route('admin.sales.reports.index')" :back-label="__('Sales reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.select name="kind" :label="__('Show')" wire:model.live="kind" :options="$kindOptions" />
                <x-form.date name="asOf" :label="__('As of')" wire:model.live="asOf" required />
            </x-toolbar>
        </x-slot:toolbar>
        @if($parties->isEmpty())
            <x-empty-state emoji="🎉" :title="__('Nothing is outstanding.')" />
        @else
            <x-table :caption="$isPurchase ? __('Payables ageing') : __('Receivables ageing')">
                <x-slot:head><th scope="col">{{ $isPurchase ? __('Supplier') : __('Customer') }}</th>@if($showCompany)<th scope="col">{{ __('Company') }}</th>@endif<th scope="col" class="num">{{ __('Documents') }}</th>
                    @foreach($bucketLabels as $key => $label)<th scope="col" class="num" wire:key="bucket-head-{{ $key }}">{{ $label }}</th>@endforeach<th scope="col" class="num">{{ __('Total') }}</th></x-slot:head>
                @foreach($parties as $row)
                    <tr wire:key="ageing-{{ $row['company']->id }}-{{ $row['party']?->id ?? 0 }}">
                        <th scope="row" class="row-label">{{ $row['party']?->name ?? __('No party') }}</th>
                        @if($showCompany)<td>{{ $row['company']->name }}</td>@endif
                        <td class="num">{{ $row['count'] }}</td>
                        @foreach($row['buckets'] as $key => $amount)<td class="num" wire:key="bucket-{{ $key }}">@if($amount > 0)<x-money :value="$amount" :class="$key !== 'not_due' ? 'text-danger' : ''" />@else<span class="muted">—</span>@endif</td>@endforeach
                        <td class="num"><strong><x-money :value="$row['total']" /></strong></td>
                    </tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row" colspan="{{ $showCompany ? 3 : 2 }}">{{ __('Total') }}</th>
                    @foreach($totals as $key => $amount)<td class="num" wire:key="bucket-total-{{ $key }}"><x-money :value="$amount" /></td>@endforeach
                    <td class="num"><x-money :value="array_sum($totals)" /></td></tr></x-slot:foot>
            </x-table>
            <p class="muted px-5 py-3 max-sm:px-4">{{ __('Balances are what was owed at the end of that date. Days overdue count from the due date, or from the issue date when there is none.') }}</p>
        @endif
    </x-card>
</div>
