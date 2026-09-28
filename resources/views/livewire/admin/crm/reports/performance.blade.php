<div class="page">
    <x-page-header :title="__('Team performance')" :description="__('Leads assigned to each person by their current status, overdue follow-ups, and the calls and visits they logged. For lead and call lists use the filters and export on Leads and Call log.')" />
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.date-range id="performance-range" :label="__('Date range')" :placeholder="__('All time')" />
                <x-slot:actions><x-table.export :columns="$this->tableColumns()" /></x-slot:actions>
            </x-toolbar>
        </x-slot:toolbar>
        <x-table :caption="__('Team performance')">
            <x-slot:head>
                <th>{{ __('Person') }}</th><th class="num">{{ __('Leads') }}</th>
                @foreach($statuses as $name)<th class="num">{{ $name }}</th>@endforeach
                <th class="num">{{ __('Overdue') }}</th><th class="num">{{ __('Calls') }}</th><th class="num">{{ __('Visits') }}</th>
            </x-slot:head>
            @forelse($rows as $row)
                <tr wire:key="person-{{ $row->id }}">
                    <th scope="row" class="row-label">{{ $row->name }}</th>
                    <td class="num"><a class="text-link" href="{{ route('admin.crm.leads.index', array_filter(['assignee' => $showUnassigned ? $row->id : null, 'from' => $from, 'to' => $to])) }}" wire:navigate>{{ number_format($row->leads_count) }}</a></td>
                    @foreach(array_keys($statuses) as $index => $name)<td class="num">{{ number_format($row->{'status_'.$index.'_count'}) }}</td>@endforeach
                    <td class="num">@if($row->overdue_count > 0)<x-badge tone="danger">{{ number_format($row->overdue_count) }}</x-badge>@else 0 @endif</td>
                    <td class="num">{{ number_format($row->calls_count) }}</td>
                    <td class="num">{{ number_format($row->visits_count) }}</td>
                </tr>
            @empty
                <x-table.empty :colspan="count($statuses) + 5" emoji="🏆">{{ __('Nobody works in the CRM for these companies yet.') }}</x-table.empty>
            @endforelse
            @if($rows->count() > 1)
                <x-slot:foot><tr class="grand-total-row"><th scope="row">{{ __('Total') }}</th><td class="num">{{ number_format($rows->sum('leads_count')) }}</td>
                    @foreach(array_keys($statuses) as $index => $name)<td class="num">{{ number_format($rows->sum('status_'.$index.'_count')) }}</td>@endforeach
                    <td class="num">{{ number_format($rows->sum('overdue_count')) }}</td><td class="num">{{ number_format($rows->sum('calls_count')) }}</td><td class="num">{{ number_format($rows->sum('visits_count')) }}</td></tr></x-slot:foot>
            @endif
        </x-table>
    </x-card>
</div>
