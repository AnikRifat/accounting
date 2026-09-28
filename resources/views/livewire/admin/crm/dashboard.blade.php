<div class="page">
    <x-notices />
    @if(! $hasCompanies)
        <x-page-header :title="__('CRM dashboard')" />
        <x-card><x-empty-state emoji="🔒" :title="__('No company assigned yet')" :description="__('Ask your administrator to assign you a company. Its leads will appear here once you have access.')" /></x-card>
    @else
        <x-page-header :title="__('CRM dashboard')" :description="$scopeLabel.' · '.__('Follow-ups come from the next call date of each lead; closed leads are left out.')">
            @can('crm.leads.create')<x-slot:actions><x-button icon="plus" :href="route('admin.crm.leads.index', ['sheet' => 'create'])">{{ __('Add lead') }}</x-button></x-slot:actions> @endcan
        </x-page-header>
        <div class="stats">
            <x-stat :label="__('Calls due today')" emoji="📞" tone="info" :value="number_format($queues['today']->total())" :href="route('admin.crm.leads.index', ['follow_up' => 'today'])" />
            <x-stat :label="__('Overdue follow-ups')" emoji="⏰" tone="danger" :value="number_format($queues['overdue']->total())" :href="route('admin.crm.leads.index', ['follow_up' => 'overdue'])" />
            <x-stat :label="__('Upcoming follow-ups')" emoji="📅" tone="success" :value="number_format($queues['upcoming']->total())" :href="route('admin.crm.leads.index', ['follow_up' => 'upcoming'])" />
            <x-stat :label="__('Leads')" emoji="🧲" :value="number_format($totalLeads)" :hint="trans_choice(':count call logged today|:count calls logged today', $callsToday, ['count' => $callsToday])" :href="route('admin.crm.leads.index')" />
        </div>

        @php
            $titles = ['today' => [__('Call today'), __('Nobody is due for a call today.'), '📞'], 'overdue' => [__('Overdue'), __('No overdue follow-ups. Nicely done.'), '⏰'], 'upcoming' => [__('Upcoming'), __('No follow-ups scheduled after today.'), '📅']];
        @endphp
        <div class="grid-2">
            @foreach($queues as $queue => $leads)
                <x-card :id="'queue-'.$queue" :title="$titles[$queue][0]" flush :class="$queue === 'upcoming' ? 'span-full' : ''">
                    <x-slot:actions><x-badge :tone="['today' => 'info', 'overdue' => 'danger', 'upcoming' => 'success'][$queue]">{{ number_format($leads->total()) }}</x-badge></x-slot:actions>
                    @if($leads->isEmpty())
                        <x-empty-state :emoji="$titles[$queue][2]" :title="$titles[$queue][1]" />
                    @else
                        <x-table :caption="$titles[$queue][0]">
                            <x-slot:head><th>{{ __('Lead') }}</th><th>{{ __('Status') }}</th><th>{{ __('Next call') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                            @foreach($leads as $lead)
                                <tr wire:key="{{ $queue }}-{{ $lead->id }}">
                                    <td><a class="font-semibold text-heading" href="{{ route('admin.crm.leads.show', $lead) }}" wire:navigate>{{ $lead->displayName() }}</a>
                                        <p class="muted"><a class="text-link" href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a>@if($lead->service) · {{ $lead->service->name }}@endif @if($showCompany) · {{ $lead->company->name }}@endif @if($lead->assignee) · {{ $lead->assignee->name }}@endif</p>
                                        @if($lead->latestCall?->summary)<p class="muted">{{ \Illuminate\Support\Str::limit($lead->latestCall->summary, 80) }}</p>@endif</td>
                                    <td><x-badge :tone="$lead->status->tone">{{ $lead->status->name }}</x-badge></td>
                                    <td class="nowrap">{{ $lead->next_call_on->format('d M Y') }}</td>
                                    <td><div class="row-actions">@can('crm.calls.create')<x-button variant="secondary" size="sm" icon="phone" :href="route('admin.crm.calls.create', $lead)" :navigate="false" wire:click.prevent="openSheet('call:{{ $lead->id }}')">{{ __('Log call') }}</x-button>@endcan</div></td>
                                </tr>
                            @endforeach
                        </x-table>
                        {{ $leads->links() }}
                    @endif
                </x-card>
            @endforeach
        </div>

        <x-card id="recent-leads" :title="__('Newest leads')" flush>
            <x-slot:actions><x-button variant="secondary" size="sm" :href="route('admin.crm.leads.index')">{{ __('All leads') }}</x-button></x-slot:actions>
            @if($recent->isEmpty())
                <x-empty-state emoji="🧲" :title="__('No leads yet.')">@can('crm.leads.import')<x-button variant="secondary" icon="upload" :href="route('admin.crm.leads.index', ['sheet' => 'import'])">{{ __('Import leads') }}</x-button>@endcan</x-empty-state>
            @else
                <x-table :caption="__('Newest leads')">
                    <x-slot:head><th>{{ __('Lead') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Status') }}</th><th>{{ __('Service') }}</th><th>{{ __('Assigned to') }}</th><th>{{ __('Next call') }}</th></x-slot:head>
                    @foreach($recent as $lead)
                        <tr wire:key="recent-{{ $lead->id }}">
                            <td><a class="font-semibold text-heading" href="{{ route('admin.crm.leads.show', $lead) }}" wire:navigate>{{ $lead->displayName() }}</a><p class="muted">{{ $lead->created_at->format('d M Y') }}@if($showCompany) · {{ $lead->company->name }}@endif</p></td>
                            <td class="nowrap"><a class="text-link" href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a></td>
                            <td><x-badge :tone="$lead->status->tone">{{ $lead->status->name }}</x-badge></td>
                            <td>{{ $lead->service?->name ?? '—' }}</td>
                            <td>{{ $lead->assignee?->name ?? __('Unassigned') }}</td>
                            <td><x-crm.next-call :date="$lead->next_call_on" :closed="$lead->status->is_closed" /></td>
                        </tr>
                    @endforeach
                </x-table>
            @endif
        </x-card>
    @endif
    <x-sheet :label="__('Log a call')">
        @if($this->sheetAction() === 'call')
            <livewire:admin.crm.calls.form :lead="\App\Models\Lead::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :return-to="route('admin.crm.dashboard')" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
