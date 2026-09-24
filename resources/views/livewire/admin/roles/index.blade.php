<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">Access control</p><h1>Roles & permissions</h1><p class="muted">System roles are fixed. Custom roles grant explicit abilities.</p></div>@can('roles.create')@can('permissions.manage')<a class="btn" href="{{ route('admin.roles.create') }}" wire:navigate>Create custom role</a>@endcan
@endcan</div>
    @error('role')<p class="error mb-4" role="alert">{{ $message }}</p>@enderror
    <div class="panel table-wrap"><table><thead><tr><th>Role</th><th>Type</th><th>Abilities</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        @foreach($roles as $role)<tr wire:key="role-{{ $role }}"><td><strong>{{ $registry->label($role) }}</strong><p class="muted">{{ $role }}</p></td><td>{{ $registry->isCustom($role) ? 'Custom' : 'System' }}</td><td>{{ count($registry->forRole($role)) }} / {{ count($registry->catalogue()) }}</td><td><span class="badge {{ $registry->isActive($role) ? '' : 'badge-neutral' }}">{{ $registry->isActive($role) ? 'Enabled' : 'Disabled' }}</span></td><td><div class="flex items-center gap-4">@can('roles.update')<a class="text-link" href="{{ route('admin.roles.edit', $role) }}" aria-label="Edit {{ $registry->label($role) }}" wire:navigate>Edit</a>@endcan @if($registry->isCustom($role))@can('roles.delete')<button class="btn btn-danger" wire:click="delete('{{ $role }}')" wire:confirm="Delete this custom role? Assigned roles cannot be deleted." wire:loading.attr="disabled">Delete</button>@endcan
@endif</div></td></tr>@endforeach
    </tbody></table></div>
</div>
