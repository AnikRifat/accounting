<div class="page">
    <x-page-header :title="__('Trial balance')" :description="$scopeLabel.($asOfLabel ? ' · '.__('As of :date', ['date' => $asOfLabel]) : '')" :back="route('admin.reports.index')" :back-label="__('Reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar><x-toolbar><x-form.input name="asOf" :label="__('As of date')" type="date" wire:model.live="asOf" /></x-toolbar></x-slot:toolbar>
        @if($asOfLabel)
            <div class="px-5 pb-3 max-sm:px-4" role="status">@if($balanced)<x-alert tone="success" :title="__('Balanced')">{{ __('Total debits equal total credits.') }}</x-alert>@else<x-alert tone="danger" :title="__('Out of balance')">{{ __('Difference: :amount', ['amount' => \App\Support\Money::format(abs($debitTotal - $creditTotal))]) }}</x-alert>@endif</div>
        @endif
        @if($consolidated)<p class="muted px-5 pb-3 max-sm:px-4">{{ __('All companies combined: accounts with the same type and name are added together.') }}</p>@endif
        @if($rows->isEmpty())
            <x-empty-state emoji="⚖️" :title="__('Nothing to show')" :description="__('No account has a balance on this date.')" />
        @else
            <x-table :caption="__('Trial balance')">
                <x-slot:head><th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Type') }}</th>@if($consolidated)<th scope="col">{{ __('Companies') }}</th>@endif<th scope="col" class="num">{{ __('Debit') }}</th><th scope="col" class="num">{{ __('Credit') }}</th></x-slot:head>
                @foreach($rows as $row)
                    <tr wire:key="tb-{{ $row['type']->value }}-{{ md5($row['name']) }}"><th scope="row" class="row-label">@unless($consolidated){{ $row['code'] }} · @endunless{{ $row['name'] }}</th><td>{{ $row['type']->label() }}</td>
                        @if($consolidated)<td class="muted">{{ implode(', ', $row['companies']) }}</td>@endif
                        <td class="num">@if($row['debit'] > 0)<x-money :value="$row['debit']" />@endif</td>
                        <td class="num">@if($row['credit'] > 0)<x-money :value="$row['credit']" />@endif</td></tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row" colspan="{{ $consolidated ? 3 : 2 }}">{{ __('Total') }}</th><td class="num"><x-money :value="$debitTotal" /></td><td class="num"><x-money :value="$creditTotal" /></td></tr></x-slot:foot>
            </x-table>
        @endif
    </x-card>
</div>
