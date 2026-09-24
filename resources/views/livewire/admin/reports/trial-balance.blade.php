<div>
    <div class="page-header"><div><p class="eyebrow"><a href="{{ route('admin.reports.index') }}" wire:navigate>{{ __('Reports') }}</a></p><h1>{{ __('Trial balance') }}</h1>
        <p class="muted">@if($selectedCompany){{ $selectedCompany->name }}@endif @if($asOfLabel) · {{ __('As of :date', ['date' => $asOfLabel]) }}@endif</p></div>
        <button class="btn btn-secondary no-print" type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    @if($companyOptions === [])
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @else
        <div class="panel no-print mb-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-form.select name="company" :label="__('Company')" wire:model.live="company" :options="$companyOptions" />
                <x-form.input name="asOf" :label="__('As of date')" type="date" wire:model.live="asOf" />
            </div>
        </div>
        <div class="panel stack">
            @if($asOfLabel)
                <p role="status">@if($balanced)<span class="badge">{{ __('Balanced') }}</span> <span class="muted">{{ __('Total debits equal total credits.') }}</span>@else<span class="badge badge-danger">{{ __('Out of balance') }}</span> <span class="muted">{{ __('Difference: :amount', ['amount' => \App\Support\Money::format(abs($debitTotal - $creditTotal))]) }}</span>@endif</p>
            @endif
            @if($rows->isEmpty())
                <p class="muted">{{ __('No account has a balance on this date.') }}</p>
            @else
                <div class="table-wrap"><table class="report-table"><caption class="sr-only">{{ __('Trial balance') }}</caption>
                    <thead><tr><th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col" class="text-right">{{ __('Debit') }}</th><th scope="col" class="text-right">{{ __('Credit') }}</th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr wire:key="tb-{{ $row['account']->id }}"><th scope="row" class="row-label">{{ $row['account']->label() }}</th><td>{{ $row['account']->type->label() }}</td>
                                <td class="text-right tabular-nums">{{ $row['debit'] > 0 ? \App\Support\Money::format($row['debit']) : '' }}</td>
                                <td class="text-right tabular-nums">{{ $row['credit'] > 0 ? \App\Support\Money::format($row['credit']) : '' }}</td></tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr class="grand-total-row"><th scope="row" colspan="2">{{ __('Total') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($debitTotal) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($creditTotal) }}</td></tr></tfoot>
                </table></div>
            @endif
        </div>
    @endif
</div>
