<div class="page">
    <x-notices />
    <x-page-header :title="__('Employees')" :description="__('Everyone who works here signs in. Roles set what they can do; companies set whose books they see.')">
        @can('users.create')<x-slot:actions><x-button icon="plus" :href="route('admin.users.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add employee') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    @php($showSalary = auth()->user()->hasPermission('users.update'))
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar :active="(int) ($status !== '')">
                <x-form.input name="search" :label="__('Search by name, email, code or phone')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Name, email, code or phone…')" />
                <x-slot:filters>
                    <x-form.select name="status" :label="__('Status')" wire:model.live="status" :options="['' => __('Any status'), 'active' => __('Active'), 'inactive' => __('Inactive')]" />
                </x-slot:filters>
                <x-slot:clear><x-button variant="ghost" icon="filter-x" x-on:click="$wire.set('status', '')">{{ __('Clear filters') }}</x-button></x-slot:clear>
                <x-slot:actions><x-table.export :columns="$this->tableColumns()" /></x-slot:actions>
            </x-toolbar>
        </x-slot:toolbar>
        <x-table.bulk />
        <x-table :caption="__('Employees')">
            <x-slot:head><x-table.check-all :ids="$users->pluck('id')->all()" /><th>{{ __('Employee') }}</th><th>{{ __('Designation') }}</th><th>{{ __('Role') }}</th><th>{{ __('Companies') }}</th>@if($showSalary)<th class="num">{{ __('Monthly salary') }}</th>@endif<th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($users as $user)
                <tr wire:key="user-{{ $user->id }}">
                    <x-table.check :value="$user->id" :label="$user->name" />
                    <td><div class="flex items-center gap-3"><span class="avatar" aria-hidden="true">{{ collect(preg_split('/\s+/', trim($user->name)))->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('') }}</span><div><strong>{{ $user->name }}</strong><p class="muted">{{ $user->email }}@if($user->employee_code) · {{ $user->employee_code }}@endif</p>@if($user->phone)<p class="muted">{{ $user->phone }}</p>@endif</div></div></td>
                    <td>{{ $user->designation ?: '—' }}@if($user->department)<p class="muted">{{ $user->department }}</p>@endif</td>
                    <td><x-badge tone="primary">{{ $permissions->label($user->role) }}</x-badge>@if($user->extra_roles)<p class="muted">{{ __('+ :count extra', ['count' => count($user->extra_roles)]) }}</p>@endif</td>
                    <td>{{ $user->hasPermission('companies.all') ? __('All companies') : ($user->companies->pluck('name')->join(', ') ?: '—') }}</td>
                    @if($showSalary)<td class="num"><x-money :value="$user->monthly_salary" /></td>@endif
                    <td><x-badge.active :active="$user->is_active" /></td>
                    <td><div class="row-actions">@can('users.update')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.users.edit', $user)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $user->id }}')" :label="__('Edit :name', ['name' => $user->name])">{{ __('Edit') }}</x-button>@endcan</div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showSalary ? 8 : 7" emoji="👥">{{ __('No employees found.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $users->links() }}
    </x-card>
    <x-sheet :label="__('Employee')" size="lg">
        @if($this->sheetAction() === 'create')
            <livewire:admin.users.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.users.form :user="\App\Models\User::query()->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
