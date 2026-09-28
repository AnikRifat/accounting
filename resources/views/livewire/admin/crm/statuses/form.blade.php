<div class="page">
    <x-notices />
    <x-page-header :title="$statusId ? __('Edit status') : __('Add status')" :back="route('admin.crm.statuses.index')" :back-label="__('Statuses')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Status details')">
            <div class="form-grid">
                @if($statusId)
                    <div class="field"><span class="field-label">{{ __('Type') }}</span><p>{{ $types[$type] }}</p></div>
                @else
                    <x-form.select name="type" :label="__('Type')" :options="$types" wire:model.live="type" required />
                @endif
                <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="60" autocomplete="off" />
                <x-form.select name="tone" :label="__('Colour')" :options="$tones" wire:model.live="tone" required />
                <x-form.input name="position" :label="__('Order')" type="number" min="0" max="999" wire:model="position" required :help="__('Lower numbers come first; the first active lead status is where new leads start.')" />
            </div>
            <p><span class="muted">{{ __('Preview:') }}</span> <x-badge :tone="$tone">{{ $name !== '' ? $name : __('Status') }}</x-badge></p>
            @if($type === 'lead')<x-form.checkbox name="isClosed" :label="__('Closed: leads in this status need no more calls')" wire:model="isClosed" />@endif
            <x-form.checkbox name="isActive" :label="__('Status is active (offered on forms)')" wire:model="isActive" />
        </x-card>
        <x-form.actions :submit="__('Save status')" :cancel="route('admin.crm.statuses.index')" />
    </form>
</div>
