@if($body !== null)
    @if($body !== '')<div class="contract-body">{!! $body !!}</div>@endif
@elseif($lines->isNotEmpty())
<div class="items-wrap">
<table class="items">
    <thead><tr>
        <th class="index">#</th>
        <th>{{ __('Description') }}</th>
        <th class="num">{{ __('Qty') }}</th>
        @if($showPrices)
            <th class="num">{{ __('Unit price') }}</th>
            @if($hasLineDiscounts)<th class="num">{{ __('Discount') }}</th>@endif
            @if($hasTax)<th class="num">{{ __('VAT') }}</th>@endif
            <th class="num">{{ __('Amount') }}</th>
        @endif
    </tr></thead>
    <tbody>
        @foreach($lines as $index => $line)
            <tr>
                <td class="index">{{ $index + 1 }}</td>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ $line['quantity'] }}@if($line['unit']) {{ $line['unit'] }}@endif</td>
                @if($showPrices)
                    <td class="num">{{ $money($line['unit_price']) }}</td>
                    @if($hasLineDiscounts)<td class="num">@if($line['discount_amount'] !== null){{ $money($line['discount_amount']) }}@else{{ $line['discount_percent'] }}@endif</td>@endif
                    @if($hasTax)<td class="num">{{ \App\Support\DocumentMath::formatBasisPoints($line['tax_rate']) }}%</td>@endif
                    <td class="num">{{ $money($line['line_amount']) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif
