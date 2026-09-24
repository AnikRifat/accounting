<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">Administration</p><h1>{{ $userId ? 'Edit' : 'Add' }} {{ $employee ? 'employee' : 'user' }}</h1><p class="muted">Accounts share one identity and permission system.</p></div></div>
    <form wire:submit="save" class="stack">
        <div class="panel"><h2>Account details</h2><div class="form-grid">
            <x-form.input name="name" label="Full name" wire:model="name" required autocomplete="name" />
            <x-form.input name="email" label="Email address" type="email" wire:model="email" required autocomplete="email" />
            <x-form.input name="password" label="Password" type="password" wire:model="password" autocomplete="new-password" :help="$userId ? 'Leave blank to keep the current password.' : '12+ characters with letters and numbers.'" />
            <x-form.input name="password_confirmation" label="Confirm password" type="password" wire:model="password_confirmation" autocomplete="new-password" />
            <x-form.checkbox name="isActive" label="Account is active" wire:model="isActive" />
        </div></div>
        @if($employee)<div class="panel"><h2>Employee profile</h2><div class="form-grid">
            <x-form.input name="employeeCode" label="Employee code" wire:model="employeeCode" required />
            <x-form.input name="jobTitle" label="Job title" wire:model="jobTitle" />
            <x-form.input name="department" label="Department" wire:model="department" />
            <x-form.input name="phone" label="Phone" type="tel" wire:model="phone" />
        </div></div>@endif
        <div class="panel stack"><h2>Roles and access</h2>
            <x-form.select name="role" label="Primary role" wire:model.live="role" :options="$roleOptions" :disabled="! auth()->user()->hasPermission('roles.assign')" help="Disabled roles cannot be newly assigned. The owner is protected." />
            <fieldset class="stack"><legend>Extra system roles</legend>@foreach($registry->systemRoles() as $key)<div wire:key="extra-{{ $key }}"><x-form.checkbox name="extraRoles" :id="'extra-'.$key" :label="$registry->label($key)" :value="$key" wire:model.live="extraRoles" :disabled="! auth()->user()->hasPermission('roles.assign')" /></div>@endforeach</fieldset>
            @can('permissions.manage')<fieldset><legend>Personal permissions</legend><p class="muted mb-4">Uncheck an ability to restrict this account. Personal permissions can never exceed the union of its roles.</p>
                <div class="permission-grid">@foreach(config('permissions.catalogue') as $group => $abilities)<div class="permission-group" wire:key="permission-group-{{ $loop->index }}"><h2>{{ $group }}</h2>@foreach($abilities as $ability)<div wire:key="ability-{{ $ability }}"><x-form.checkbox name="permissions" :id="'permission-'.$ability" :label="str($ability)->replace('.', ' ')->headline()" :value="$ability" wire:model="permissions" :disabled="! in_array($ability, $ceiling, true)" /></div>@endforeach</div>@endforeach</div>
            </fieldset>@endcan
        </div>
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">Save {{ $employee ? 'employee' : 'user' }}</button><a class="btn btn-secondary" href="{{ route('admin.'.$this->module().'.index') }}" wire:navigate>Cancel</a><span class="muted" wire:loading>Saving…</span></div>
    </form>
</div>
