<div class="page">
    <x-page-header :title="__('Income statement')" :description="$scopeLabel.($periodLabel ? ' · '.$periodLabel : '')" :back="route('admin.reports.index')" :back-label="__('Reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                <x-form.input name="from" :label="__('From date')" type="date" wire:model.live="from" />
                <x-form.input name="to" :label="__('To date')" type="date" wire:model.live="to" />
            </x-toolbar>
        </x-slot:toolbar>
        @if($income->isEmpty() && $expense->isEmpty())
            <x-empty-state emoji="📈" :title="__('Nothing to show')" :description="__('No income or expense was posted in this period.')" />
        @else
            <x-table :caption="__('Income statement')" grouped>
                <x-slot:head><th scope="col">{{ __('Account') }}</th>
                    @foreach($columns as $column)<th scope="col" class="num" wire:key="col-{{ $column->id }}">@if($consolidated)<abbr title="{{ $column->name }}">{{ $column->code }}</abbr>@else{{ __('Amount') }}@endif</th>@endforeach
                    @if($consolidated)<th scope="col" class="num">{{ __('Total') }}</th>@endif
                </x-slot:head>
                @foreach([__('Income') => [$income, $totalIncome, __('Total income')], __('Expenses') => [$expense, $totalExpense, __('Total expenses')]] as $heading => [$rows, $sectionTotals, $totalLabel])
                    <tbody wire:key="section-{{ $loop->index }}">
                        <tr class="section-row"><th scope="rowgroup" colspan="{{ $columns->count() + ($consolidated ? 2 : 1) }}">{{ $heading }}</th></tr>
                        @forelse($rows as $row)
                            <tr wire:key="row-{{ $loop->parent->index }}-{{ md5($row['name']) }}"><th scope="row" class="row-label">{{ $row['name'] }}</th>
                                @foreach($columns as $column)<td class="num">@isset($row['amounts'][$column->id])<x-money :value="$row['amounts'][$column->id]" />@else—@endisset</td>@endforeach
                                @if($consolidated)<td class="num"><x-money :value="$row['total']" /></td>@endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $columns->count() + ($consolidated ? 2 : 1) }}"><p class="muted">{{ __('None in this period.') }}</p></td></tr>
                        @endforelse
                        <tr class="total-row"><th scope="row">{{ $totalLabel }}</th>
                            @foreach($columns as $column)<td class="num"><x-money :value="$sectionTotals[$column->id]" /></td>@endforeach
                            @if($consolidated)<td class="num"><x-money :value="array_sum($sectionTotals)" /></td>@endif
                        </tr>
                    </tbody>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Net profit / (loss)') }}</th>
                    @foreach($columns as $column)<td class="num"><x-money :value="$totalIncome[$column->id] - $totalExpense[$column->id]" signed /></td>@endforeach
                    @if($consolidated)<td class="num"><x-money :value="array_sum($totalIncome) - array_sum($totalExpense)" signed /></td>@endif
                </tr></x-slot:foot>
            </x-table>
            @if($consolidated)<p class="muted px-5 py-3 max-sm:px-4">{{ __('Columns show company codes:') }} @foreach($columns as $column){{ $column->code }} = {{ $column->name }}@unless($loop->last); @endunless @endforeach</p>@endif
        @endif
    </x-card>
</div>
