<div>
    <div class="page-header"><div><p class="eyebrow"><a href="{{ route('admin.reports.index') }}" wire:navigate>{{ __('Reports') }}</a></p><h1>{{ __('Income statement') }}</h1><p class="muted">{{ $scopeLabel }}@if($periodLabel) · {{ $periodLabel }}@endif</p></div>
        <button class="btn btn-secondary no-print" type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    <div class="panel no-print mb-6">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-form.select name="company" :label="__('Company')" wire:model.live="company" :options="$companyOptions" />
            <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
            <x-form.input name="from" :label="__('From date')" type="date" wire:model.live="from" />
            <x-form.input name="to" :label="__('To date')" type="date" wire:model.live="to" />
        </div>
    </div>
    <div class="panel">
        @if($income->isEmpty() && $expense->isEmpty())
            <p class="muted">{{ __('No income or expense was posted in this period.') }}</p>
        @else
            <div class="table-wrap"><table class="report-table"><caption class="sr-only">{{ __('Income statement') }}</caption>
                <thead><tr><th scope="col">{{ __('Account') }}</th>
                    @foreach($columns as $column)<th scope="col" class="text-right" wire:key="col-{{ $column->id }}">@if($consolidated)<abbr title="{{ $column->name }}">{{ $column->code }}</abbr>@else{{ __('Amount') }}@endif</th>@endforeach
                    @if($consolidated)<th scope="col" class="text-right">{{ __('Total') }}</th>@endif
                </tr></thead>
                @foreach([__('Income') => [$income, $totalIncome, __('Total income')], __('Expenses') => [$expense, $totalExpense, __('Total expenses')]] as $heading => [$rows, $sectionTotals, $totalLabel])
                    <tbody wire:key="section-{{ $loop->index }}">
                        <tr class="section-row"><th scope="rowgroup" colspan="{{ $columns->count() + ($consolidated ? 2 : 1) }}">{{ $heading }}</th></tr>
                        @forelse($rows as $row)
                            <tr wire:key="row-{{ $loop->parent->index }}-{{ md5($row['name']) }}"><th scope="row" class="row-label">{{ $row['name'] }}</th>
                                @foreach($columns as $column)<td class="text-right tabular-nums">{{ isset($row['amounts'][$column->id]) ? \App\Support\Money::format($row['amounts'][$column->id]) : '—' }}</td>@endforeach
                                @if($consolidated)<td class="text-right tabular-nums">{{ \App\Support\Money::format($row['total']) }}</td>@endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $columns->count() + ($consolidated ? 2 : 1) }}"><p class="muted">{{ __('None in this period.') }}</p></td></tr>
                        @endforelse
                        <tr class="total-row"><th scope="row">{{ $totalLabel }}</th>
                            @foreach($columns as $column)<td class="text-right tabular-nums">{{ \App\Support\Money::format($sectionTotals[$column->id]) }}</td>@endforeach
                            @if($consolidated)<td class="text-right tabular-nums">{{ \App\Support\Money::format(array_sum($sectionTotals)) }}</td>@endif
                        </tr>
                    </tbody>
                @endforeach
                <tfoot><tr class="grand-total-row"><th scope="row">{{ __('Net profit / (loss)') }}</th>
                    @foreach($columns as $column)<td class="text-right tabular-nums">{{ \App\Support\Money::format($totalIncome[$column->id] - $totalExpense[$column->id]) }}</td>@endforeach
                    @if($consolidated)<td class="text-right tabular-nums">{{ \App\Support\Money::format(array_sum($totalIncome) - array_sum($totalExpense)) }}</td>@endif
                </tr></tfoot>
            </table></div>
            @if($consolidated)<p class="muted mt-4">{{ __('Columns show company codes:') }} @foreach($columns as $column){{ $column->code }} = {{ $column->name }}@unless($loop->last); @endunless @endforeach</p>@endif
        @endif
    </div>
</div>
