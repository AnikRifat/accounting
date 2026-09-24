<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Organisation') }}</p><h1>{{ $userId ? __('Edit employee') : __('Add employee') }}</h1><p class="muted">{{ __('Every employee signs in. Their role sets what they can do; their companies set whose books they can see.') }}</p></div></div>
    <form wire:submit="save" class="stack">
        <div class="panel"><h2>{{ __('Account details') }}</h2><div class="form-grid">
            <x-form.input name="name" :label="__('Full name')" wire:model="name" required maxlength="150" autocomplete="off" />
            <x-form.input name="email" :label="__('Email address')" type="email" wire:model="email" required autocomplete="off" />
            <x-form.input name="password" :label="__('Password')" type="password" wire:model="password" autocomplete="new-password" :help="$userId ? __('Leave blank to keep the current password.') : __('12+ characters with letters and numbers.')" />
            <x-form.input name="password_confirmation" :label="__('Confirm password')" type="password" wire:model="password_confirmation" autocomplete="new-password" />
            <x-form.checkbox name="isActive" :label="__('Employee is active and can sign in')" wire:model="isActive" />
        </div></div>
        <div class="panel"><h2>{{ __('Employee details') }}</h2><div class="form-grid">
            <x-form.input name="employeeCode" :label="__('Employee code')" wire:model="employeeCode" maxlength="30" autocomplete="off" :help="__('Optional. Unique across all companies.')" />
            <x-form.input name="phone" :label="__('Phone')" type="tel" wire:model="phone" maxlength="40" autocomplete="off" />
            <x-form.input name="designation" :label="__('Designation')" wire:model="designation" maxlength="255" />
            <x-form.input name="department" :label="__('Department')" wire:model="department" maxlength="255" />
            <x-form.input name="monthlySalary" :label="__('Monthly salary (৳)')" wire:model="monthlySalary" required inputmode="decimal" autocomplete="off" :help="__('In taka, e.g. 25,000.50.')" />
            <x-form.input name="joinedOn" :label="__('Joining date')" type="date" wire:model="joinedOn" />
        </div></div>
        <div class="panel stack"><h2>{{ __('Companies') }}</h2><p class="muted">{{ __('Each ticked company gets a party for this employee, so salaries and other expenses can be recorded against them. Roles with all-company access see every company, but get a party only where ticked.') }}</p>
            <fieldset class="stack"><legend class="sr-only">{{ __('Assigned companies') }}</legend>@forelse($companies as $company)<div wire:key="company-{{ $company->id }}"><x-form.checkbox name="companyIds" :id="'company-'.$company->id" :label="$company->name.' ('.$company->code.')'" :value="$company->id" wire:model="companyIds" /></div>@empty<p class="muted">{{ __('No companies yet.') }}</p>@endforelse</fieldset>
            @error('companyIds.*')<p class="error" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="panel stack"><h2>{{ __('Roles and access') }}</h2>
            <x-form.select name="role" :label="__('Primary role')" wire:model.live="role" :options="$roleOptions" :disabled="! auth()->user()->hasPermission('roles.assign')" :help="__('Disabled roles cannot be newly assigned. The super admin is protected.')" />
            <fieldset class="stack"><legend>{{ __('Extra system roles') }}</legend>@foreach($registry->systemRoles() as $key)<div wire:key="extra-{{ $key }}"><x-form.checkbox name="extraRoles" :id="'extra-'.$key" :label="$registry->label($key)" :value="$key" wire:model.live="extraRoles" :disabled="! auth()->user()->hasPermission('roles.assign')" /></div>@endforeach</fieldset>
            @can('permissions.manage')<fieldset><legend>{{ __('Personal permissions') }}</legend><p class="muted mb-4">{{ __('Uncheck an ability to restrict this account. Personal permissions can never exceed the union of its roles.') }}</p>
                <div class="permission-grid">@foreach(config('permissions.catalogue') as $group => $abilities)<div class="permission-group" wire:key="permission-group-{{ $loop->index }}"><h2>{{ __($group) }}</h2>@foreach($abilities as $ability)<div wire:key="ability-{{ $ability }}"><x-form.checkbox name="permissions" :id="'permission-'.$ability" :label="str($ability)->replace('.', ' ')->headline()" :value="$ability" wire:model="permissions" :disabled="! in_array($ability, $ceiling, true)" /></div>@endforeach</div>@endforeach</div>
            </fieldset>@endcan
        </div>
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save employee') }}</button><a class="btn btn-secondary" href="{{ route('admin.users.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span></div>
    </form>
</div>
