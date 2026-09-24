<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Organisation') }}</p><h1>{{ $employeeId ? __('Edit employee') : __('Add employee') }}</h1><p class="muted">{{ __('Company: :name', ['name' => $companyName]) }}</p><p class="muted">{{ __('Employees are staff records. Link them to salary and other expenses.') }}</p></div></div>
    @error('company')<p class="error mb-4" role="alert">{{ $message }}</p>@enderror
    <form wire:submit="save" class="stack">
        <div class="panel"><h2>{{ __('Employee details') }}</h2><div class="form-grid">
            <x-form.input name="employeeCode" :label="__('Employee code')" wire:model="employeeCode" required maxlength="30" :help="__('Unique within the company.')" />
            <x-form.input name="name" :label="__('Full name')" wire:model="name" required maxlength="255" />
            <x-form.input name="designation" :label="__('Designation')" wire:model="designation" maxlength="255" />
            <x-form.input name="department" :label="__('Department')" wire:model="department" maxlength="255" />
            <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" maxlength="40" />
            <x-form.input name="monthlySalary" :label="__('Monthly salary (৳)')" wire:model="monthlySalary" required inputmode="decimal" :help="__('In taka, e.g. 25,000.50.')" />
            <x-form.input name="joinedOn" :label="__('Joining date')" type="date" wire:model="joinedOn" />
            <x-form.checkbox name="isActive" :label="__('Employee is active')" wire:model="isActive" />
        </div></div>
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save employee') }}</button><a class="btn btn-secondary" href="{{ route('admin.employees.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span></div>
    </form>
</div>
