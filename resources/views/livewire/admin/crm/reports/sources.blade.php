<div class="page">
    <x-page-header :title="__('Lead sources')" :description="$scopeLabel.' · '.__('Where leads come from and how they end. The date range limits leads by the day they were added.')" :back="route('admin.crm.reports.index')" :back-label="__('CRM reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar><x-crm.report-toolbar :sees-all="$seesAll" :people="$people" :person-label="__('Assigned to')" /></x-slot:toolbar>
        @if($rows->isEmpty())
            <x-empty-state emoji="📣" :title="__('Nothing to show')" :description="__('No leads match these filters.')" />
        @else
            <x-table :caption="__('Lead sources')">
                <x-slot:head><th scope="col">{{ __('Source') }}</th><th scope="col" class="num">{{ __('Leads') }}</th><th scope="col" class="num">{{ __('Contacted') }}</th><th scope="col" class="num">{{ __('Open') }}</th>@foreach($closedStatuses as $name)<th scope="col" class="num">{{ $name }}</th>@endforeach</x-slot:head>
                @foreach($rows as $row)
                    <tr wire:key="source-{{ $loop->index }}">
                        <th scope="row" class="row-label"><a class="text-link" href="{{ route('admin.crm.leads.index', array_filter(['source' => $row['filter'], 'from' => $from, 'to' => $to, 'assignee' => $seesAll ? $person : ''])) }}" wire:navigate>{{ $row['label'] }}</a></th>
                        <td class="num"><strong>{{ number_format($row['total']) }}</strong></td>
                        <td class="num">{{ number_format($row['contacted']) }} <span class="muted">({{ $row['total'] > 0 ? round($row['contacted'] * 100 / $row['total']) : 0 }}%)</span></td>
                        <td class="num">{{ number_format($row['open']) }}</td>
                        @foreach($closedStatuses as $name)<td class="num">{{ number_format($row['closed'][$name] ?? 0) }}</td>@endforeach
                    </tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th><td class="num">{{ number_format($rows->sum('total')) }}</td><td class="num">{{ number_format($rows->sum('contacted')) }}</td><td class="num">{{ number_format($rows->sum('open')) }}</td>
                    @foreach($closedStatuses as $name)<td class="num">{{ number_format($rows->sum(fn (array $row): int => $row['closed'][$name] ?? 0)) }}</td>@endforeach</tr></x-slot:foot>
            </x-table>
        @endif
    </x-card>
</div>
