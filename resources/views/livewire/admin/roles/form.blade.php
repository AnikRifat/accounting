<div>
    <x-notices />
    @php($custom = ! $roleKey || $registry->isCustom($roleKey))
    <div class="page-header"><div><p class="eyebrow">{{ __('Access control') }}</p><h1>{{ $roleKey ? __('Edit role') : __('Create custom role') }}</h1><p class="muted">{{ $custom ? __('Choose the abilities this role grants.') : __('A system role keeps its name. Its abilities and availability can change.') }}</p></div></div>
    <form wire:submit="save" class="stack"><div class="panel stack">
        <x-form.input name="label" :label="__('Role name')" wire:model="label" :disabled="! $custom" required :help="__('The stored role key stays the same when the label changes.')" />
        <x-form.checkbox name="isActive" :label="__('Enabled for assignment')" wire:model="isActive" />
        <p class="muted">{{ __('Disabling removes this role from new assignments. Existing holders keep their permissions.') }}</p>
        <div class="permission-grid">@foreach(config('permissions.catalogue') as $group => $abilities)<fieldset class="permission-group" wire:key="group-{{ $loop->index }}"><legend>{{ __($group) }}</legend>@foreach($abilities as $ability)<div wire:key="ability-{{ $ability }}"><x-form.checkbox name="permissions" :id="'ability-'.$ability" :label="__(str($ability)->replace('.', ' ')->headline()->toString())" :value="$ability" wire:model="permissions" :disabled="! auth()->user()->hasPermission('permissions.manage')" /></div>@endforeach</fieldset>@endforeach</div>
    </div><div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save role') }}</button><a class="btn btn-secondary" href="{{ route('admin.roles.index') }}" wire:navigate>{{ __('Cancel') }}</a><span wire:loading class="muted">{{ __('Saving…') }}</span></div></form>
</div>
