<div class="page">
    <x-notices />
    <x-page-header :title="$accountId ? __('Edit account') : __('Add account')" :description="__(':company · codes and names are unique within a company.', ['company' => $companyName])" :back="route('admin.accounts.index')" :back-label="__('Chart of accounts')" />
    <form wire:submit="save" class="stack">
        @error('companyId')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
        <x-card :title="__('Account details')">
            <div class="form-grid">
                <x-form.select name="type" :label="__('Type')" wire:model.live="type" :options="$types" :disabled="$hasEntries" :help="$hasEntries ? __('The type is fixed once the account has entries.') : null" />
                <x-form.input name="code" :label="__('Code')" wire:model="code" required maxlength="20" />
                <x-form.input name="name" :label="__('Name')" wire:model="name" required maxlength="150" />
                @if($type === 'asset')<div class="span-full"><x-form.checkbox name="isCash" :label="__('Payment method (cash, bank or wallet that receives and pays money)')" wire:model.live="isCash" :disabled="$hasEntries" /></div>@endif
                @if($type === 'asset' && $isCash)
                    <x-form.select name="paymentType" :label="__('Payment type')" wire:model="paymentType" :options="$paymentTypes" />
                    <x-form.input name="details" :label="__('Details')" wire:model="details" maxlength="255" :help="__('Optional, for example an account or wallet number.')" />
                @endif
            </div>
            <x-form.checkbox name="isActive" :label="__('Account is active')" wire:model="isActive" />
        </x-card>
        @if($openingEntry)
            <x-card :title="__('Opening balance')"><p class="muted">{{ __(':amount as of :date (:number).', ['amount' => \App\Support\Money::format($openingEntry->amount), 'date' => $openingEntry->entry_date->format('d M Y'), 'number' => $openingEntry->number]) }} @can('entries.update')<a class="text-link" href="{{ route('admin.entries.edit', $openingEntry) }}" wire:navigate>{{ __('Edit opening balance') }}</a>@endcan</p></x-card>
        @elseif($type === 'asset' && $isCash)
            <x-card :title="__('Opening balance')">
                <div class="form-grid">
                    <x-form.input name="openingBalance" :label="__('Opening balance (৳)')" wire:model="openingBalance" inputmode="decimal" autocomplete="off" :help="__('Optional. The money already in this account, posted against Opening Balance Equity.')" />
                    <x-form.date name="openingDate" :label="__('As of date')" wire:model="openingDate" />
                </div>
            </x-card>
        @endif
        <x-form.actions :submit="__('Save account')" :cancel="route('admin.accounts.index')" />
    </form>
</div>
