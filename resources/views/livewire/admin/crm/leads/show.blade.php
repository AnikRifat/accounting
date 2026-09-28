<div class="page">
    <x-notices />
    <x-page-header :title="$lead->displayName()" :back="route('admin.crm.leads.index')" :back-label="__('Leads')">
        <x-slot:meta><p class="btn-group"><x-badge :tone="$lead->status->tone">{{ $lead->status->name }}</x-badge>@if($lead->service)<x-badge>{{ $lead->service->name }}</x-badge>@endif<span class="muted">{{ $lead->company->name }}</span></p></x-slot:meta>
        <x-slot:actions>
            @can('crm.leads.update')<x-button variant="secondary" icon="pencil" :href="route('admin.crm.leads.edit', $lead)" :navigate="false" wire:click.prevent="openSheet('edit')">{{ __('Edit') }}</x-button>@endcan
            @can('crm.calls.create')<x-button icon="phone" :href="route('admin.crm.calls.create', $lead)" :navigate="false" wire:click.prevent="openSheet('call')">{{ __('Log a call') }}</x-button>@endcan
        </x-slot:actions>
    </x-page-header>
    <div class="stats">
        <x-stat :label="__('Phone')" emoji="📞"><x-slot:value><a class="text-link" href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a></x-slot:value></x-stat>
        <x-stat :label="__('Next call')" emoji="📅" :hint="$lead->status->is_closed ? __('Closed lead: no follow-up') : null"><x-slot:value><x-crm.next-call :date="$lead->next_call_on" :closed="$lead->status->is_closed" /></x-slot:value></x-stat>
        <x-stat :label="__('Calls and visits')" emoji="🗂️" :value="$calls->count()" :hint="$calls->first() ? __('Last: :date', ['date' => $calls->first()->called_at->format('d M Y, h:i A')]) : __('Never called')" />
        <x-stat :label="__('Assigned to')" emoji="👤" :value="$lead->assignee?->name ?? __('Unassigned')" />
    </div>
    <div class="grid-2">
        <x-card :title="__('Details')">
            <dl class="details-list">
                @foreach([
                    __('Email') => $lead->email, __('Organisation') => $lead->organization, __('Address') => $lead->address,
                    __('Source') => $lead->source?->name, __('Notes') => $lead->notes,
                    __('Created') => $lead->created_at->format('d M Y').($lead->creator ? ' · '.$lead->creator->name : ''),
                ] as $label => $value)
                    <div><dt class="muted">{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd></div>
                @endforeach
            </dl>
        </x-card>
        <x-card :title="__('Call history')" flush>
            <x-table :caption="__('Call history')">
                <x-slot:head><th>{{ __('When') }}</th><th>{{ __('Result') }}</th><th>{{ __('Summary') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                @forelse($calls as $call)
                    <tr wire:key="call-{{ $call->id }}">
                        <td class="nowrap">{{ $call->called_at->format('d M Y') }}<p class="muted">{{ $call->called_at->format('h:i A') }} · {{ $call->type->label() }}</p></td>
                        <td>@if($call->callStatus)<x-badge :tone="$call->callStatus->tone">{{ $call->callStatus->name }}</x-badge>@endif <x-badge :tone="$call->leadStatus->tone">{{ $call->leadStatus->name }}</x-badge></td>
                        <td>{{ $call->summary ?: '—' }}<p class="muted">{{ $call->user->name }}@if($call->next_call_on) · {{ __('Next: :date', ['date' => $call->next_call_on->format('d M Y')]) }}@endif</p></td>
                        <td><div class="row-actions">
                            @if($editsAny || $call->user_id === auth()->id())
                                @can('crm.calls.update')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.crm.calls.edit', $call)" :navigate="false" wire:click.prevent="openSheet('edit-call:{{ $call->id }}')" :label="__('Edit call')" />@endcan
                                @can('crm.calls.delete')<x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="deleteCall({{ $call->id }})" wire:confirm="{{ __('Delete this call?') }}" :label="__('Delete call')" />@endcan
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <x-table.empty :colspan="4" emoji="📞">{{ __('No calls yet.') }}</x-table.empty>
                @endforelse
            </x-table>
        </x-card>
    </div>
    <x-sheet :label="__('Lead')">
        @if($this->sheetAction() === 'edit')
            <livewire:admin.crm.leads.form :lead="$lead" :return-to="route('admin.crm.leads.show', $lead)" :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'call')
            <livewire:admin.crm.calls.form :lead="$lead" :return-to="route('admin.crm.leads.show', $lead)" :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit-call')
            <livewire:admin.crm.calls.form :call="\App\Models\LeadCall::visibleTo(auth()->user())->where('lead_id', $lead->id)->findOrFail((int) $this->sheetArgument())" :return-to="route('admin.crm.leads.show', $lead)" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
