<div class="page">
    <x-notices />
    <x-page-header :title="__('Email log')" :description="$seesAll ? __('Every email sent to the leads of the companies in the header, including the ones the mail server refused.') : __('Emails to your leads and emails you sent.')" />
    <div class="stats">
        <x-stat :label="__('Sent')" emoji="✉️" :value="number_format($summary['sent'])" />
        <x-stat :label="__('Failed')" emoji="⚠️" :value="number_format($summary['failed'])" :hint="$summary['failed'] > 0 ? __('Check Settings > Mail.') : null" />
        <x-stat :label="__('Leads emailed')" emoji="🧲" :value="number_format($summary['leads'])" />
    </div>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar :active="$active">
                <x-form.input name="search" :label="__('Search emails')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Lead, address or subject…')" />
                <x-slot:filters>
                    <x-form.date-range id="sent-range" :label="__('Date')" />
                    <x-form.select name="status" :label="__('Status')" wire:model.live="status" :options="['' => __('Sent and failed')] + $statuses" />
                    @if($seesAll)
                        <x-form.select name="sender" :label="__('Sent by')" wire:model.live="sender" :options="['' => __('Anyone')] + $people" />
                        <x-form.select name="assignee" :label="__('Lead assigned to')" wire:model.live="assignee" :options="['' => __('Anyone')] + $people" />
                    @endif
                </x-slot:filters>
                <x-slot:clear><x-button variant="ghost" icon="filter-x" x-on:click="$wire.set('from', ''); $wire.set('to', ''); $wire.set('status', ''); $wire.set('sender', ''); $wire.set('assignee', '')">{{ __('Clear filters') }}</x-button></x-slot:clear>
                <x-slot:actions><x-table.export :columns="$this->tableColumns()" /></x-slot:actions>
            </x-toolbar>
        </x-slot:toolbar>
        <x-table.bulk />
        <x-table :caption="__('Email log')">
            <x-slot:head><x-table.check-all :ids="$emails->pluck('id')->all()" /><th>{{ __('When') }}</th><th>{{ __('Lead') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Email') }}</th><th>{{ __('Status') }}</th><th>{{ __('By') }}</th></x-slot:head>
            @forelse($emails as $email)
                <tr wire:key="email-{{ $email->id }}">
                    <x-table.check :value="$email->id" :label="$email->lead->displayName()" />
                    <td class="nowrap">{{ $email->sent_at->format('d M Y') }}<p class="muted">{{ $email->sent_at->format('h:i A') }}</p></td>
                    <td><a class="font-semibold text-heading" href="{{ route('admin.crm.leads.show', $email->lead_id) }}" wire:navigate>{{ $email->lead->displayName() }}</a><p class="muted">{{ $email->to }}</p></td>
                    @if($showCompany)<td>{{ $email->company->name }}</td>@endif
                    <td><x-crm.email-message :email="$email" /></td>
                    <td><x-badge :tone="$email->failed() ? 'danger' : 'success'">{{ $email->failed() ? __('Failed') : __('Sent') }}</x-badge></td>
                    <td>{{ $email->user->name }}</td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 7 : 6" emoji="✉️">{{ __('No emails found.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $emails->links() }}
    </x-card>
</div>
