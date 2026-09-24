<div class="page">
    <x-notices />
    <x-page-header :title="$userId ? __('Edit employee') : __('Add employee')" :description="__('Every employee signs in. Their role sets what they can do; their companies set whose books they can see.')" :back="route('admin.users.index')" :back-label="__('Employees')" />
    <form wire:submit="save" class="stack">
        <x-card :title="__('Account details')">
            <div class="form-grid">
                <x-form.input name="name" :label="__('Full name')" wire:model="name" required maxlength="150" autocomplete="off" />
                <x-form.input name="email" :label="__('Email address')" type="email" wire:model="email" required autocomplete="off" />
                <x-form.input name="password" :label="__('Password')" type="password" wire:model="password" autocomplete="new-password" :help="$userId ? __('Leave blank to keep the current password.') : __('12+ characters with letters and numbers.')" />
                <x-form.input name="password_confirmation" :label="__('Confirm password')" type="password" wire:model="password_confirmation" autocomplete="new-password" />
            </div>
            <x-form.checkbox name="isActive" :label="__('Employee is active and can sign in')" wire:model="isActive" />
        </x-card>
        <x-card :title="__('Employee details')">
            <div class="form-grid">
                <x-form.input name="employeeCode" :label="__('Employee code')" wire:model="employeeCode" maxlength="30" autocomplete="off" :help="__('Optional. Unique across all companies.')" />
                <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" maxlength="40" autocomplete="off" />
                <x-form.input name="designation" :label="__('Designation')" wire:model="designation" maxlength="255" />
                <x-form.input name="department" :label="__('Department')" wire:model="department" maxlength="255" />
                <x-form.input name="monthlySalary" :label="__('Monthly salary (৳)')" wire:model="monthlySalary" required inputmode="decimal" autocomplete="off" :help="__('In taka, e.g. 25,000.50.')" />
                <x-form.date name="joinedOn" :label="__('Joining date')" wire:model="joinedOn" />
            </div>
        </x-card>
        <x-card :title="__('Companies')" :description="__('Each ticked company gets a party for this employee, so salaries and other expenses can be recorded against them. Roles with all-company access see every company, but get a party only where ticked.')">
            <fieldset><legend class="sr-only">{{ __('Assigned companies') }}</legend>
                <div class="permission-grid">@forelse($companies as $company)<div wire:key="company-{{ $company->id }}"><x-form.checkbox name="companyIds" :id="'company-'.$company->id" :label="$company->name.' ('.$company->code.')'" :value="$company->id" wire:model="companyIds" /></div>@empty<p class="muted">{{ __('No companies yet.') }}</p>@endforelse</div>
            </fieldset>
            @error('companyIds.*')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
        </x-card>
        <x-card :title="__('Roles and access')">
            <div class="form-grid">
                <x-form.select name="role" :label="__('Primary role')" wire:model.live="role" :options="$roleOptions" :disabled="! auth()->user()->hasPermission('roles.assign')" :help="__('Disabled roles cannot be newly assigned. The super admin is protected.')" />
            </div>
            <fieldset class="stack-sm"><legend>{{ __('Extra system roles') }}</legend>
                <div class="flex flex-wrap gap-x-6 gap-y-2">@foreach($registry->systemRoles() as $key)<div wire:key="extra-{{ $key }}"><x-form.checkbox name="extraRoles" :id="'extra-'.$key" :label="$registry->label($key)" :value="$key" wire:model.live="extraRoles" :disabled="! auth()->user()->hasPermission('roles.assign')" /></div>@endforeach</div>
            </fieldset>
            @can('permissions.manage')
                <fieldset class="stack-sm"><legend>{{ __('Personal permissions') }}</legend><p class="muted">{{ __('Uncheck an ability to restrict this account. Personal permissions can never exceed the union of its roles.') }}</p>
                    <div class="permission-grid">@foreach(config('permissions.catalogue') as $group => $abilities)<div class="permission-group" wire:key="permission-group-{{ $loop->index }}"><h3>{{ __($group) }}</h3>@foreach($abilities as $ability)<div wire:key="ability-{{ $ability }}"><x-form.checkbox name="permissions" :id="'permission-'.$ability" :label="str($ability)->replace('.', ' ')->headline()" :value="$ability" wire:model="permissions" :disabled="! in_array($ability, $ceiling, true)" /></div>@endforeach</div>@endforeach</div>
                </fieldset>
            @endcan
        </x-card>
        <x-form.actions :submit="__('Save employee')" :cancel="route('admin.users.index')" />
    </form>
</div>
