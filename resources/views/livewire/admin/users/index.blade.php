<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Organisation') }}</p><h1>{{ __('Employees') }}</h1><p class="muted">{{ __('Everyone who works here signs in. Roles set what they can do; companies set whose books they see.') }}</p></div>@can('users.create')<a class="btn" href="{{ route('admin.users.create') }}" wire:navigate>{{ __('Add employee') }}</a>@endcan</div>
    <div class="panel stack">
        <div class="form-grid">
            <x-form.input name="search" :label="__('Search by name, email, code or phone')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" />
            <x-form.select name="status" :label="__('Status')" wire:model.live="status" :options="['' => __('Any status'), 'active' => __('Active'), 'inactive' => __('Inactive')]" />
        </div>
        @php($showSalary = auth()->user()->hasPermission('users.update'))
        <div class="table-wrap"><table><thead><tr><th>{{ __('Employee') }}</th><th>{{ __('Designation') }}</th><th>{{ __('Role') }}</th><th>{{ __('Companies') }}</th>@if($showSalary)<th class="text-right">{{ __('Monthly salary') }}</th>@endif<th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
            @forelse($users as $user)<tr wire:key="user-{{ $user->id }}">
                <td><strong>{{ $user->name }}</strong><p class="muted">{{ $user->email }}@if($user->employee_code) · {{ $user->employee_code }}@endif</p>@if($user->phone)<p class="muted">{{ $user->phone }}</p>@endif</td>
                <td>{{ $user->designation ?: '—' }}@if($user->department)<p class="muted">{{ $user->department }}</p>@endif</td>
                <td>{{ $permissions->label($user->role) }} @if($user->extra_roles)<p class="muted">{{ __('+ :count extra', ['count' => count($user->extra_roles)]) }}</p>@endif</td>
                <td>{{ $user->hasPermission('companies.all') ? __('All companies') : ($user->companies->pluck('name')->join(', ') ?: '—') }}</td>
                @if($showSalary)<td class="text-right tabular-nums whitespace-nowrap">{{ \App\Support\Money::format($user->monthly_salary) }}</td>@endif
                <td><span class="badge {{ $user->is_active ? '' : 'badge-neutral' }}">{{ $user->is_active ? __('Active') : __('Inactive') }}</span></td>
                <td>@can('users.update')<a class="text-link" href="{{ route('admin.users.edit', $user) }}" aria-label="{{ __('Edit :name', ['name' => $user->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan</td>
            </tr>
            @empty<tr><td colspan="{{ $showSalary ? 7 : 6 }}"><p class="muted">{{ __('No employees found.') }}</p></td></tr>@endforelse
        </tbody></table></div>{{ $users->links() }}
    </div>
</div>
