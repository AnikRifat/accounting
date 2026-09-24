<div class="page">
    <x-notices />
    @if(! $hasCompanies)
        <x-page-header :title="__('Dashboard')" />
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
        <x-page-header :title="__('Dashboard')" :description="$scopeLabel.' · '.__('Posted entries only; voided entries never count.')" />

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
            <x-card id="trend" :title="__('Cash flow & trend, last :count months', ['count' => count($months)])">
                <x-slot:actions>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm {{ $trendMonths === 6 ? 'btn-primary' : 'btn-secondary' }}" wire:click="setTrendMonths(6)">6M</button>
                        <button type="button" class="btn btn-sm {{ $trendMonths === 12 ? 'btn-primary' : 'btn-secondary' }}" wire:click="setTrendMonths(12)">12M</button>
                    </div>
                </x-slot:actions>
                @if($chartMax === 0)
                    <x-empty-state emoji="📊" :title="__('No activity yet')" :description="__('No income or expense was posted in these months.')" />
                @else
                    <div class="pt-2 pb-4">
                        <x-chart :data="$cashFlowChart['data']" :options="$cashFlowChart['options']" height="300" />
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

        <div class="grid-2">
            @if($expenseBreakdown !== null)
                <x-card id="expenses" :title="__('Expense breakdown')" :description="__('Top cost drivers for the selected :count months period', ['count' => $trendMonths])">
                    <x-slot:actions>
                        <span class="badge badge-subtle font-mono text-xs">{{ __('Total:') }} <x-money :value="$expenseBreakdown['total']" /></span>
                    </x-slot:actions>
                    <div class="flex flex-col sm:flex-row items-center gap-6 py-2">
                        <div class="w-48 h-48 shrink-0 relative flex items-center justify-center">
                            <x-chart type="doughnut" :data="$expenseBreakdown['chart']['data']" :options="$expenseBreakdown['chart']['options']" height="190" />
                        </div>
                        <div class="flex-1 w-full space-y-2.5">
                            @foreach($expenseBreakdown['items'] as $item)
                                <div class="flex items-center justify-between text-xs" wire:key="exp-item-{{ $loop->index }}">
                                    <div class="flex items-center gap-2 truncate pr-2">
                                        <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $item['color'] }}"></span>
                                        <span class="font-medium text-slate-800 truncate" title="{{ $item['name'] }}">{{ $item['name'] }}</span>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="font-semibold text-slate-900"><x-money :value="$item['amount']" /></span>
                                        <span class="text-slate-400 text-[11px] ml-1">({{ $item['percent'] }}%)</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
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

                    @if(!empty($dues['aging']['receivable']) && ($dues['receivable'] > 0 || $dues['payable'] > 0))
                        <div class="px-5 pb-4 max-sm:px-4 border-t border-slate-100 pt-3">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">{{ __('Receivables aging breakdown') }}</p>
                            <div class="grid grid-cols-4 gap-2 text-center text-xs">
                                <div class="bg-slate-50 rounded-lg p-2">
                                    <span class="text-[11px] text-slate-500 block">{{ __('Current') }}</span>
                                    <span class="font-medium text-slate-900"><x-money :value="$dues['aging']['receivable']['current']" /></span>
                                </div>
                                <div class="bg-amber-50 rounded-lg p-2">
                                    <span class="text-[11px] text-amber-700 block">1–30d</span>
                                    <span class="font-medium text-amber-900"><x-money :value="$dues['aging']['receivable']['overdue_1_30']" /></span>
                                </div>
                                <div class="bg-orange-50 rounded-lg p-2">
                                    <span class="text-[11px] text-orange-700 block">31–60d</span>
                                    <span class="font-medium text-orange-900"><x-money :value="$dues['aging']['receivable']['overdue_31_60']" /></span>
                                </div>
                                <div class="bg-rose-50 rounded-lg p-2">
                                    <span class="text-[11px] text-rose-700 block">60d+</span>
                                    <span class="font-medium text-rose-900"><x-money :value="$dues['aging']['receivable']['overdue_60_plus']" /></span>
                                </div>
                            </div>
                        </div>
                    @endif

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
        </div>

        <div class="grid-2 mt-6">
            @if($cash !== null)
                <x-card id="cash" :title="__('Cash position')" flush>
                    @if(!empty($cash['byType']) && $cash['total'] !== 0)
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 px-5 pt-3 pb-3 border-b border-slate-100 max-sm:px-4">
                            @foreach($cash['byType'] as $kind => $info)
                                @if($info['amount'] !== 0)
                                    <div class="bg-slate-50/80 rounded-lg p-2 text-xs" wire:key="liquidity-{{ $kind }}">
                                        <div class="flex items-center gap-1.5 text-slate-500 mb-0.5">
                                            <span>{{ $info['icon'] }}</span>
                                            <span class="truncate">{{ $info['label'] }}</span>
                                        </div>
                                        <div class="font-semibold text-slate-900">
                                            <x-money :value="$info['amount']" />
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
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
