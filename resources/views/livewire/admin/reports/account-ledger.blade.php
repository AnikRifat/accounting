<div>
    <div class="page-header"><div><p class="eyebrow"><a href="{{ route('admin.reports.index') }}" wire:navigate>{{ __('Reports') }}</a></p><h1>{{ __('Account ledger') }}</h1>
        <p class="muted">@if($selectedCompany){{ $selectedCompany->name }}@endif @if($selectedAccount) · {{ $selectedAccount->label() }}@endif @if($periodLabel) · {{ $periodLabel }}@endif</p></div>
        <button class="btn btn-secondary no-print" type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    @if($companyOptions === [])
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @else
        <div class="panel no-print mb-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <x-form.select name="company" :label="__('Company')" wire:model.live="company" :options="$companyOptions" />
                <x-form.select name="account" :label="__('Account')" wire:model.live="account" :options="$accountOptions" />
                <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                <x-form.input name="from" :label="__('From date')" type="date" wire:model.live="from" />
                <x-form.input name="to" :label="__('To date')" type="date" wire:model.live="to" />
            </div>
        </div>
        <div class="panel">
            @if($report === null)
                <p class="muted">{{ $selectedAccount ? __('Choose a valid period to see the ledger.') : __('Choose an account to see its ledger.') }}</p>
            @else
                @if($report['truncated'])<p class="notice" role="status">{{ __('Showing the first :count lines. Narrow the period to see the rest; the closing balance covers the whole period.', ['count' => number_format(\App\Livewire\Admin\Reports\AccountLedger::ROW_LIMIT)]) }}</p>@endif
                <div class="table-wrap"><table class="report-table"><caption class="sr-only">{{ __('Account ledger') }}</caption>
                    <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="text-right">{{ __('Debit') }}</th><th scope="col" class="text-right">{{ __('Credit') }}</th><th scope="col" class="text-right">{{ __('Balance') }}</th></tr></thead>
                    <tbody>
                        <tr class="total-row"><th scope="row" colspan="6">{{ __('Opening balance') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($report['opening']) }}</td></tr>
                        @forelse($report['rows'] as $row)
                            <tr wire:key="line-{{ $loop->index }}-{{ $row['entry']->id }}">
                                <td class="whitespace-nowrap">{{ $row['entry']->entry_date->format('d M Y') }}</td>
                                <td class="whitespace-nowrap">@can('entries.update')<a class="text-link" href="{{ route('admin.entries.edit', $row['entry']->id) }}" wire:navigate>{{ $row['entry']->number }}</a>@else{{ $row['entry']->number }}@endcan</td>
                                <td>{{ $row['entry']->type->label() }}</td>
                                <td>{{ $row['entry']->description }}@if($row['entry']->reference)<p class="muted">{{ __('Ref: :reference', ['reference' => $row['entry']->reference]) }}</p>@endif</td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ $row['debit'] > 0 ? \App\Support\Money::format($row['debit']) : '' }}</td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ $row['credit'] > 0 ? \App\Support\Money::format($row['credit']) : '' }}</td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($row['balance']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><p class="muted">{{ __('No posted entries on this account in the period.') }}</p></td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="total-row"><th scope="row" colspan="4">{{ __('Period totals') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($report['debit']) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($report['credit']) }}</td><td></td></tr>
                        <tr class="grand-total-row"><th scope="row" colspan="6">{{ __('Closing balance') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($report['closing']) }}</td></tr>
                    </tfoot>
                </table></div>
            @endif
        </div>
    @endif
</div>
