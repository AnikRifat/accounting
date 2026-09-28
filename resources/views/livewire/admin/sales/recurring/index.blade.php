<div class="page">
    <x-notices />
    <x-page-header :title="__('Recurring invoices')" :description="__('Schedules that copy an invoice into a new draft every week, month, quarter or year. Drafts are created each morning for you to check and issue.')" :back="route('admin.sales.dashboard')" :back-label="__('Sales')">
        @can('sales.update')
            <x-slot:actions>
                <x-button variant="secondary" icon="check" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">{{ __('Generate due now') }}</x-button>
                <x-button icon="plus" :href="route('admin.sales.recurring.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add schedule') }}</x-button>
            </x-slot:actions>
        @endcan
    </x-page-header>
    @if($skipped !== [])
        <x-alert tone="warning" :title="__('Some schedules were skipped')">
            <ul>@foreach($skipped as $skip)<li wire:key="skip-{{ $loop->index }}"><strong>{{ $skip['schedule'] }}</strong>: {{ $skip['reason'] }}</li>@endforeach</ul>
        </x-alert>
    @endif
    <x-card flush>
        <x-table :caption="__('Recurring invoices')">
            <x-slot:head><th>{{ __('Schedule') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Invoice') }}</th><th>{{ __('Repeats') }}</th><th>{{ __('Next run') }}</th><th>{{ __('Last run') }}</th><th class="num">{{ __('Drafts made') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($schedules as $schedule)
                <tr wire:key="schedule-{{ $schedule->id }}">
                    <td><strong>{{ $schedule->name }}</strong>@if($schedule->ends_on)<p class="muted">{{ __('Until :date', ['date' => $schedule->ends_on->format('d M Y')]) }}</p>@endif</td>
                    @if($showCompany)<td>{{ $schedule->company->name }}</td>@endif
                    <td><a class="text-link" href="{{ route('admin.sales.documents.show', $schedule->source_id) }}" wire:navigate>{{ $schedule->source?->number ?? __('Draft #:id', ['id' => $schedule->source_id]) }}</a><p class="muted">{{ $schedule->source?->party?->name }}</p></td>
                    <td>{{ $schedule->frequency->label() }}@if($schedule->frequency !== \App\Enums\RecurringFrequency::Weekly)<p class="muted">{{ __('Day :day', ['day' => $schedule->day]) }}</p>@endif</td>
                    <td class="nowrap">{{ $schedule->is_active ? $schedule->next_run_on?->format('d M Y') : '—' }}</td>
                    <td class="nowrap">{{ $schedule->last_run_on?->format('d M Y') ?? '—' }}</td>
                    <td class="num">@if($schedule->documents_count > 0)<a class="text-link" href="{{ route('admin.sales.invoices.index', ['recurring' => $schedule->id]) }}" wire:navigate>{{ $schedule->documents_count }}</a>@else 0 @endif</td>
                    <td><x-badge.active :active="$schedule->is_active" /></td>
                    <td><div class="row-actions">
                        @can('sales.update')
                            <x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.sales.recurring.edit', $schedule)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $schedule->id }}')" :label="__('Edit :name', ['name' => $schedule->name])">{{ __('Edit') }}</x-button>
                            <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $schedule->id }})" wire:confirm="{{ __('Delete :name? The invoices it created stay.', ['name' => $schedule->name]) }}" :label="__('Delete :name', ['name' => $schedule->name])" />
                        @endcan
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 9 : 8" emoji="🔁">{{ __('No recurring invoices yet. Add a schedule and pick the invoice to copy.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $schedules->links() }}
    </x-card>
    <x-sheet :label="__('Recurring invoice')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.sales.recurring.form :source="(int) $this->sheetArgument() ?: null" :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.sales.recurring.form :recurring="\App\Models\RecurringInvoice::query()->whereIn('company_id', auth()->user()->accessibleCompanyIds())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
