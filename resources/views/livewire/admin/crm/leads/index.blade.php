<div class="page">
    <x-notices />
    <x-page-header :title="__('Leads')" :description="$seesAll ? __('Every lead of the companies in the header. Filter, export or print it as the lead report.') : __('The leads assigned to you.')">
        <x-slot:actions>
            @can('crm.leads.import')<x-button variant="secondary" icon="upload" :href="route('admin.crm.leads.index', ['sheet' => 'import'])" :navigate="false" wire:click.prevent="openSheet('import')">{{ __('Import') }}</x-button>@endcan
            @can('crm.leads.create')<x-button icon="plus" :href="route('admin.crm.leads.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add lead') }}</x-button>@endcan
        </x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar :active="$active">
                <x-form.input name="search" :label="__('Search leads')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Name, phone, email or organisation…')" />
                <x-slot:filters>
                    <x-form.select name="followUp" :label="__('Follow-up')" wire:model.live="followUp" :options="['' => __('Any follow-up'), 'today' => __('Call today'), 'overdue' => __('Overdue'), 'upcoming' => __('Upcoming'), 'none' => __('No follow-up set')]" />
                    <x-form.select name="status" :label="__('Status')" wire:model.live="status" :options="['' => __('Any status')] + $statuses" />
                    <x-form.select name="service" :label="__('Service')" wire:model.live="service" :options="['' => __('Any service')] + $services" />
                    <x-form.select name="source" :label="__('Source')" wire:model.live="source" :options="['' => __('Any source')] + $sources + ['none' => __('Not recorded')]" />
                    @if($seesAll)<x-form.select name="assignee" :label="__('Assigned to')" wire:model.live="assignee" :options="['' => __('Anyone'), 'none' => __('Unassigned')] + $people" />@endif
                    <x-form.date-range id="created-range" :label="__('Created')" />
                </x-slot:filters>
                <x-slot:clear><x-button variant="ghost" icon="filter-x" x-on:click="$wire.set('followUp', ''); $wire.set('status', ''); $wire.set('service', ''); $wire.set('source', ''); $wire.set('assignee', ''); $wire.set('from', ''); $wire.set('to', '')">{{ __('Clear filters') }}</x-button></x-slot:clear>
                <x-slot:actions><x-table.export :columns="$this->tableColumns()" /></x-slot:actions>
            </x-toolbar>
        </x-slot:toolbar>
        <x-table.bulk />
        <x-table :caption="__('Leads')">
            <x-slot:head><x-table.check-all :ids="$leads->pluck('id')->all()" /><th>{{ __('Lead') }}</th><th>{{ __('Phone') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Status') }}</th><th>{{ __('Service') }}</th>@if($seesAll)<th>{{ __('Assigned to') }}</th>@endif<th>{{ __('Next call') }}</th><th>{{ __('Last call') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($leads as $lead)
                <tr wire:key="lead-{{ $lead->id }}">
                    <x-table.check :value="$lead->id" :label="$lead->displayName()" />
                    <td><a class="font-semibold text-heading" href="{{ route('admin.crm.leads.show', $lead) }}" wire:navigate>{{ $lead->displayName() }}</a>@if($lead->organization)<p class="muted">{{ $lead->organization }}</p>@endif</td>
                    <td class="nowrap"><a class="text-link" href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a></td>
                    @if($showCompany)<td>{{ $lead->company->name }}</td>@endif
                    <td><x-badge :tone="$lead->status->tone">{{ $lead->status->name }}</x-badge></td>
                    <td>{{ $lead->service?->name ?? '—' }}</td>
                    @if($seesAll)<td>{{ $lead->assignee?->name ?? __('Unassigned') }}</td>@endif
                    <td><x-crm.next-call :date="$lead->next_call_on" :closed="$lead->status->is_closed" /></td>
                    <td>@if($lead->latestCall)<span class="nowrap">{{ $lead->latestCall->called_at->format('d M Y') }}</span>@if($lead->latestCall->summary)<p class="muted">{{ \Illuminate\Support\Str::limit($lead->latestCall->summary, 60) }}</p>@endif @else<span class="muted">{{ __('Never called') }}</span>@endif</td>
                    <td><div class="row-actions">
                        @can('crm.calls.create')<x-button variant="ghost" size="sm" icon="phone" :href="route('admin.crm.calls.create', $lead)" :navigate="false" wire:click.prevent="openSheet('call:{{ $lead->id }}')" :label="__('Log a call with :name', ['name' => $lead->displayName()])" />@endcan
                        @can('crm.leads.update')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.crm.leads.edit', $lead)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $lead->id }}')" :label="__('Edit :name', ['name' => $lead->displayName()])" />@endcan
                        @can('crm.leads.delete')<x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $lead->id }})" wire:confirm="{{ __('Delete :name and every call logged with them?', ['name' => $lead->displayName()]) }}" :label="__('Delete :name', ['name' => $lead->displayName()])" />@endcan
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="10" emoji="🧲">{{ __('No leads found.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $leads->links() }}
    </x-card>
    <x-sheet :label="__('Lead')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.crm.leads.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.crm.leads.form :lead="\App\Models\Lead::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'call')
            <livewire:admin.crm.calls.form :lead="\App\Models\Lead::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :return-to="route('admin.crm.leads.index', array_filter(['search' => $search, 'status' => $status, 'service' => $service, 'source' => $source, 'assignee' => $assignee, 'follow_up' => $followUp, 'from' => $from, 'to' => $to]))" :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'import')
            <livewire:admin.crm.leads.import :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
