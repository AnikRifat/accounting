<div class="page">
    <x-page-header :title="__('CRM insights')" :description="$scopeLabel.' · '.__('The date range limits leads by the day they were added and calls by the day they were made.')" />
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                @if($seesAll)<x-form.select name="person" :label="__('Person')" wire:model.live="person" :options="['' => __('Everyone')] + $people" />@endif
                <x-form.date-range id="insights-range" :label="__('Date range')" :placeholder="__('All time')" />
            </x-toolbar>
        </x-slot:toolbar>
    </x-card>

    <section class="stack" aria-labelledby="lead-figures">
        <h2 id="lead-figures">{{ __('Leads and calls') }}</h2>
        <div class="stats">
            <x-stat :label="__('Leads')" emoji="🧲" :value="number_format($leadFigures['leads'])" :href="route('admin.crm.leads.index', array_filter(['from' => $from, 'to' => $to, 'assignee' => $seesAll ? $person : '']))" />
            <x-stat :label="__('Never called')" emoji="🕳️" tone="warning" :value="number_format($leadFigures['neverCalled'])" :hint="__('Leads without a single call or visit')" />
            <x-stat :label="__('Calls')" emoji="📞" tone="info" :value="number_format($leadFigures['calls'])" :href="route('admin.crm.calls.index', array_filter(['from' => $from, 'to' => $to, 'type' => 'call', 'by' => $seesAll ? $person : '']))" />
            <x-stat :label="__('Leads contacted')" emoji="✅" tone="success" :value="number_format($leadFigures['leadsCalled'])" />
            <x-stat :label="__('Repeat contacts')" emoji="🔁" :value="number_format($leadFigures['repeatCalls'])" :hint="__('Calls and visits after the first with the same lead')" />
        </div>
    </section>

    <section class="stack" aria-labelledby="lead-status-figures">
        <h2 id="lead-status-figures">{{ __('Leads by status') }}</h2>
        <div class="stats">
            @forelse($byLeadStatus as $label => $count)
                <x-stat wire:key="lead-status-{{ $loop->index }}" :label="$label" :value="number_format($count)" :href="route('admin.crm.leads.index', array_filter(['status' => $label, 'from' => $from, 'to' => $to, 'assignee' => $seesAll ? $person : '']))" />
            @empty
                <p class="muted">{{ __('No lead statuses yet.') }}</p>
            @endforelse
        </div>
    </section>

    <section class="stack" aria-labelledby="call-result-figures">
        <h2 id="call-result-figures">{{ __('Calls by result') }}</h2>
        <div class="stats">
            @foreach($byCallResult as $label => $count)
                <x-stat wire:key="call-result-{{ $loop->index }}" :label="$label" :value="number_format($count)" />
            @endforeach
        </div>
    </section>

    <section class="stack" aria-labelledby="follow-up-figures">
        <h2 id="follow-up-figures">{{ __('Follow-ups') }}</h2>
        <div class="stats">
            <x-stat :label="__('Calls due today')" emoji="📞" tone="info" :value="number_format($followUps['today'])" :href="route('admin.crm.leads.index', array_filter(['follow_up' => 'today', 'assignee' => $seesAll ? $person : '']))" />
            <x-stat :label="__('Overdue follow-ups')" emoji="⏰" tone="danger" :value="number_format($followUps['overdue'])" :href="route('admin.crm.leads.index', array_filter(['follow_up' => 'overdue', 'assignee' => $seesAll ? $person : '']))" />
            <x-stat :label="__('Upcoming follow-ups')" emoji="📅" tone="success" :value="number_format($followUps['upcoming'])" :href="route('admin.crm.leads.index', array_filter(['follow_up' => 'upcoming', 'assignee' => $seesAll ? $person : '']))" />
            <x-stat :label="__('Visits today')" emoji="🚶" :value="number_format($followUps['visitsToday'])" />
            <x-stat :label="__('Visits')" emoji="🏢" :value="number_format($followUps['visits'])" :href="route('admin.crm.calls.index', array_filter(['from' => $from, 'to' => $to, 'type' => 'visit', 'by' => $seesAll ? $person : '']))" />
        </div>
    </section>
</div>
