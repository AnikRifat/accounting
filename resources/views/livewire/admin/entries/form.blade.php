<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ $title }}</h1><p class="muted">{{ __('Amounts are in taka (৳).') }}</p></div></div>
    <form wire:submit="save" class="stack">
        @error('entry')<p class="error" role="alert">{{ $message }}</p>@enderror
        @if($type === 'opening')@error('creditAccountId')<p class="error" role="alert">{{ $message }}</p>@enderror @endif
        @if($settled > 0)<p class="notice" role="status">{{ __(':amount has already been settled against this entry, so its party is fixed and the unpaid part can\'t go below that amount.', ['amount' => \App\Support\Money::format($settled)]) }}</p>@endif
        <div class="panel"><div class="form-grid">
            <x-form.select name="companyId" :label="__('Company')" wire:model.live="companyId" :options="['' => __('Select a company')] + $companies" :disabled="$entryId !== null" :help="$entryId ? __('The company of an entry cannot be changed.') : null" />
            <x-form.input name="entryDate" :label="__('Date')" type="date" wire:model="entryDate" required />
            @if($isBill)
                <div wire:key="category-{{ $companyId }}"><x-form.select name="categoryAccountId" :label="__('Category')" wire:model="categoryAccountId" :options="$categories" :autofocus="! $entryId" /></div>
                <div class="field" wire:key="party-{{ $companyId }}">
                    <x-form.input name="partySearch" :label="__('Find party')" type="search" wire:model.live.debounce.300ms="partySearch" maxlength="100" autocomplete="off" :disabled="$settled > 0" :help="__('Type a name or phone number to narrow the list.')" />
                    <x-form.select name="partyId" :label="__('Party')" wire:model.live="partyId" :options="$parties" :disabled="$settled > 0" :help="__('Who paid, received or was spent on. Required when part of the amount is unpaid.')" />
                    @can('parties.create')@if($settled === 0)
                        @if($addingParty)
                            <div class="stack rounded-lg border border-[var(--line)] p-4">
                                <x-form.input name="newPartyName" :label="__('New party name')" wire:model="newPartyName" maxlength="150" />
                                <x-form.input name="newPartyPhone" :label="__('Phone')" type="tel" wire:model="newPartyPhone" maxlength="40" />
                                <div class="flex gap-3"><button class="btn btn-secondary" type="button" wire:click="addParty">{{ __('Add party') }}</button><button class="btn btn-secondary" type="button" wire:click="$set('addingParty', false)">{{ __('Cancel') }}</button></div>
                            </div>
                        @else
                            <button class="text-link justify-self-start" type="button" wire:click="$set('addingParty', true)">{{ __('+ Add a new party') }}</button>
                        @endif
                    @endif @endcan
                </div>
                <x-form.input name="amount" :label="__('Total amount (৳)')" wire:model.live.debounce.400ms="amount" required inputmode="decimal" autocomplete="off" :help="__('For example 1,25,000.50')" />
                <x-form.input name="paidAmount" :label="$type === 'income' ? __('Received now (৳)') : __('Paid now (৳)')" wire:model.live.debounce.400ms="paidAmount" required inputmode="decimal" autocomplete="off" :help="__('Enter less than the total to record the rest as due.')" />
                <div wire:key="method-{{ $companyId }}"><x-form.select name="paymentAccountId" :label="__('Payment method')" wire:model="paymentAccountId" :options="$methods" /></div>
                @if($showDue)
                    <x-form.input name="dueDate" :label="__('Due date for the rest')" type="date" wire:model="dueDate" required />
                @endif
            @else
                @if($type === 'transfer')
                    <div wire:key="from-{{ $companyId }}"><x-form.select name="creditAccountId" :label="__('From')" wire:model="creditAccountId" :options="$methods" :autofocus="! $entryId" /></div>
                @endif
                <div wire:key="to-{{ $companyId }}"><x-form.select name="debitAccountId" :label="$type === 'transfer' ? __('To') : __('Payment method')" wire:model="debitAccountId" :options="$methods" /></div>
                <x-form.input name="amount" :label="__('Amount (৳)')" wire:model="amount" required inputmode="decimal" autocomplete="off" :help="__('For example 1,25,000.50')" />
            @endif
            <x-form.input name="reference" :label="__('Reference')" wire:model="reference" maxlength="100" :help="__('Voucher, invoice or cheque number.')" />
            <x-form.input name="description" :label="__('Description')" wire:model="description" maxlength="500" />
        </div></div>
        <div class="actions">
            <button class="btn" type="submit" wire:loading.attr="disabled">{{ __('Save entry') }}</button>
            @unless($entryId)<button class="btn btn-secondary" type="button" wire:click="save(true)" wire:loading.attr="disabled">{{ __('Save & add another') }}</button>@endunless
            <a class="btn btn-secondary" href="{{ route('admin.entries.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span>
        </div>
    </form>
</div>
