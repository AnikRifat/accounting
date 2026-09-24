<div class="page">
    <x-page-header :title="__('Employee cost')" :description="$scopeLabel.($periodLabel ? ' · '.$periodLabel : '')" :back="route('admin.reports.index')" :back-label="__('Reports')">
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
        <p class="muted px-5 pb-3 max-sm:px-4">{{ __('Posted expenses recorded against each employee, such as salaries. Paid and outstanding are as of today. Employees with no expenses in the period are not listed.') }}</p>
        @if($rows->isEmpty())
            <x-empty-state emoji="👥" :title="__('Nothing to show')" :description="__('No employee expenses were posted in this period.')" />
        @else
            <x-table :caption="__('Employee cost')">
                <x-slot:head><th scope="col">{{ __('Employee') }}</th>@if($consolidated)<th scope="col">{{ __('Company') }}</th>@endif<th scope="col" class="num">{{ __('Entries') }}</th><th scope="col" class="num">{{ __('Total') }}</th><th scope="col" class="num">{{ __('Paid') }}</th><th scope="col" class="num">{{ __('Outstanding') }}</th><th scope="col" class="no-print actions-col"><span class="sr-only">{{ __('Transactions') }}</span></th></x-slot:head>
                @foreach($rows as $row)
                    <tr wire:key="employee-party-{{ $row['party']->id }}">
                        <th scope="row" class="row-label">{{ $row['party']->name }}<p class="muted">{{ $row['party']->user?->employee_code }}@if($row['party']->user?->designation)@if($row['party']->user->employee_code) · @endif{{ $row['party']->user->designation }}@endif</p></th>
                        @if($consolidated)<td>{{ $row['party']->company->name }}</td>@endif
                        <td class="num">{{ $row['count'] }}</td>
                        <td class="num"><x-money :value="$row['total']" /></td>
                        <td class="num"><x-money :value="$row['paid']" /></td>
                        <td class="num"><x-money :value="$row['outstanding']" /></td>
                        <td class="no-print"><div class="row-actions">@can('entries.view')<x-button variant="ghost" size="sm" icon="eye" :href="route('admin.entries.index', ['party' => $row['party']->id, 'type' => 'expense', 'from' => $range[0], 'to' => $range[1]])" :label="__('Transactions of :name', ['name' => $row['party']->name])">{{ __('View') }}</x-button>@endcan</div></td>
                    </tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row" colspan="{{ $consolidated ? 2 : 1 }}">{{ __('Total') }}</th><td class="num">{{ $rows->sum('count') }}</td>
                    <td class="num"><x-money :value="$rows->sum('total')" /></td>
                    <td class="num"><x-money :value="$rows->sum('paid')" /></td>
                    <td class="num"><x-money :value="$rows->sum('outstanding')" /></td><td class="no-print"></td></tr></x-slot:foot>
            </x-table>
        @endif
    </x-card>
</div>
