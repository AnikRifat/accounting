<div class="page">
    <x-notices />
    @php($custom = ! $roleKey || $registry->isCustom($roleKey))
    <x-page-header :title="$roleKey ? __('Edit role') : __('Create custom role')" :description="$custom ? __('Choose the abilities this role grants.') : __('A system role keeps its name. Its abilities and availability can change.')" :back="route('admin.roles.index')" :back-label="__('Roles & permissions')" />
    <form wire:submit="save" class="stack">
        <x-card :title="__('Role')">
            <div class="form-grid">
                <x-form.input name="label" :label="__('Role name')" wire:model="label" :disabled="! $custom" required :help="__('The stored role key stays the same when the label changes.')" />
            </div>
            <x-form.checkbox name="isActive" :label="__('Enabled for assignment')" wire:model="isActive" />
            <p class="muted">{{ __('Disabling removes this role from new assignments. Existing holders keep their permissions.') }}</p>
        </x-card>
        <x-card :title="__('Abilities')">
            <div class="permission-grid">@foreach(config('permissions.catalogue') as $group => $abilities)<fieldset class="permission-group" wire:key="group-{{ $loop->index }}"><legend>{{ __($group) }}</legend>@foreach($abilities as $ability)<div wire:key="ability-{{ $ability }}"><x-form.checkbox name="permissions" :id="'ability-'.$ability" :label="__(str($ability)->replace('.', ' ')->headline()->toString())" :value="$ability" wire:model="permissions" :disabled="! auth()->user()->hasPermission('permissions.manage')" /></div>@endforeach</fieldset>@endforeach</div>
        </x-card>
        <x-form.actions :submit="__('Save role')" :cancel="route('admin.roles.index')" />
    </form>
</div>
