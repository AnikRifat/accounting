<div>
    <x-notices />
    @php($custom = ! $roleKey || $registry->isCustom($roleKey))
    <div class="page-header"><div><p class="eyebrow">Access control</p><h1>{{ $roleKey ? 'Edit role' : 'Create custom role' }}</h1><p class="muted">{{ $custom ? 'Choose the abilities this role grants.' : 'Only assignment availability can change for a system role.' }}</p></div></div>
    <form wire:submit="save" class="stack"><div class="panel stack">
        <x-form.input name="label" label="Role name" wire:model="label" :disabled="! $custom" required help="The stored role key stays the same when the label changes." />
        <x-form.checkbox name="isActive" label="Enabled for assignment" wire:model="isActive" />
        <p class="muted">Disabling removes this role from new assignments. Existing holders keep their permissions, matching Shoplagbe.</p>
        <div class="permission-grid">@foreach(config('permissions.catalogue') as $group => $abilities)<fieldset class="permission-group" wire:key="group-{{ $loop->index }}"><legend>{{ $group }}</legend>@foreach($abilities as $ability)<div wire:key="ability-{{ $ability }}"><x-form.checkbox name="permissions" :id="'ability-'.$ability" :label="str($ability)->replace('.', ' ')->headline()" :value="$ability" wire:model="permissions" :disabled="! $custom || ! auth()->user()->hasPermission('permissions.manage')" /></div>@endforeach</fieldset>@endforeach</div>
    </div><div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">Save role</button><a class="btn btn-secondary" href="{{ route('admin.roles.index') }}" wire:navigate>Cancel</a><span wire:loading class="muted">Saving…</span></div></form>
</div>
