<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ $categoryId ? __('Edit category') : __('Add category') }}</h1><p class="muted">{{ __('Company: :name', ['name' => $companyName]) }}</p><p class="muted">{{ $categoryId || $companyId ? __('Names are unique within a company. The code is assigned automatically.') : __('The category is added to every active company that does not already have this name.') }}</p></div></div>
    @error('company')<p class="error mb-4" role="alert">{{ $message }}</p>@enderror
    <form wire:submit="save" class="stack">
        <div class="panel"><div class="form-grid">
            <x-form.select name="type" :label="__('Type')" wire:model="type" :options="$types" :disabled="$hasEntries" :help="$hasEntries ? __('The type is fixed once the category has entries.') : null" />
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" autocomplete="off" />
            <x-form.checkbox name="isActive" :label="__('Category is active')" wire:model="isActive" />
        </div></div>
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save category') }}</button><a class="btn btn-secondary" href="{{ route('admin.categories.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span></div>
    </form>
</div>
