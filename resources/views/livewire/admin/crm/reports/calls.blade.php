<div class="page">
    <x-page-header :title="__('Call outcomes')" :description="$scopeLabel.' · '.__('Calls per person by call result, with visits and leads contacted. The date range limits calls by the day they were made.')" :back="route('admin.crm.reports.index')" :back-label="__('CRM reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar><x-crm.report-toolbar :sees-all="$seesAll" :people="$people" :person-label="__('Logged by')" /></x-slot:toolbar>
        @if($rows->isEmpty())
            <x-empty-state emoji="📞" :title="__('Nothing to show')" :description="__('No calls or visits match these filters.')" />
        @else
            <x-table :caption="__('Call outcomes')">
                <x-slot:head><th scope="col">{{ __('Person') }}</th>@foreach($columns as $label)<th scope="col" class="num">{{ $label }}</th>@endforeach<th scope="col" class="num">{{ __('Calls') }}</th><th scope="col" class="num">{{ __('Visits') }}</th><th scope="col" class="num">{{ __('Leads contacted') }}</th></x-slot:head>
                @foreach($rows as $row)
                    <tr wire:key="caller-{{ $loop->index }}">
                        <th scope="row" class="row-label">{{ $row['name'] }}</th>
                        @foreach($columns as $name => $label)<td class="num">{{ number_format($row['counts'][$name] ?? 0) }}</td>@endforeach
                        <td class="num"><strong>{{ number_format($row['calls']) }}</strong></td>
                        <td class="num">{{ number_format($row['visits']) }}</td>
                        <td class="num">{{ number_format($row['leads']) }}</td>
                    </tr>
                @endforeach
                @if($rows->count() > 1)
                    <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th>@foreach($columns as $name => $label)<td class="num">{{ number_format($rows->sum(fn (array $row): int => $row['counts'][$name] ?? 0)) }}</td>@endforeach
                        <td class="num">{{ number_format($rows->sum('calls')) }}</td><td class="num">{{ number_format($rows->sum('visits')) }}</td><td class="muted num">—</td></tr></x-slot:foot>
                @endif
            </x-table>
        @endif
    </x-card>
</div>
