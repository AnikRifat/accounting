<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ $accountId ? __('Edit account') : __('Add account') }}</h1><p class="muted">{{ __(':company · codes and names are unique within a company.', ['company' => $companyName]) }}</p></div></div>
    <form wire:submit="save" class="stack">
        @error('companyId')<p class="error" role="alert">{{ $message }}</p>@enderror
        <div class="panel"><div class="form-grid">
            <x-form.select name="type" :label="__('Type')" wire:model.live="type" :options="$types" :disabled="$hasEntries" :help="$hasEntries ? __('The type is fixed once the account has entries.') : null" />
            <x-form.input name="code" :label="__('Code')" wire:model="code" required maxlength="20" />
            <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" />
            @if($type === 'asset')<x-form.checkbox name="isCash" :label="__('Payment method (cash, bank or wallet that receives and pays money)')" wire:model.live="isCash" :disabled="$hasEntries" />@endif
            @if($type === 'asset' && $isCash)
                <x-form.select name="paymentType" :label="__('Payment type')" wire:model="paymentType" :options="$paymentTypes" />
                <x-form.input name="details" :label="__('Details')" wire:model="details" maxlength="255" :help="__('Optional, for example an account or wallet number.')" />
            @endif
            <x-form.checkbox name="isActive" :label="__('Account is active')" wire:model="isActive" />
        </div></div>
        @if($openingEntry)
            <div class="panel"><h2>{{ __('Opening balance') }}</h2><p class="muted">{{ __(':amount as of :date (:number).', ['amount' => \App\Support\Money::format($openingEntry->amount), 'date' => $openingEntry->entry_date->format('d M Y'), 'number' => $openingEntry->number]) }} @can('entries.update')<a class="text-link" href="{{ route('admin.entries.edit', $openingEntry) }}" wire:navigate>{{ __('Edit opening balance') }}</a>@endcan</p></div>
        @elseif($type === 'asset' && $isCash)
            <div class="panel"><h2>{{ __('Opening balance') }}</h2><div class="form-grid">
                <x-form.input name="openingBalance" :label="__('Opening balance (৳)')" wire:model="openingBalance" inputmode="decimal" autocomplete="off" :help="__('Optional. The money already in this account, posted against Opening Balance Equity.')" />
                <x-form.input name="openingDate" :label="__('As of date')" type="date" wire:model="openingDate" />
            </div></div>
        @endif
        <div class="actions"><button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save account') }}</button><a class="btn btn-secondary" href="{{ route('admin.accounts.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span></div>
    </form>
</div>
