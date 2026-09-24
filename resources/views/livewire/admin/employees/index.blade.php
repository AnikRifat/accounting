<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Organisation') }}</p><h1>{{ __('Employees') }}</h1><p class="muted">{{ __('Staff records for each company. Employees do not sign in.') }}</p></div>@can('employees.create')<a class="btn" href="{{ route('admin.employees.create') }}" wire:navigate>{{ __('Add employee') }}</a>@endcan</div>
    <div class="panel stack">
        <div class="form-grid">
            <x-form.input name="search" :label="__('Search by name, code or phone')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" />
            <x-form.select name="status" :label="__('Status')" wire:model.live="status" :options="['' => __('Any status'), 'active' => __('Active'), 'inactive' => __('Inactive')]" />
        </div>
        <div class="table-wrap"><table><thead><tr><th>{{ __('Employee') }}</th><th>{{ __('Company') }}</th><th>{{ __('Designation') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Monthly salary') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
            @forelse($employees as $employee)<tr wire:key="employee-{{ $employee->id }}"><td><strong>{{ $employee->name }}</strong><p class="muted">{{ $employee->employee_code }}</p></td><td>{{ $employee->company->name }}</td><td>{{ $employee->designation ?: '—' }}@if($employee->department)<p class="muted">{{ $employee->department }}</p>@endif</td><td>{{ $employee->phone ?: '—' }}</td><td>{{ \App\Support\Money::format($employee->monthly_salary) }}</td><td><span class="badge {{ $employee->is_active ? '' : 'badge-neutral' }}">{{ $employee->is_active ? __('Active') : __('Inactive') }}</span></td><td>@can('employees.update')<a class="text-link" href="{{ route('admin.employees.edit', $employee) }}" aria-label="{{ __('Edit :name', ['name' => $employee->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan</td></tr>
            @empty<tr><td colspan="7"><p class="muted">{{ __('No employees found.') }}</p></td></tr>@endforelse
        </tbody></table></div>{{ $employees->links() }}
    </div>
</div>
