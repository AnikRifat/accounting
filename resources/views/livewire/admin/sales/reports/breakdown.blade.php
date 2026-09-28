<div class="page">
    <x-page-header :title="$isSales ? __('Sales by customer and item') : __('Purchases by supplier')" :description="$scopeLabel.($periodLabel ? ' · '.$periodLabel : '')" :back="route('admin.sales.reports.index')" :back-label="__('Sales reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.select name="side" :label="__('Show')" wire:model.live="side" :options="$sideOptions" />
                <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                <x-form.date-range :label="__('Dates')" />
            </x-toolbar>
        </x-slot:toolbar>
        @if($parties->isEmpty())
            <x-empty-state emoji="📊" :title="__('Nothing to show')" :description="$isSales ? __('No invoices were issued in this period.') : __('No bills were issued in this period.')" />
        @else
            <x-table :caption="$isSales ? __('By customer') : __('By supplier')" show-caption>
                <x-slot:head><th scope="col">{{ $isSales ? __('Customer') : __('Supplier') }}</th>@if($showCompany)<th scope="col">{{ __('Company') }}</th>@endif<th scope="col" class="num">{{ $isSales ? __('Invoices') : __('Bills') }}</th><th scope="col" class="num">{{ __('Net') }}</th><th scope="col" class="num">{{ __('VAT') }}</th><th scope="col" class="num">{{ __('Total') }}</th></x-slot:head>
                @foreach($parties as $row)
                    <tr wire:key="party-{{ $loop->index }}"><th scope="row" class="row-label">{{ $row['name'] }}</th>@if($showCompany)<td>{{ $row['company'] }}</td>@endif<td class="num">{{ $row['count'] }}</td><td class="num"><x-money :value="$row['net']" /></td><td class="num"><x-money :value="$row['tax']" /></td><td class="num"><x-money :value="$row['total']" /></td></tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row" colspan="{{ $showCompany ? 3 : 2 }}">{{ __('Total') }}</th><td class="num"><x-money :value="$partyTotals['net']" /></td><td class="num"><x-money :value="$partyTotals['tax']" /></td><td class="num"><x-money :value="$partyTotals['total']" /></td></tr></x-slot:foot>
            </x-table>
            @if($isSales)
                <x-table :caption="__('By item')" show-caption>
                    <x-slot:head><th scope="col">{{ __('Item') }}</th>@if($showCompany)<th scope="col">{{ __('Company') }}</th>@endif<th scope="col" class="num">{{ __('Quantity') }}</th><th scope="col" class="num">{{ __('Net') }}</th><th scope="col" class="num">{{ __('VAT') }}</th><th scope="col" class="num">{{ __('Total') }}</th></x-slot:head>
                    @foreach($items as $row)
                        <tr wire:key="item-{{ $loop->index }}"><th scope="row" class="row-label">{{ $row['name'] }}</th>@if($showCompany)<td>{{ $row['company'] }}</td>@endif<td class="num">{{ ($row['count'] < 0 ? '-' : '').\App\Support\DocumentMath::formatMilli(abs($row['count'])) }}</td><td class="num"><x-money :value="$row['net']" /></td><td class="num"><x-money :value="$row['tax']" /></td><td class="num"><x-money :value="$row['total']" /></td></tr>
                    @endforeach
                    <x-slot:foot><tr class="grand-total-row"><th scope="row" colspan="{{ $showCompany ? 3 : 2 }}">{{ __('Total') }}</th><td class="num"><x-money :value="$itemTotals['net']" /></td><td class="num"><x-money :value="$itemTotals['tax']" /></td><td class="num"><x-money :value="$itemTotals['total']" /></td></tr></x-slot:foot>
                </x-table>
            @endif
            <p class="muted px-5 py-3 max-sm:px-4">{{ $isSales ? __('Credit notes are subtracted in the period they were issued. Net is after discounts and before VAT.') : __('Debit notes are subtracted in the period they were issued. Net is after discounts and before VAT.') }}</p>
        @endif
    </x-card>
</div>
