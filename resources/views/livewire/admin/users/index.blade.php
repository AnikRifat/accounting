<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">Administration</p><h1>{{ $employee ? 'Employees' : 'Users' }}</h1><p class="muted">Manage accounts, active status, and assigned roles.</p></div>@can($this->module().'.create')<a class="btn" href="{{ route('admin.'.$this->module().'.create') }}" wire:navigate>Add {{ $employee ? 'employee' : 'user' }}</a>@endcan</div>
    <div class="panel stack">
        <x-form.input name="search" label="Search by name or email" wire:model.live.debounce.300ms="search" type="search" maxlength="100" />
        <div class="table-wrap"><table><thead><tr><th>Name</th>@if($employee)<th>Employee code</th><th>Department</th>@endif<th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($users as $user)<tr wire:key="user-{{ $user->id }}"><td><strong>{{ $user->name }}</strong><p class="muted">{{ $user->email }}</p></td>@if($employee)<td>{{ $user->employee->employee_code }}</td><td>{{ $user->employee->department ?: '—' }}</td>@endif<td>{{ $permissions->label($user->role) }} @if($user->extra_roles)<p class="muted">+ {{ count($user->extra_roles) }} extra</p>@endif</td><td><span class="badge {{ $user->is_active ? '' : 'badge-neutral' }}">{{ $user->is_active ? 'Active' : 'Inactive' }}</span></td><td>@can($this->module().'.update')<a class="text-link" href="{{ route('admin.'.$this->module().'.edit', $user) }}" aria-label="Edit {{ $user->name }}" wire:navigate>Edit</a>@endcan</td></tr>
            @empty<tr><td colspan="{{ $employee ? 6 : 4 }}"><p class="muted">No {{ $employee ? 'employees' : 'users' }} found.</p></td></tr>@endforelse
        </tbody></table></div>{{ $users->links() }}
    </div>
</div>
