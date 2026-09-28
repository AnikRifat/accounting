@if($showPrices && $body === null && $lines->isNotEmpty())
<table class="totals-wrap"><tr>
    <td style="width: 50%;"></td>
    <td>
        <table class="totals">
            <tr><td>{{ __('Subtotal') }}</td><td class="num">{{ $money($totals['subtotal']) }}</td></tr>
            @if($totals['discount'] > 0)<tr><td>{{ $totals['discountLabel'] }}</td><td class="num">{{ $money(-$totals['discount']) }}</td></tr>@endif
            @if($hasTax)<tr><td>{{ $document->tax_inclusive ? __('VAT (included)') : __('VAT') }}</td><td class="num">{{ $money($totals['tax']) }}</td></tr>@endif
            <tr><td class="grand">{{ __('Total') }}</td><td class="grand num">{{ $money($totals['total']) }}</td></tr>
            @if($payment)
                @if($payment['credited'] > 0)<tr><td>{{ $type->isPurchase() ? __('Debit notes') : __('Credit notes') }}</td><td class="num">{{ $money(-$payment['credited']) }}</td></tr>@endif
                <tr><td>{{ __('Paid') }}</td><td class="num">{{ $money(-$payment['paid']) }}</td></tr>
                <tr><td class="balance">{{ __('Balance due') }}</td><td class="balance num">{{ $money($payment['balance']) }}</td></tr>
            @endif
        </table>
    </td>
</tr></table>
@endif
