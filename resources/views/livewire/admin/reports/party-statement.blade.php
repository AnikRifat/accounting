<div>
    <div class="page-header"><div><p class="eyebrow"><a href="{{ route('admin.reports.index') }}" wire:navigate>{{ __('Reports') }}</a></p><h1>{{ __('Party statement') }}</h1>
        <p class="muted">@if($selectedCompany){{ $selectedCompany->name }}@endif @if($selectedParty) · {{ $selectedParty->name }}@if($selectedParty->phone) ({{ $selectedParty->phone }})@endif @endif @if($periodLabel) · {{ $periodLabel }}@endif</p></div>
        <button class="btn btn-secondary no-print" type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    @if(! $hasCompanies)
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @elseif($selectedCompany === null)
        <div class="panel stack empty-state"><h2>{{ __('Choose a company') }}</h2>
            <p class="muted">{{ __('A party statement belongs to one company. Choose a company to see its parties.') }}</p>
            <div><a class="btn" href="{{ $chooseCompanyUrl }}" wire:navigate>{{ __('Choose a company') }}</a></div>
        </div>
    @else
        <div class="panel no-print mb-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-form.select name="party" :label="__('Party')" wire:model.live="party" :options="$partyOptions" />
                <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                <x-form.input name="from" :label="__('From date')" type="date" wire:model.live="from" />
                <x-form.input name="to" :label="__('To date')" type="date" wire:model.live="to" />
            </div>
        </div>
        <div class="panel stack">
            @if($report === null)
                <p class="muted">{{ $selectedParty ? __('Choose a valid period to see the statement.') : __('Choose a party to see its statement.') }}</p>
            @else
                <p class="muted">{{ __('Debit raises what the party owes us; credit lowers it. A positive balance is owed to us, a negative balance is owed by us.') }}</p>
                @if($report['truncated'])<p class="notice" role="status">{{ __('Showing the first :count lines. Narrow the period to see the rest; the closing due covers the whole period.', ['count' => number_format(\App\Livewire\Admin\Reports\PartyStatement::ROW_LIMIT)]) }}</p>@endif
                <div class="table-wrap"><table class="report-table"><caption class="sr-only">{{ __('Party statement') }}</caption>
                    <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="text-right">{{ __('Debit') }}</th><th scope="col" class="text-right">{{ __('Credit') }}</th><th scope="col" class="text-right">{{ __('Balance') }}</th></tr></thead>
                    <tbody>
                        <tr class="total-row"><th scope="row" colspan="6">{{ __('Opening due') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($report['opening']) }}</td></tr>
                        @forelse($report['rows'] as $row)
                            <tr wire:key="statement-{{ $row['entry']->id }}">
                                <td class="whitespace-nowrap">{{ $row['entry']->entry_date->format('d M Y') }}</td>
                                <td class="whitespace-nowrap">@can('entries.update')<a class="text-link" href="{{ $row['entry']->type->isSettlement() ? route('admin.entries.settlement.edit', $row['entry']->id) : route('admin.entries.edit', $row['entry']->id) }}" wire:navigate>{{ $row['entry']->number }}</a>@else{{ $row['entry']->number }}@endcan</td>
                                <td>{{ $row['entry']->type->label() }}@if($row['entry']->bill)<p class="muted">{{ __('For :number', ['number' => $row['entry']->bill->number]) }}</p>@endif</td>
                                <td>{{ $row['entry']->description }}@if($row['entry']->due_date)<p class="muted">{{ __('Due :date', ['date' => $row['entry']->due_date->format('d M Y')]) }}</p>@endif</td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ $row['debit'] > 0 ? \App\Support\Money::format($row['debit']) : '' }}</td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ $row['credit'] > 0 ? \App\Support\Money::format($row['credit']) : '' }}</td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($row['balance']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><p class="muted">{{ __('No posted entries for this party in the period.') }}</p></td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="total-row"><th scope="row" colspan="4">{{ __('Period totals') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($report['debit']) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($report['credit']) }}</td><td></td></tr>
                        <tr class="grand-total-row"><th scope="row" colspan="6">{{ $report['closing'] >= 0 ? __('Closing due (owed to us)') : __('Closing due (owed by us)') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($report['closing']) }}</td></tr>
                    </tfoot>
                </table></div>
            @endif
        </div>
    @endif
</div>
