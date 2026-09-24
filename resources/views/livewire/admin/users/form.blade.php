<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Administration') }}</p><h1>{{ $userId ? __('Edit user') : __('Add user') }}</h1><p class="muted">{{ __('Users are login accounts. Their role sets what they can do; their companies set whose books they can see.') }}</p></div></div>
    <form wire:submit="save" class="stack">
        <div class="panel"><h2>{{ __('Account details') }}</h2><div class="form-grid">
            <x-form.input name="name" :label="__('Full name')" wire:model="name" required autocomplete="name" />
            <x-form.input name="email" :label="__('Email address')" type="email" wire:model="email" required autocomplete="email" />
            <x-form.input name="password" :label="__('Password')" type="password" wire:model="password" autocomplete="new-password" :help="$userId ? __('Leave blank to keep the current password.') : __('12+ characters with letters and numbers.')" />
            <x-form.input name="password_confirmation" :label="__('Confirm password')" type="password" wire:model="password_confirmation" autocomplete="new-password" />
            <x-form.checkbox name="isActive" :label="__('Account is active')" wire:model="isActive" />
        </div></div>
        <div class="panel stack"><h2>{{ __('Companies') }}</h2><p class="muted">{{ __('Owners and administrators see every company. Other roles see only the companies ticked here.') }}</p>
            <fieldset class="stack"><legend class="sr-only">{{ __('Assigned companies') }}</legend>@forelse($companies as $company)<div wire:key="company-{{ $company->id }}"><x-form.checkbox name="companyIds" :id="'company-'.$company->id" :label="$company->name.' ('.$company->code.')'" :value="$company->id" wire:model="companyIds" /></div>@empty<p class="muted">{{ __('No companies yet.') }}</p>@endforelse</fieldset>
            @error('companyIds.*')<p class="error" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="panel stack"><h2>{{ __('Roles and access') }}</h2>
            <x-form.select name="role" :label="__('Primary role')" wire:model.live="role" :options="$roleOptions" :disabled="! auth()->user()->hasPermission('roles.assign')" :help="__('Disabled roles cannot be newly assigned. The owner is protected.')" />
            <fieldset class="stack"><legend>{{ __('Extra system roles') }}</legend>@foreach($registry->systemRoles() as $key)<div wire:key="extra-{{ $key }}"><x-form.checkbox name="extraRoles" :id="'extra-'.$key" :label="$registry->label($key)" :value="$key" wire:model.live="extraRoles" :disabled="! auth()->user()->hasPermission('roles.assign')" /></div>@endforeach</fieldset>
            @can('permissions.manage')<fieldset><legend>{{ __('Personal permissions') }}</legend><p class="muted mb-4">{{ __('Uncheck an ability to restrict this account. Personal permissions can never exceed the union of its roles.') }}</p>
                <div class="permission-grid">@foreach(config('permissions.catalogue') as $group => $abilities)<div class="permission-group" wire:key="permission-group-{{ $loop->index }}"><h2>{{ __($group) }}</h2>@foreach($abilities as $ability)<div wire:key="ability-{{ $ability }}"><x-form.checkbox name="permissions" :id="'permission-'.$ability" :label="str($ability)->replace('.', ' ')->headline()" :value="$ability" wire:model="permissions" :disabled="! in_array($ability, $ceiling, true)" /></div>@endforeach</div>@endforeach</div>
            </fieldset>@endcan
        </div>
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save user') }}</button><a class="btn btn-secondary" href="{{ route('admin.users.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span></div>
    </form>
</div>
