<div class="page">
    <x-page-header :title="__('VAT report')" :description="$scopeLabel.($periodLabel ? ' · '.$periodLabel : '')" :back="route('admin.sales.reports.index')" :back-label="__('Sales reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                <x-form.date-range :label="__('Dates')" />
            </x-toolbar>
        </x-slot:toolbar>
        <div class="split-stats px-5 pb-4 max-sm:px-4">
            <div><p class="muted">{{ __('Taxable sales (net)') }}</p><p class="stat-value"><x-money :value="$total['net']" /></p></div>
            <div><p class="muted">{{ __('Output VAT') }}</p><p class="stat-value"><x-money :value="$total['tax']" /></p></div>
        </div>
        @if($byRate->isEmpty())
            <x-empty-state emoji="🧾" :title="__('Nothing to show')" :description="__('No invoices or credit notes were issued in this period.')" />
        @else
            <x-table :caption="__('By VAT rate')" show-caption>
                <x-slot:head><th scope="col">{{ __('VAT rate') }}</th><th scope="col" class="num">{{ __('Taxable net') }}</th><th scope="col" class="num">{{ __('VAT') }}</th></x-slot:head>
                @foreach($byRate as $rate => $row)
                    <tr wire:key="vat-rate-{{ $rate }}"><th scope="row" class="row-label">{{ \App\Support\DocumentMath::formatBasisPoints($rate) }}%</th><td class="num"><x-money :value="$row['net']" /></td><td class="num"><x-money :value="$row['tax']" /></td></tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th><td class="num"><x-money :value="$total['net']" /></td><td class="num"><x-money :value="$total['tax']" /></td></tr></x-slot:foot>
            </x-table>
            <x-table :caption="__('By month')" show-caption>
                <x-slot:head><th scope="col">{{ __('Month') }}</th><th scope="col" class="num">{{ __('Taxable net') }}</th><th scope="col" class="num">{{ __('VAT') }}</th></x-slot:head>
                @foreach($byMonth as $month => $row)
                    <tr wire:key="vat-month-{{ $month }}"><th scope="row" class="row-label">{{ \App\Livewire\Admin\Sales\Reports\Vat::monthLabel($month) }}</th><td class="num"><x-money :value="$row['net']" /></td><td class="num"><x-money :value="$row['tax']" /></td></tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th><td class="num"><x-money :value="$total['net']" /></td><td class="num"><x-money :value="$total['tax']" /></td></tr></x-slot:foot>
            </x-table>
        @endif
    </x-card>
    <x-card flush :title="__('Check against the books')" :description="__('Only documents posted to accounts are in the ledger; unposted invoices and credit notes are not in the books.')">
        <x-table :caption="__('Check against the books')">
            <x-slot:head>@if($companies->isNotEmpty())<th scope="col">{{ __('Company') }}</th>@else<th scope="col"><span class="sr-only">{{ __('Figure') }}</span></th>@endif
                <th scope="col" class="num">{{ __('VAT on all documents') }}</th><th scope="col" class="num">{{ __('VAT on posted documents') }}</th><th scope="col" class="num">{{ __('VAT Payable movement in the ledger') }}</th><th scope="col" class="num">{{ __('Difference') }}</th></x-slot:head>
            @foreach($companies as $row)
                <tr wire:key="vat-company-{{ $row['company']->id }}"><th scope="row" class="row-label">{{ $row['company']->name }}</th><td class="num"><x-money :value="$row['tax']" /></td><td class="num"><x-money :value="$row['posted']" /></td><td class="num"><x-money :value="$row['ledger']" /></td><td class="num"><x-money :value="$row['ledger'] - $row['posted']" signed /></td></tr>
            @endforeach
            <tr class="grand-total-row" wire:key="vat-books-total"><th scope="row">{{ __('Total') }}</th><td class="num"><x-money :value="$total['tax']" /></td><td class="num"><x-money :value="$postedTax" /></td><td class="num"><x-money :value="$ledgerTax" /></td><td class="num"><x-money :value="$ledgerTax - $postedTax" signed /></td></tr>
        </x-table>
        <p class="muted px-5 py-3 max-sm:px-4">{{ __('The ledger figure is the VAT Payable account\'s credits minus debits in the period. A difference means entries were made on that account directly, such as a VAT payment.') }}</p>
    </x-card>
</div>
