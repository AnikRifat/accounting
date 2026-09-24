<div class="page">
    <x-notices />
    <x-page-header :title="$paymentMethodId ? __('Edit payment method') : __('Add payment method')" :back="route('admin.payment-methods.index')" :back-label="__('Payment methods')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <form wire:submit="save" class="stack">
        <x-card :title="__('Payment method details')">
            <div class="form-grid">
                <x-form.select name="paymentType" :label="__('Payment type')" wire:model="paymentType" :options="$paymentTypes" required />
                <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" autocomplete="off" :help="__('For example Cash in Hand, City Bank or bKash.')" />
                <x-form.input name="details" :label="__('Account or wallet number')" wire:model="details" maxlength="255" autocomplete="off" />
            </div>
            <x-form.checkbox name="isActive" :label="__('Payment method is active')" wire:model="isActive" />
        </x-card>
        <x-card :title="__('Opening balance')">
            @if($openingEntry)
                <p class="muted">{{ __(':amount as of :date (:number).', ['amount' => \App\Support\Money::format($openingEntry->amount), 'date' => $openingEntry->entry_date->format('d M Y'), 'number' => $openingEntry->number]) }} @can('entries.update')<a class="text-link" href="{{ route('admin.entries.edit', $openingEntry) }}" wire:navigate>{{ __('Edit opening balance') }}</a>@endcan</p>
            @else
                <div class="form-grid">
                    <x-form.input name="openingBalance" :label="__('Opening balance (৳)')" wire:model="openingBalance" inputmode="decimal" autocomplete="off" :help="__('Optional. The money already held on the date below.')" />
                    <x-form.input name="openingDate" :label="__('As of date')" type="date" wire:model="openingDate" />
                </div>
            @endif
        </x-card>
        <x-form.actions :submit="__('Save payment method')" :cancel="route('admin.payment-methods.index')" />
    </form>
</div>
