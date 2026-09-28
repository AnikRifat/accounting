<div class="page">
    <x-notices />
    <x-page-header :title="__('Call log')" :description="$seesAll ? __('Every call and visit with the leads of the companies in the header. Filter, export or print it as the call report.') : __('Calls on your leads and calls you logged.')" />
    <div class="stats">
        <x-stat :label="__('Calls')" emoji="📞" :value="number_format($summary['calls'])" />
        <x-stat :label="__('Visits')" emoji="🚶" :value="number_format($summary['visits'])" />
        <x-stat :label="__('Leads contacted')" emoji="🧲" :value="number_format($summary['leads'])" />
    </div>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar :active="$active">
                <x-form.input name="search" :label="__('Search calls')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Lead, phone or summary…')" />
                <x-slot:filters>
                    <x-form.date-range id="called-range" :label="__('Date')" />
                    <x-form.select name="type" :label="__('Type')" wire:model.live="type" :options="['' => __('Calls and visits')] + $types" />
                    <x-form.select name="callStatus" :label="__('Call result')" wire:model.live="callStatus" :options="['' => __('Any result')] + $callStatuses" />
                    <x-form.select name="leadStatus" :label="__('Lead status')" wire:model.live="leadStatus" :options="['' => __('Any status')] + $leadStatuses" />
                    <x-form.select name="service" :label="__('Service')" wire:model.live="service" :options="['' => __('Any service')] + $services" />
                    @if($seesAll)
                        <x-form.select name="caller" :label="__('Logged by')" wire:model.live="caller" :options="['' => __('Anyone')] + $people" />
                        <x-form.select name="assignee" :label="__('Lead assigned to')" wire:model.live="assignee" :options="['' => __('Anyone')] + $people" />
                    @endif
                </x-slot:filters>
                <x-slot:clear><x-button variant="ghost" icon="filter-x" x-on:click="$wire.set('from', ''); $wire.set('to', ''); $wire.set('type', ''); $wire.set('callStatus', ''); $wire.set('leadStatus', ''); $wire.set('service', ''); $wire.set('caller', ''); $wire.set('assignee', '')">{{ __('Clear filters') }}</x-button></x-slot:clear>
                <x-slot:actions><x-table.export :columns="$this->tableColumns()" /></x-slot:actions>
            </x-toolbar>
        </x-slot:toolbar>
        <x-table.bulk />
        <x-table :caption="__('Call log')">
            <x-slot:head><x-table.check-all :ids="$calls->pluck('id')->all()" /><th>{{ __('When') }}</th><th>{{ __('Lead') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Result') }}</th><th>{{ __('Summary') }}</th><th>{{ __('Next call') }}</th><th>{{ __('By') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($calls as $call)
                <tr wire:key="call-{{ $call->id }}">
                    <x-table.check :value="$call->id" :label="$call->lead->displayName()" />
                    <td class="nowrap">{{ $call->called_at->format('d M Y') }}<p class="muted">{{ $call->called_at->format('h:i A') }} · {{ $call->type->label() }}</p></td>
                    <td><a class="font-semibold text-heading" href="{{ route('admin.crm.leads.show', $call->lead_id) }}" wire:navigate>{{ $call->lead->displayName() }}</a><p class="muted">{{ $call->lead->phone }}@if($call->lead->service) · {{ $call->lead->service->name }}@endif</p></td>
                    @if($showCompany)<td>{{ $call->company->name }}</td>@endif
                    <td>@if($call->callStatus)<x-badge :tone="$call->callStatus->tone">{{ $call->callStatus->name }}</x-badge>@endif <x-badge :tone="$call->leadStatus->tone">{{ $call->leadStatus->name }}</x-badge></td>
                    <td>{{ $call->summary ? \Illuminate\Support\Str::limit($call->summary, 90) : '—' }}</td>
                    <td class="nowrap">{{ $call->next_call_on?->format('d M Y') ?? '—' }}</td>
                    <td>{{ $call->user->name }}</td>
                    <td><div class="row-actions">
                        @if($seesAll || $call->user_id === auth()->id())
                            @can('crm.calls.update')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.crm.calls.edit', $call)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $call->id }}')" :label="__('Edit call')" />@endcan
                            @can('crm.calls.delete')<x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $call->id }})" wire:confirm="{{ __('Delete this call?') }}" :label="__('Delete call')" />@endcan
                        @endif
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="9" emoji="📞">{{ __('No calls found.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $calls->links() }}
    </x-card>
    <x-sheet :label="__('Call')">
        @if($this->sheetAction() === 'edit')
            <livewire:admin.crm.calls.form :call="\App\Models\LeadCall::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :return-to="route('admin.crm.calls.index')" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
