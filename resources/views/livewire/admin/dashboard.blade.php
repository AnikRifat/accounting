<div>
    <x-notices />
    @if(! $hasCompanies)
        <div class="page-header"><div><p class="eyebrow">{{ __('Workspace') }}</p><h1>{{ __('Overview') }}</h1></div></div>
        <div class="panel stack empty-state">
            @can('companies.create')
                <h2>{{ __('Create your first company') }}</h2>
                <p class="muted">{{ __('Each company gets its own chart of accounts, cash and bank accounts and employees. Add one to start recording income and expenses.') }}</p>
                <div><a class="btn" href="{{ route('admin.companies.create') }}" wire:navigate>{{ __('Create a company') }}</a></div>
            @else
                <h2>{{ __('No company assigned yet') }}</h2>
                <p class="muted">{{ __('Ask your administrator to assign you a company. Its figures will appear here once you have access.') }}</p>
            @endcan
        </div>
    @else
        <div class="page-header"><div><p class="eyebrow">{{ __('Workspace') }}</p><h1>{{ __('Overview') }}</h1><p class="muted">{{ $scopeLabel }} · {{ __('Posted entries only; voided entries never count.') }}</p></div></div>
        @can('entries.create')
            <div class="flex flex-wrap gap-3 mb-6">
                <a class="btn" href="{{ route('admin.entries.create', 'income') }}" wire:navigate>{{ __('Record income') }}</a>
                <a class="btn" href="{{ route('admin.entries.create', 'expense') }}" wire:navigate>{{ __('Record expense') }}</a>
                <a class="btn btn-secondary" href="{{ route('admin.entries.create', 'transfer') }}" wire:navigate>{{ __('Record transfer') }}</a>
            </div>
        @endcan
        @if($months !== null || $activeEmployees !== null)
            <div class="stats">
                @if($months !== null)
                    @php
                        [$previous, $current] = array_slice($months, -2);
                    @endphp
                    @foreach([__('Income this month') => 'income', __('Expenses this month') => 'expense', __('Net this month') => 'net'] as $label => $key)
                        @php
                            $now = $key === 'net' ? $current['income'] - $current['expense'] : $current[$key];
                            $before = $key === 'net' ? $previous['income'] - $previous['expense'] : $previous[$key];
                        @endphp
                        <div class="panel" wire:key="kpi-{{ $key }}"><p class="muted">{{ $label }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($now) }}</p>
                            <p class="muted">{{ __('Last month (:month): :amount', ['month' => $previous['label'], 'amount' => \App\Support\Money::format($before)]) }}</p></div>
                    @endforeach
                @endif
                @if($activeEmployees !== null)
                    <div class="panel"><p class="muted">{{ __('Active employees') }}</p><p class="stat-value text-2xl tabular-nums">{{ $activeEmployees }}</p></div>
                @endif
            </div>
        @endif
        @if($months !== null)
            <section class="panel mb-6" aria-labelledby="trend-heading">
                <div class="flex flex-wrap items-baseline justify-between gap-3"><h2 id="trend-heading">{{ __('Income and expenses, last :count months', ['count' => count($months)]) }}</h2>
                    <p class="chart-legend"><span><span class="chart-swatch chart-income" aria-hidden="true"></span>{{ __('Income') }}</span><span><span class="chart-swatch chart-expense" aria-hidden="true"></span>{{ __('Expenses') }}</span></p>
                </div>
                @if($chartMax === 0)
                    <p class="muted">{{ __('No income or expense was posted in these months.') }}</p>
                @else
                    <div class="chart" aria-hidden="true">
                        <div class="chart-axis">
                            @foreach([0, 50, 100] as $percent)<span style="bottom: {{ $percent }}%">{{ preg_replace('/\.00$/', '', \App\Support\Money::format(intdiv($chartMax * $percent, 100))) }}</span>@endforeach
                        </div>
                        <div class="chart-plot">
                            <span class="chart-grid" style="bottom: 50%"></span><span class="chart-grid" style="bottom: 100%"></span>
                            @foreach($months as $month)
                                <div class="chart-group" wire:key="bar-{{ $loop->index }}">
                                    @foreach(['income' => __('Income'), 'expense' => __('Expenses')] as $series => $seriesLabel)
                                        <span class="chart-bar chart-{{ $series }}" style="height: {{ round($month[$series] * 100 / $chartMax, 2) }}%" title="{{ $month['label'] }} · {{ $seriesLabel }}: {{ \App\Support\Money::format($month[$series]) }}"></span>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <div></div>
                        <div class="chart-labels">@foreach($months as $month)<span wire:key="label-{{ $loop->index }}">{{ $month['short'] }}</span>@endforeach</div>
                    </div>
                @endif
                <details class="mt-4"><summary class="text-link">{{ __('View as table') }}</summary>
                    <div class="table-wrap mt-4"><table><caption class="sr-only">{{ __('Income and expenses by month') }}</caption>
                        <thead><tr><th scope="col">{{ __('Month') }}</th><th scope="col" class="text-right">{{ __('Income') }}</th><th scope="col" class="text-right">{{ __('Expenses') }}</th><th scope="col" class="text-right">{{ __('Net') }}</th></tr></thead>
                        <tbody>@foreach($months as $month)<tr wire:key="trend-row-{{ $loop->index }}"><th scope="row">{{ $month['label'] }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($month['income']) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($month['expense']) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($month['income'] - $month['expense']) }}</td></tr>@endforeach</tbody>
                    </table></div>
                </details>
            </section>
        @endif
        @if($dues !== null)
            <section class="panel mb-6" aria-labelledby="dues-heading">
                <div class="flex flex-wrap items-baseline justify-between gap-3"><h2 id="dues-heading">{{ __('Dues') }}</h2>
                    @can('reports.view')<a class="text-link" href="{{ route('admin.reports.dues') }}" wire:navigate>{{ __('Dues report') }}</a>@endcan</div>
                <div class="due-stats">
                    <div><p class="muted">{{ __('Receivable (owed to us)') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($dues['receivable']) }}</p></div>
                    <div><p class="muted">{{ __('Payable (we owe)') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($dues['payable']) }}</p></div>
                    <div><p class="muted">{{ __('Overdue bills') }}</p><p class="stat-value text-2xl tabular-nums">{{ $dues['overdue'] }}</p>
                        @if($dues['overdue'] > 0)<a class="text-link" href="{{ auth()->user()->can('reports.view') ? route('admin.reports.dues', ['overdue' => 1]) : route('admin.entries.index', ['status' => 'overdue']) }}" wire:navigate>{{ __('See overdue') }}</a>@endif</div>
                </div>
                @if($dues['next']->isNotEmpty())
                    <div class="table-wrap mt-4"><table><caption class="table-caption">{{ __('Next dues') }}</caption>
                        <thead><tr><th scope="col">{{ __('Due date') }}</th><th scope="col">{{ __('Party') }}</th><th scope="col">{{ __('Entry') }}</th><th scope="col" class="text-right">{{ __('Outstanding') }}</th></tr></thead>
                        <tbody>@foreach($dues['next'] as $bill)
                            <tr wire:key="next-due-{{ $bill->id }}"><td class="whitespace-nowrap">{{ $bill->due_date?->format('d M Y') }}@if($bill->dueStatus() === \App\Enums\DueStatus::Overdue) <span class="badge badge-danger">{{ __('Overdue') }}</span>@endif</td>
                                <td>{{ $bill->party?->name }}@if($consolidated)<p class="muted">{{ $bill->company->name }}</p>@endif</td>
                                <td>{{ $bill->number }}<p class="muted">{{ $bill->type === \App\Enums\EntryType::Income ? __('Receivable') : __('Payable') }}</p></td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format((int) $bill->outstanding) }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @endif
            </section>
        @endif
        <div class="dashboard-columns">
            @if($cash !== null)
                <section class="panel" aria-labelledby="cash-heading"><h2 id="cash-heading">{{ __('Cash position') }}</h2>
                    @if($cash['companies'] === [])
                        <p class="muted">{{ __('No payment methods yet.') }}</p>
                    @else
                        <div class="table-wrap"><table><caption class="sr-only">{{ __('Cash position by payment method') }}</caption>
                            <thead><tr><th scope="col">{{ __('Account') }}</th><th scope="col" class="text-right">{{ __('Balance') }}</th></tr></thead>
                            @foreach($cash['companies'] as $group)
                                <tbody wire:key="cash-{{ $group['company']->id }}">
                                    @if($consolidated)<tr class="section-row"><th scope="rowgroup" colspan="2">{{ $group['company']->name }}</th></tr>@endif
                                    @foreach($group['accounts'] as $row)<tr wire:key="cash-account-{{ $row['account']->id }}"><th scope="row" class="row-label">{{ $row['account']->name }}@if($row['account']->payment_type)<p class="muted">{{ $row['account']->payment_type->label() }}</p>@endif</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($row['balance']) }}</td></tr>@endforeach
                                    @if(count($cash['companies']) > 1)<tr class="total-row"><th scope="row">{{ __(':company total', ['company' => $group['company']->code]) }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($group['total']) }}</td></tr>@endif
                                </tbody>
                            @endforeach
                            <tfoot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th><td class="text-right tabular-nums">{{ \App\Support\Money::format($cash['total']) }}</td></tr></tfoot>
                        </table></div>
                    @endif
                </section>
            @endif
            @if($recent !== null)
                <section class="panel" aria-labelledby="recent-heading">
                    <div class="flex items-baseline justify-between gap-3"><h2 id="recent-heading">{{ __('Recent entries') }}</h2><a class="text-link" href="{{ route('admin.entries.index') }}" wire:navigate>{{ __('All transactions') }}</a></div>
                    @if($recent->isEmpty())
                        <p class="muted">{{ __('No entries yet.') }}</p>
                    @else
                        <div class="table-wrap"><table><caption class="sr-only">{{ __('Recent entries') }}</caption>
                            <thead><tr><th scope="col">{{ __('Entry') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col" class="text-right">{{ __('Amount') }}</th></tr></thead>
                            <tbody>@foreach($recent as $entry)
                                <tr wire:key="recent-{{ $entry->id }}"><td><strong>{{ $entry->number }}</strong><p class="muted">{{ $entry->entry_date->format('d M Y') }}@if($consolidated) · {{ $entry->company->name }}@endif @if($entry->party) · {{ $entry->party->name }}@endif</p>@if($entry->description)<p class="muted">{{ $entry->description }}</p>@endif</td>
                                    <td>{{ $entry->type->label() }}</td><td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($entry->amount) }}</td></tr>
                            @endforeach</tbody>
                        </table></div>
                    @endif
                </section>
            @endif
        </div>
    @endif
</div>
