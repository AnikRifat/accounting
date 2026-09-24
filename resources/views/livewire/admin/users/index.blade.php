<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Administration') }}</p><h1>{{ __('Users') }}</h1><p class="muted">{{ __('Manage login accounts, their roles and the companies they can access.') }}</p></div>@can('users.create')<a class="btn" href="{{ route('admin.users.create') }}" wire:navigate>{{ __('Add user') }}</a>@endcan</div>
    <div class="panel stack">
        <x-form.input name="search" :label="__('Search by name or email')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" />
        <div class="table-wrap"><table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Role') }}</th><th>{{ __('Companies') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
            @forelse($users as $user)<tr wire:key="user-{{ $user->id }}"><td><strong>{{ $user->name }}</strong><p class="muted">{{ $user->email }}</p></td><td>{{ $permissions->label($user->role) }} @if($user->extra_roles)<p class="muted">{{ __('+ :count extra', ['count' => count($user->extra_roles)]) }}</p>@endif</td><td>{{ $user->hasPermission('companies.all') ? __('All companies') : ($user->companies->pluck('name')->join(', ') ?: '—') }}</td><td><span class="badge {{ $user->is_active ? '' : 'badge-neutral' }}">{{ $user->is_active ? __('Active') : __('Inactive') }}</span></td><td>@can('users.update')<a class="text-link" href="{{ route('admin.users.edit', $user) }}" aria-label="{{ __('Edit :name', ['name' => $user->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan</td></tr>
            @empty<tr><td colspan="5"><p class="muted">{{ __('No users found.') }}</p></td></tr>@endforelse
        </tbody></table></div>{{ $users->links() }}
    </div>
</div>
