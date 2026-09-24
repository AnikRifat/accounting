<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Access control') }}</p><h1>{{ __('Roles & permissions') }}</h1><p class="muted">{{ __('System roles are fixed. Custom roles grant explicit abilities.') }}</p></div>@can('roles.create')@can('permissions.manage')<a class="btn" href="{{ route('admin.roles.create') }}" wire:navigate>{{ __('Create custom role') }}</a>@endcan
@endcan</div>
    @error('role')<p class="error mb-4" role="alert">{{ $message }}</p>@enderror
    <div class="panel table-wrap"><table><thead><tr><th>{{ __('Role') }}</th><th>{{ __('Type') }}</th><th>{{ __('Abilities') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
        @foreach($roles as $role)<tr wire:key="role-{{ $role }}"><td><strong>{{ $registry->label($role) }}</strong><p class="muted">{{ $role }}</p></td><td>{{ $registry->isCustom($role) ? __('Custom') : __('System') }}</td><td>{{ count($registry->forRole($role)) }} / {{ count($registry->catalogue()) }}</td><td><span class="badge {{ $registry->isActive($role) ? '' : 'badge-neutral' }}">{{ $registry->isActive($role) ? __('Enabled') : __('Disabled') }}</span></td><td><div class="flex items-center gap-4">@can('roles.update')<a class="text-link" href="{{ route('admin.roles.edit', $role) }}" aria-label="{{ __('Edit :name', ['name' => $registry->label($role)]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan @if($registry->isCustom($role))@can('roles.delete')<button class="btn btn-danger" wire:click="delete('{{ $role }}')" wire:confirm="{{ __('Delete this custom role? Assigned roles cannot be deleted.') }}" wire:loading.attr="disabled">{{ __('Delete') }}</button>@endcan
@endif</div></td></tr>@endforeach
    </tbody></table></div>
</div>
