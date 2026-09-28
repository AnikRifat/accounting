<div class="page">
    <x-page-header :title="__('Lead pipeline')" :description="$scopeLabel.' · '.__('Leads by service and current status. The date range limits leads by the day they were added.')" :back="route('admin.crm.reports.index')" :back-label="__('CRM reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar><x-crm.report-toolbar :sees-all="$seesAll" :people="$people" :person-label="__('Assigned to')" /></x-slot:toolbar>
        @if($rows->isEmpty())
            <x-empty-state emoji="🧭" :title="__('Nothing to show')" :description="__('No leads match these filters.')" />
        @else
            <x-table :caption="__('Lead pipeline')">
                <x-slot:head><th scope="col">{{ __('Service') }}</th>@foreach($statuses as $name)<th scope="col" class="num">{{ $name }}</th>@endforeach<th scope="col" class="num">{{ __('Total') }}</th></x-slot:head>
                @foreach($rows as $row)
                    <tr wire:key="pipeline-{{ $loop->index }}">
                        <th scope="row" class="row-label">@if($row['service'])<a class="text-link" href="{{ route('admin.crm.leads.index', array_filter(['service' => $row['service'], 'from' => $from, 'to' => $to, 'assignee' => $seesAll ? $person : ''])) }}" wire:navigate>{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif</th>
                        @foreach($statuses as $name)<td class="num">{{ number_format($row['counts'][$name] ?? 0) }}</td>@endforeach
                        <td class="num"><strong>{{ number_format($row['total']) }}</strong></td>
                    </tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th>@foreach($statuses as $name)<td class="num">{{ number_format($totals[$name]) }}</td>@endforeach<td class="num">{{ number_format($rows->sum('total')) }}</td></tr></x-slot:foot>
            </x-table>
        @endif
    </x-card>
</div>
