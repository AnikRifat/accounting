<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Organisation') }}</p><h1>{{ __('Parties') }}</h1><p class="muted">{{ __('Customers, suppliers and everyone else who pays, receives or is spent on. Every employee is a party of each company they are assigned to.') }}</p></div>@can('parties.create')<a class="btn" href="{{ route('admin.parties.create') }}" wire:navigate>{{ __('Add party') }}</a>@endcan</div>
    <div class="panel stack">
        <div class="form-grid">
            <x-form.input name="search" :label="__('Search by name or phone')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" />
            <x-form.select name="kind" :label="__('Type')" wire:model.live="kind" :options="['' => __('Any type'), 'custom' => __('Custom'), 'employee' => __('Employee')]" />
            <x-form.select name="status" :label="__('Status')" wire:model.live="status" :options="['' => __('Any status'), 'active' => __('Active'), 'inactive' => __('Inactive')]" />
        </div>
        <div class="table-wrap"><table><thead><tr><th>{{ __('Party') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Type') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
            @forelse($parties as $party)<tr wire:key="party-{{ $party->id }}"><td><strong>{{ $party->name }}</strong>@if($party->address)<p class="muted">{{ $party->address }}</p>@endif</td>@if($showCompany)<td>{{ $party->company->name }}</td>@endif<td><span class="badge badge-neutral">{{ $party->isEmployee() ? __('Employee') : __('Custom') }}</span></td><td>{{ $party->phone ?: '—' }}</td><td><span class="badge {{ $party->is_active ? '' : 'badge-neutral' }}">{{ $party->is_active ? __('Active') : __('Inactive') }}</span></td><td>
                @if($party->isEmployee())
                    @can('users.update')<a class="text-link" href="{{ route('admin.users.edit', $party->user_id) }}" aria-label="{{ __('Edit employee :name', ['name' => $party->name]) }}" wire:navigate>{{ __('Edit employee') }}</a>@endcan
                @else
                    @can('parties.update')<a class="text-link" href="{{ route('admin.parties.edit', $party) }}" aria-label="{{ __('Edit :name', ['name' => $party->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan
                @endif
            </td></tr>
            @empty<tr><td colspan="{{ $showCompany ? 6 : 5 }}"><p class="muted">{{ __('No parties found.') }}</p></td></tr>@endforelse
        </tbody></table></div>{{ $parties->links() }}
    </div>
</div>
