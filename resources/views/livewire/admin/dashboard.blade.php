<div class="page">
    <x-notices />
    @if(! $hasCompanies)
        <x-page-header :title="__('Overview')" />
        <x-card>
            @can('companies.create')
                <x-empty-state emoji="🏢" :title="__('Create your first company')" :description="__('Each company gets its own chart of accounts, cash and bank accounts and parties. Add one to start recording income and expenses.')">
                    <x-button icon="plus" :href="route('admin.companies.create')">{{ __('Create a company') }}</x-button>
                </x-empty-state>
            @else
                <x-empty-state emoji="🔒" :title="__('No company assigned yet')" :description="__('Ask your administrator to assign you a company. Its figures will appear here once you have access.')" />
            @endcan
        </x-card>
    @else
        <x-page-header :title="__('Overview')" :description="$scopeLabel.' · '.__('Posted entries only; voided entries never count.')" />

        @can('entries.create')
            <div class="quick-actions">
                @foreach([
                    'income' => ['💰', __('Record income'), __('Sales, fees and other money in'), 'success'],
                    'expense' => ['🧾', __('Record expense'), __('Bills, salaries and other money out'), 'danger'],
                ] as $type => [$emoji, $label, $hint, $tone])
                    <a class="card quick-action quick-action-{{ $tone }}" href="{{ route('admin.entries.index', ['sheet' => 'create:'.$type]) }}" wire:navigate wire:key="quick-{{ $type }}"><span class="quick-action-emoji" aria-hidden="true">{{ $emoji }}</span><span><strong>{{ $label }}</strong><span class="muted">{{ $hint }}</span></span></a>
                @endforeach
            </div>
        @endcan

        @if($months !== null || $activeEmployees !== null)
            <div class="stats">
                @if($months !== null)
                    @php
                        [$previous, $current] = array_slice($months, -2);
                    @endphp
                    @foreach([
                        'income' => [__('Income this month'), '📈', 'success'],
                        'expense' => [__('Expenses this month'), '📉', 'danger'],
                        'net' => [__('Net this month'), '⚖️', 'info'],
                    ] as $key => [$label, $emoji, $tone])
                        @php
                            $now = $key === 'net' ? $current['income'] - $current['expense'] : $current[$key];
                            $before = $key === 'net' ? $previous['income'] - $previous['expense'] : $previous[$key];
                        @endphp
                        <x-stat wire:key="kpi-{{ $key }}" :label="$label" :emoji="$emoji" :tone="$tone" :hint="__('Last month (:month): :amount', ['month' => $previous['label'], 'amount' => \App\Support\Money::format($before)])">
                            <x-slot:value><x-money :value="$now" :signed="$key === 'net'" /></x-slot:value>
                        </x-stat>
                    @endforeach
                @endif
                @if($activeEmployees !== null)
                    <x-stat :label="__('Active employees')" emoji="👥" :value="$activeEmployees" :href="auth()->user()->can('users.view') ? route('admin.users.index') : null" />
                @endif
            </div>
        @endif

        @if($months !== null)
            <x-card id="trend" :title="__('Income and expenses, last :count months', ['count' => count($months)])">
                <x-slot:actions>
                    <p class="chart-legend"><span><span class="chart-swatch chart-income" aria-hidden="true"></span>{{ __('Income') }}</span><span><span class="chart-swatch chart-expense" aria-hidden="true"></span>{{ __('Expenses') }}</span></p>
                </x-slot:actions>
                @if($chartMax === 0)
                    <x-empty-state emoji="📊" :title="__('No activity yet')" :description="__('No income or expense was posted in these months.')" />
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
                <details class="disclosure"><summary>{{ __('View as table') }}</summary>
                    <div class="card mt-3 overflow-hidden">
                        <x-table :caption="__('Income and expenses by month')">
                            <x-slot:head><th scope="col">{{ __('Month') }}</th><th scope="col" class="num">{{ __('Income') }}</th><th scope="col" class="num">{{ __('Expenses') }}</th><th scope="col" class="num">{{ __('Net') }}</th></x-slot:head>
                            @foreach($months as $month)
                                <tr wire:key="trend-row-{{ $loop->index }}"><th scope="row">{{ $month['label'] }}</th><td class="num"><x-money :value="$month['income']" /></td><td class="num"><x-money :value="$month['expense']" /></td><td class="num"><x-money :value="$month['income'] - $month['expense']" signed /></td></tr>
                            @endforeach
                        </x-table>
                    </div>
                </details>
            </x-card>
        @endif

        @if($dues !== null)
            <x-card id="dues" :title="__('Dues')" flush>
                <x-slot:actions>@can('reports.view')<x-button variant="secondary" size="sm" :href="route('admin.reports.dues')">{{ __('Dues report') }}</x-button>@endcan</x-slot:actions>
                <div class="split-stats px-5 pb-4 max-sm:px-4">
                    <div><p class="muted">{{ __('Receivable (owed to us)') }}</p><p class="stat-value"><x-money :value="$dues['receivable']" /></p></div>
                    <div><p class="muted">{{ __('Payable (we owe)') }}</p><p class="stat-value"><x-money :value="$dues['payable']" /></p></div>
                    <div><p class="muted">{{ __('Overdue bills') }}</p><p class="stat-value">{{ $dues['overdue'] }}</p>
                        @if($dues['overdue'] > 0)<a class="text-link" href="{{ auth()->user()->can('reports.view') ? route('admin.reports.dues', ['overdue' => 1]) : route('admin.entries.index', ['status' => 'overdue']) }}" wire:navigate>{{ __('See overdue') }}</a>@endif</div>
                </div>
                @if($dues['next']->isNotEmpty())
                    <x-table :caption="__('Next dues')" show-caption>
                        <x-slot:head><th scope="col">{{ __('Due date') }}</th><th scope="col">{{ __('Party') }}</th><th scope="col">{{ __('Entry') }}</th><th scope="col" class="num">{{ __('Outstanding') }}</th></x-slot:head>
                        @foreach($dues['next'] as $bill)
                            <tr wire:key="next-due-{{ $bill->id }}">
                                <td class="nowrap">{{ $bill->due_date?->format('d M Y') }}@if($bill->dueStatus() === \App\Enums\DueStatus::Overdue) <x-badge tone="danger">{{ __('Overdue') }}</x-badge>@endif</td>
                                <td>{{ $bill->party?->name }}@if($consolidated)<p class="muted">{{ $bill->company->name }}</p>@endif</td>
                                <td>{{ $bill->number }}<p class="muted">{{ $bill->type === \App\Enums\EntryType::Income ? __('Receivable') : __('Payable') }}</p></td>
                                <td class="num"><x-money :value="(int) $bill->outstanding" /></td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </x-card>
        @endif

        <div class="grid-2">
            @if($cash !== null)
                <x-card id="cash" :title="__('Cash position')" flush>
                    @if($cash['companies'] === [])
                        <x-empty-state emoji="💳" :title="__('No payment methods yet.')" />
                    @else
                        <x-table :caption="__('Cash position by payment method')" grouped>
                            <x-slot:head><th scope="col">{{ __('Account') }}</th><th scope="col" class="num">{{ __('Balance') }}</th></x-slot:head>
                            @foreach($cash['companies'] as $group)
                                <tbody wire:key="cash-{{ $group['company']->id }}">
                                    @if($consolidated)<tr class="section-row"><th scope="rowgroup" colspan="2">{{ $group['company']->name }}</th></tr>@endif
                                    @foreach($group['accounts'] as $row)
                                        <tr wire:key="cash-account-{{ $row['account']->id }}"><th scope="row" class="row-label">{{ $row['account']->name }}@if($row['account']->payment_type)<p class="muted">{{ $row['account']->payment_type->label() }}</p>@endif</th><td class="num"><x-money :value="$row['balance']" /></td></tr>
                                    @endforeach
                                    @if(count($cash['companies']) > 1)<tr class="total-row"><th scope="row">{{ __(':company total', ['company' => $group['company']->code]) }}</th><td class="num"><x-money :value="$group['total']" /></td></tr>@endif
                                </tbody>
                            @endforeach
                            <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th><td class="num"><x-money :value="$cash['total']" /></td></tr></x-slot:foot>
                        </x-table>
                    @endif
                </x-card>
            @endif
            @if($recent !== null)
                <x-card id="recent" :title="__('Recent entries')" flush>
                    <x-slot:actions><x-button variant="secondary" size="sm" :href="route('admin.entries.index')">{{ __('All transactions') }}</x-button></x-slot:actions>
                    @if($recent->isEmpty())
                        <x-empty-state emoji="🧾" :title="__('No entries yet.')" />
                    @else
                        <x-table :caption="__('Recent entries')">
                            <x-slot:head><th scope="col">{{ __('Entry') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col" class="num">{{ __('Amount') }}</th></x-slot:head>
                            @foreach($recent as $entry)
                                <tr wire:key="recent-{{ $entry->id }}">
                                    <td><strong>{{ $entry->number }}</strong><p class="muted">{{ $entry->entry_date->format('d M Y') }}@if($consolidated) · {{ $entry->company->name }}@endif @if($entry->party) · {{ $entry->party->name }}@endif</p>@if($entry->description)<p class="muted">{{ $entry->description }}</p>@endif</td>
                                    <td><x-badge.entry-type :type="$entry->type" /></td>
                                    <td class="num"><x-money :value="$entry->amount" /></td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            @endif
        </div>
    @endif
</div>
