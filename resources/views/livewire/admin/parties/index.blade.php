<div class="page">
    <x-notices />
    <x-page-header :title="__('Parties')" :description="__('Customers, suppliers and everyone else who pays, receives or is spent on. Every employee is a party of each company they are assigned to.')">
        @can('parties.create')<x-slot:actions><x-button icon="plus" :href="route('admin.parties.create')">{{ __('Add party') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.input name="search" :label="__('Search by name or phone')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Name or phone…')" />
                <x-form.select name="kind" :label="__('Type')" wire:model.live="kind" :options="['' => __('Any type'), 'custom' => __('Custom'), 'employee' => __('Employee')]" />
                <x-form.select name="status" :label="__('Status')" wire:model.live="status" :options="['' => __('Any status'), 'active' => __('Active'), 'inactive' => __('Inactive')]" />
            </x-toolbar>
        </x-slot:toolbar>
        <x-table :caption="__('Parties')">
            <x-slot:head><th>{{ __('Party') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Type') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($parties as $party)
                <tr wire:key="party-{{ $party->id }}">
                    <td><strong>{{ $party->name }}</strong>@if($party->address)<p class="muted">{{ $party->address }}</p>@endif</td>
                    @if($showCompany)<td>{{ $party->company->name }}</td>@endif
                    <td>@if($party->isEmployee())<x-badge tone="info">{{ __('Employee') }}</x-badge>@else<x-badge>{{ __('Custom') }}</x-badge>@endif</td>
                    <td class="nowrap">{{ $party->phone ?: '—' }}</td>
                    <td><x-badge.active :active="$party->is_active" /></td>
                    <td><div class="row-actions">
                        @if(! $showCompany)@can('reports.view')<x-button variant="ghost" size="sm" icon="eye" :href="route('admin.reports.party-statement', ['party' => $party->id])" :label="__('Statement of :name', ['name' => $party->name])" />@endcan @endif
                        @if($party->isEmployee())
                            @can('users.update')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.users.edit', $party->user_id)" :label="__('Edit employee :name', ['name' => $party->name])">{{ __('Edit employee') }}</x-button>@endcan
                        @else
                            @can('parties.update')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.parties.edit', $party)" :label="__('Edit :name', ['name' => $party->name])">{{ __('Edit') }}</x-button>@endcan
                        @endif
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 6 : 5" emoji="🤝">{{ __('No parties found.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $parties->links() }}
    </x-card>
</div>
