<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ $paymentMethodId ? __('Edit payment method') : __('Add payment method') }}</h1><p class="muted">{{ __('Names are unique within a company. The code is assigned automatically.') }}</p></div></div>
    <form wire:submit="save" class="stack">
        <div class="panel"><div class="form-grid">
            <x-form.select name="companyId" :label="__('Company')" wire:model="companyId" :options="$companies" required :disabled="$paymentMethodId !== null" :help="$paymentMethodId ? __('A payment method cannot move to another company.') : __('Only active companies are listed.')" />
            <x-form.select name="paymentType" :label="__('Payment type')" wire:model="paymentType" :options="$paymentTypes" required />
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" autocomplete="off" :help="__('For example Cash in Hand, City Bank or bKash.')" />
            <x-form.input name="details" :label="__('Account or wallet number')" wire:model="details" maxlength="255" autocomplete="off" />
            <x-form.checkbox name="isActive" :label="__('Payment method is active')" wire:model="isActive" />
        </div></div>
        @if($openingEntry)
            <div class="panel"><h2>{{ __('Opening balance') }}</h2><p class="muted">{{ __(':amount as of :date (:number).', ['amount' => \App\Support\Money::format($openingEntry->amount), 'date' => $openingEntry->entry_date->format('d M Y'), 'number' => $openingEntry->number]) }} @can('entries.update')<a class="text-link" href="{{ route('admin.entries.edit', $openingEntry) }}" wire:navigate>{{ __('Edit opening balance') }}</a>@endcan</p></div>
        @else
            <div class="panel"><h2>{{ __('Opening balance') }}</h2><div class="form-grid">
                <x-form.input name="openingBalance" :label="__('Opening balance (৳)')" wire:model="openingBalance" inputmode="decimal" autocomplete="off" :help="__('Optional. The money already held on the date below.')" />
                <x-form.input name="openingDate" :label="__('As of date')" type="date" wire:model="openingDate" />
            </div></div>
        @endif
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save payment method') }}</button><a class="btn btn-secondary" href="{{ route('admin.payment-methods.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span></div>
    </form>
</div>
