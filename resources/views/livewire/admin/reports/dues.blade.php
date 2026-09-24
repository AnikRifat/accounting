<div class="page">
    <x-page-header :title="__('Dues')" :description="$scopeLabel.' · '.__('Outstanding as of :date', ['date' => $todayLabel]).($overdue ? ' · '.__('Overdue only') : '')" :back="route('admin.reports.index')" :back-label="__('Reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.select name="kind" :label="__('Show')" wire:model.live="kind" :options="$kindOptions" />
                <x-form.select name="party" :label="__('Party')" wire:model.live="party" :options="$partyOptions" />
                <div class="flex min-h-9 items-center"><x-form.checkbox name="overdue" :label="__('Overdue only')" wire:model.live="overdue" /></div>
            </x-toolbar>
        </x-slot:toolbar>
        @if($companyTotals->isEmpty())
            <x-empty-state emoji="🎉" :title="$overdue ? __('Nothing is overdue.') : __('Nothing is outstanding.')" />
        @elseif(! $consolidated)
            <div class="split-stats px-5 pb-4 max-sm:px-4">
                <div><p class="muted">{{ __('Receivable (owed to us)') }}</p><p class="stat-value"><x-money :value="$companyTotals->sum('receivable')" /></p></div>
                <div><p class="muted">{{ __('Payable (we owe)') }}</p><p class="stat-value"><x-money :value="$companyTotals->sum('payable')" /></p></div>
            </div>
        @else
            <x-table :caption="__('Totals by company')" show-caption>
                <x-slot:head><th scope="col">{{ __('Company') }}</th><th scope="col" class="num">{{ __('Receivable') }}</th><th scope="col" class="num">{{ __('Payable') }}</th></x-slot:head>
                @foreach($companyTotals as $row)<tr wire:key="dues-company-{{ $row['company']->id }}"><th scope="row" class="row-label">{{ $row['company']->name }}</th><td class="num"><x-money :value="$row['receivable']" /></td><td class="num"><x-money :value="$row['payable']" /></td></tr>@endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th><td class="num"><x-money :value="$companyTotals->sum('receivable')" /></td><td class="num"><x-money :value="$companyTotals->sum('payable')" /></td></tr></x-slot:foot>
            </x-table>
        @endif

        @if($agingChart !== null)
            <div class="no-print border-t border-slate-100 p-5 bg-slate-50/50">
                <div class="grid grid-cols-1 {{ $topPartiesChart !== null ? 'lg:grid-cols-2' : '' }} gap-6">
                    <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs">
                        <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">{{ __('Aging analysis (days overdue)') }}</h3>
                        <x-chart :type="$agingChart['type']" :data="$agingChart['data']" :options="$agingChart['options']" height="220" />
                    </div>
                    @if($topPartiesChart !== null)
                        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs">
                            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">{{ __('Top parties with outstanding balance') }}</h3>
                            <x-chart :type="$topPartiesChart['type']" :data="$topPartiesChart['data']" :options="$topPartiesChart['options']" :horizontal="true" height="220" />
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </x-card>
    @foreach(['receivable' => [__('Receivables (owed to us)'), __('Receive payment')], 'payable' => [__('Payables (we owe)'), __('Make payment')]] as $key => [$heading, $actionLabel])
        @if($companyTotals->isNotEmpty() && $sections[$key]->isNotEmpty())
            <x-card flush :id="'dues-'.$key" :title="$heading" wire:key="dues-section-{{ $key }}">
                <x-table :caption="$heading" grouped>
                    <x-slot:head><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Category') }}</th><th scope="col" class="num">{{ __('Total') }}</th><th scope="col" class="num">{{ __('Paid') }}</th><th scope="col" class="num">{{ __('Outstanding') }}</th><th scope="col">{{ __('Due date') }}</th><th scope="col" class="num">{{ __('Days overdue') }}</th><th scope="col" class="no-print actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                    @foreach($sections[$key] as $group)
                        <tbody wire:key="dues-{{ $key }}-party-{{ $group['party']?->id ?? 0 }}">
                            <tr class="section-row"><th scope="rowgroup" colspan="9">
                                @if($group['party'] && auth()->user()->can('parties.view'))
                                    @if($consolidated)<button class="text-link" type="button" wire:click="openStatement({{ $group['party']->id }})" title="{{ __('Switches the company to :company', ['company' => $group['company']->name]) }}">{{ $group['party']->name }}</button>
                                    @else<a class="text-link" href="{{ route('admin.reports.party-statement', ['party' => $group['party']->id]) }}" wire:navigate>{{ $group['party']->name }}</a>@endif
                                @else{{ $group['party']?->name ?? __('No party') }}@endif
                                <span class="muted normal-case tracking-normal">@if($consolidated) · {{ $group['company']->name }}@endif @if($group['party']?->phone) · {{ $group['party']->phone }}@endif</span>
                            </th></tr>
                            @foreach($group['bills'] as $row)
                                <tr wire:key="due-{{ $row['entry']->id }}">
                                    <td class="nowrap">{{ $row['entry']->number }}</td>
                                    <td class="nowrap">{{ $row['entry']->entry_date->format('d M Y') }}</td>
                                    <td>{{ $row['category'] }}@if($row['entry']->description)<p class="muted">{{ $row['entry']->description }}</p>@endif</td>
                                    <td class="num"><x-money :value="$row['entry']->amount" /></td>
                                    <td class="num"><x-money :value="$row['paid']" /></td>
                                    <td class="num"><x-money :value="$row['outstanding']" /></td>
                                    <td class="nowrap">{{ $row['entry']->due_date?->format('d M Y') }}</td>
                                    <td class="num">@if($row['days_overdue'] > 0)<x-badge tone="danger">{{ trans_choice('{1} :count day|[2,*] :count days', $row['days_overdue'], ['count' => $row['days_overdue']]) }}</x-badge>@else<span class="muted">{{ __('Not due') }}</span>@endif</td>
                                    <td class="no-print"><div class="row-actions">@can('entries.create')<x-button variant="secondary" size="sm" icon="wallet" :href="route('admin.entries.settle', $row['entry']->id)" :label="$actionLabel.': '.$row['entry']->number">{{ $actionLabel }}</x-button>@endcan</div></td>
                                </tr>
                            @endforeach
                            <tr class="total-row"><th scope="row" colspan="5">{{ __('Subtotal') }}</th><td class="num"><x-money :value="$group['total']" /></td><td colspan="3"></td></tr>
                        </tbody>
                    @endforeach
                    <x-slot:foot><tr class="grand-total-row"><th scope="row" colspan="5">{{ __('Total') }}</th><td class="num"><x-money :value="$sections[$key]->sum('total')" /></td><td colspan="3"></td></tr></x-slot:foot>
                </x-table>
            </x-card>
        @endif
    @endforeach
</div>
