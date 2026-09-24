<div class="page">
    <x-notices />
    <x-page-header :title="$categoryId ? __('Edit category') : __('Add category')" :description="$categoryId || $companyId ? __('Names are unique within a company. The code is assigned automatically.') : __('The category is added to every active company that does not already have this name.')" :back="route('admin.categories.index')" :back-label="__('Categories')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Category details')">
            <div class="form-grid">
                <x-form.select name="type" :label="__('Type')" wire:model="type" :options="$types" :disabled="$hasEntries" :help="$hasEntries ? __('The type is fixed once the category has entries.') : null" />
                <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" autocomplete="off" />
            </div>
            <x-form.checkbox name="isActive" :label="__('Category is active')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save category')" :cancel="route('admin.categories.index')" />
    </form>
</div>
