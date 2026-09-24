<div>
    <div class="page-header"><div><p class="eyebrow"><a href="{{ route('admin.reports.index') }}" wire:navigate>{{ __('Reports') }}</a></p><h1>{{ __('Dues') }}</h1><p class="muted">{{ $scopeLabel }} · {{ __('Outstanding as of :date', ['date' => $todayLabel]) }}@if($overdue) · {{ __('Overdue only') }}@endif</p></div>
        <button class="btn btn-secondary no-print" type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    <div class="panel no-print mb-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-form.select name="kind" :label="__('Show')" wire:model.live="kind" :options="$kindOptions" />
            <x-form.select name="party" :label="__('Party')" wire:model.live="party" :options="$partyOptions" />
            <div class="flex items-end pb-8"><x-form.checkbox name="overdue" :label="__('Overdue only')" wire:model.live="overdue" /></div>
        </div>
    </div>
    @if($companyTotals->isEmpty())
        <div class="panel"><p class="muted">{{ $overdue ? __('Nothing is overdue.') : __('Nothing is outstanding.') }}</p></div>
    @else
        @if(! $consolidated)
            <div class="panel mb-6 due-stats">
                <div><p class="muted">{{ __('Receivable (owed to us)') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($companyTotals->sum('receivable')) }}</p></div>
                <div><p class="muted">{{ __('Payable (we owe)') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($companyTotals->sum('payable')) }}</p></div>
            </div>
        @else
        <section class="panel mb-6" aria-labelledby="dues-totals-heading"><h2 id="dues-totals-heading">{{ __('Totals by company') }}</h2>
            <div class="table-wrap"><table class="report-table"><caption class="sr-only">{{ __('Totals by company') }}</caption>
                <thead><tr><th scope="col">{{ __('Company') }}</th><th scope="col" class="text-right">{{ __('Receivable') }}</th><th scope="col" class="text-right">{{ __('Payable') }}</th></tr></thead>
                <tbody>@foreach($companyTotals as $row)<tr wire:key="dues-company-{{ $row['company']->id }}"><th scope="row" class="row-label">{{ $row['company']->name }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($row['receivable']) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($row['payable']) }}</td></tr>@endforeach</tbody>
                <tfoot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($companyTotals->sum('receivable')) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($companyTotals->sum('payable')) }}</td></tr></tfoot>
            </table></div>
        </section>
        @endif
        @foreach(['receivable' => [__('Receivables (owed to us)'), __('Receive payment')], 'payable' => [__('Payables (we owe)'), __('Make payment')]] as $key => [$heading, $actionLabel])
            @if($sections[$key]->isNotEmpty())
                <section class="panel mb-6" aria-labelledby="dues-{{ $key }}-heading" wire:key="dues-section-{{ $key }}"><h2 id="dues-{{ $key }}-heading">{{ $heading }}</h2>
                    <div class="table-wrap"><table class="report-table"><caption class="sr-only">{{ $heading }}</caption>
                        <thead><tr><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Category') }}</th><th scope="col" class="text-right">{{ __('Total') }}</th><th scope="col" class="text-right">{{ __('Paid') }}</th><th scope="col" class="text-right">{{ __('Outstanding') }}</th><th scope="col">{{ __('Due date') }}</th><th scope="col" class="text-right">{{ __('Days overdue') }}</th><th scope="col" class="no-print"><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
                        @foreach($sections[$key] as $group)
                            <tbody wire:key="dues-{{ $key }}-party-{{ $group['party']?->id ?? 0 }}">
                                <tr class="section-row"><th scope="rowgroup" colspan="9">
                                    @if($group['party'] && auth()->user()->can('parties.view'))
                                        @if($consolidated)<button class="text-link" type="button" wire:click="openStatement({{ $group['party']->id }})" title="{{ __('Switches the company to :company', ['company' => $group['company']->name]) }}">{{ $group['party']->name }}</button>
                                        @else<a class="text-link" href="{{ route('admin.reports.party-statement', ['party' => $group['party']->id]) }}" wire:navigate>{{ $group['party']->name }}</a>@endif
                                    @else{{ $group['party']?->name ?? __('No party') }}@endif
                                    <span class="muted">@if($consolidated) · {{ $group['company']->name }}@endif @if($group['party']?->phone) · {{ $group['party']->phone }}@endif</span>
                                </th></tr>
                                @foreach($group['bills'] as $row)
                                    <tr wire:key="due-{{ $row['entry']->id }}">
                                        <td class="whitespace-nowrap">{{ $row['entry']->number }}</td>
                                        <td class="whitespace-nowrap">{{ $row['entry']->entry_date->format('d M Y') }}</td>
                                        <td>{{ $row['category'] }}@if($row['entry']->description)<p class="muted">{{ $row['entry']->description }}</p>@endif</td>
                                        <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($row['entry']->amount) }}</td>
                                        <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($row['paid']) }}</td>
                                        <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($row['outstanding']) }}</td>
                                        <td class="whitespace-nowrap">{{ $row['entry']->due_date?->format('d M Y') }}</td>
                                        <td class="text-right tabular-nums">@if($row['days_overdue'] > 0)<span class="badge badge-danger">{{ trans_choice('{1} :count day|[2,*] :count days', $row['days_overdue'], ['count' => $row['days_overdue']]) }}</span>@else<span class="muted">{{ __('Not due') }}</span>@endif</td>
                                        <td class="no-print whitespace-nowrap">@can('entries.create')<a class="text-link" href="{{ route('admin.entries.settle', $row['entry']->id) }}" aria-label="{{ $actionLabel }}: {{ $row['entry']->number }}" wire:navigate>{{ $actionLabel }}</a>@endcan</td>
                                    </tr>
                                @endforeach
                                <tr class="total-row"><th scope="row" colspan="5">{{ __('Subtotal') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($group['total']) }}</td><td colspan="3"></td></tr>
                            </tbody>
                        @endforeach
                        <tfoot><tr class="grand-total-row"><th scope="row" colspan="5">{{ __('Total') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($sections[$key]->sum('total')) }}</td><td colspan="3"></td></tr></tfoot>
                    </table></div>
                </section>
            @endif
        @endforeach
    @endif
</div>
