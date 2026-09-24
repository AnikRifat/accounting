<div>
    <div class="page-header"><div><p class="eyebrow"><a href="{{ route('admin.reports.index') }}" wire:navigate>{{ __('Reports') }}</a></p><h1>{{ __('Employee cost') }}</h1><p class="muted">{{ $scopeLabel }}@if($periodLabel) · {{ $periodLabel }}@endif</p></div>
        <button class="btn btn-secondary no-print" type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    <div class="panel no-print mb-6">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-form.select name="company" :label="__('Company')" wire:model.live="company" :options="$companyOptions" />
            <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
            <x-form.input name="from" :label="__('From date')" type="date" wire:model.live="from" />
            <x-form.input name="to" :label="__('To date')" type="date" wire:model.live="to" />
        </div>
    </div>
    <div class="panel stack">
        <p class="muted">{{ __('Posted expenses recorded against each employee, such as salaries. Paid and outstanding are as of today. Employees with no expenses in the period are not listed.') }}</p>
        @if($rows->isEmpty())
            <p class="muted">{{ __('No employee expenses were posted in this period.') }}</p>
        @else
            <div class="table-wrap"><table class="report-table"><caption class="sr-only">{{ __('Employee cost') }}</caption>
                <thead><tr><th scope="col">{{ __('Employee') }}</th><th scope="col">{{ __('Company') }}</th><th scope="col" class="text-right">{{ __('Entries') }}</th><th scope="col" class="text-right">{{ __('Total') }}</th><th scope="col" class="text-right">{{ __('Paid') }}</th><th scope="col" class="text-right">{{ __('Outstanding') }}</th><th scope="col" class="no-print">{{ __('Transactions') }}</th></tr></thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr wire:key="employee-party-{{ $row['party']->id }}">
                            <th scope="row" class="row-label">{{ $row['party']->name }}<p class="muted">{{ $row['party']->employee?->employee_code }}@if($row['party']->employee?->designation) · {{ $row['party']->employee->designation }}@endif</p></th>
                            <td>{{ $row['party']->company->name }}</td>
                            <td class="text-right tabular-nums">{{ $row['count'] }}</td>
                            <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($row['total']) }}</td>
                            <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($row['paid']) }}</td>
                            <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($row['outstanding']) }}</td>
                            <td class="no-print">@can('entries.view')<a class="text-link" href="{{ route('admin.entries.index', ['company' => $row['party']->company_id, 'party' => $row['party']->id, 'type' => 'expense', 'from' => $range[0], 'to' => $range[1]]) }}" aria-label="{{ __('Transactions of :name', ['name' => $row['party']->name]) }}" wire:navigate>{{ __('View') }}</a>@endcan</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr class="grand-total-row"><th scope="row" colspan="2">{{ __('Total') }}</th><td class="text-right tabular-nums">{{ $rows->sum('count') }}</td>
                    <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($rows->sum('total')) }}</td>
                    <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($rows->sum('paid')) }}</td>
                    <td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($rows->sum('outstanding')) }}</td><td class="no-print"></td></tr></tfoot>
            </table></div>
        @endif
    </div>
</div>
