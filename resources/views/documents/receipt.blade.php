{{-- A receipt (or payment voucher) for one payment on a document, in the document template's branding. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $heading }} {{ $number }} · {{ $company->name }}</title>
    <style>
        @include('documents.partials.base-css')
        @if(! $pdf) .sheet { min-height: 0; } @endif
    </style>
</head>
<body>
    @if($toolbar && ! $pdf){{ $toolbar }}@endif
    <div class="sheet">
        @if($watermark && ! $pdf)<div class="watermark" aria-hidden="true">{{ $watermark }}</div>@endif
        @include('documents.blocks.header')
        <table class="title-row"><tr>
            <td class="title-cell" style="width: 55%;">
                <div class="doc-title">{{ $heading }}</div>
                @if($watermark)<div class="status">{{ $watermark }}</div>@endif
            </td>
            <td class="meta-cell">
                <table class="meta">
                    <tr><td class="label">{{ __('Number') }}</td><td class="num">{{ $number }}</td></tr>
                    <tr><td class="label">{{ __('Date') }}</td><td class="num">{{ $date?->format('d M Y') }}</td></tr>
                </table>
            </td>
        </tr></table>
        <table class="receipt-details">
            <tr><td class="label">{{ $partyLabel }}</td><td><span class="party-name">{{ $party?->name ?? '—' }}</span></td></tr>
            <tr><td class="label">{{ __('Amount') }}</td><td><span class="receipt-amount">{{ $money($amount) }}</span></td></tr>
            <tr><td class="label">{{ __('Payment method') }}</td><td>{{ $method ?? '—' }}</td></tr>
            @if($reference)<tr><td class="label">{{ __('Reference') }}</td><td>{{ $reference }}</td></tr>@endif
            <tr><td class="label">{{ __('For') }}</td><td>{{ $document->type->label() }} {{ $document->displayNumber() }} ({{ $money($document->total) }})</td></tr>
            @if($balanceAfter !== null)<tr><td class="label">{{ __('Balance after this payment') }}</td><td><strong>{{ $money($balanceAfter) }}</strong></td></tr>@endif
        </table>
        @include('documents.blocks.signature')
        @include('documents.blocks.footer')
    </div>
</body>
</html>
