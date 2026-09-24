<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ $title }}</h1><p class="muted">{{ __(':company · amounts are in taka (৳).', ['company' => $companyName]) }}</p></div></div>
    <form wire:submit="save" class="stack">
        @error('entry')<p class="error" role="alert">{{ $message }}</p>@enderror
        @if($type === 'opening')@error('creditAccountId')<p class="error" role="alert">{{ $message }}</p>@enderror @endif
        @if($settled > 0)<p class="notice" role="status">{{ __(':amount has already been settled against this entry, so its party is fixed and the unpaid part can\'t go below that amount.', ['amount' => \App\Support\Money::format($settled)]) }}</p>@endif
        <div class="panel"><div class="form-grid">
            <x-form.input name="entryDate" :label="__('Date')" type="date" wire:model="entryDate" required />
            @if($isBill)
                <div class="field" wire:key="category-{{ $companyId }}">
                    <x-form.select name="categoryAccountId" :label="__('Category')" wire:model="categoryAccountId" :options="$categories" :autofocus="! $entryId" />
                    @can('accounts.manage')@if($canAdd)<button class="text-link justify-self-start" type="button" x-on:click="$wire.addingCategory = true">{{ __('+ Add a new category') }}</button>@endif @endcan
                </div>
                <div class="field" wire:key="party-{{ $companyId }}">
                    <x-form.select name="partyId" :label="__('Party')" wire:model.live="partyId" :options="$parties" :disabled="$settled > 0" :help="__('Who paid, received or was spent on. Required when part of the amount is unpaid.')" />
                    @can('parties.create')@if($settled === 0 && $canAdd)<button class="text-link justify-self-start" type="button" x-on:click="$wire.addingParty = true">{{ __('+ Add a new party') }}</button>@endif @endcan
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
    @if($isBill && $canAdd)
        @can('accounts.manage')
            <x-drawer id="add-category" wire:model="addingCategory" submit="addCategory" :title="$type === 'income' ? __('New income category') : __('New expense category')" :description="__('Adds the category to :company and selects it.', ['company' => $companyName])">
                <x-form.input name="newCategoryName" :label="__('Category name')" wire:model="newCategoryName" required maxlength="150" autocomplete="off" autofocus />
                <x-slot:footer><button class="btn" type="submit" wire:loading.attr="disabled" wire:target="addCategory">{{ __('Add category') }}</button><button class="btn btn-secondary" type="button" x-on:click="open = false">{{ __('Cancel') }}</button></x-slot:footer>
            </x-drawer>
        @endcan
        @can('parties.create')@if($settled === 0)
            <x-drawer id="add-party" wire:model="addingParty" submit="addParty" :title="__('New party')" :description="__('Adds the party to :company and selects it.', ['company' => $companyName])">
                <x-form.input name="newPartyName" :label="__('Party name')" wire:model="newPartyName" required maxlength="150" autocomplete="off" autofocus />
                <x-form.input name="newPartyPhone" :label="__('Phone')" type="tel" wire:model="newPartyPhone" maxlength="40" autocomplete="off" />
                <x-slot:footer><button class="btn" type="submit" wire:loading.attr="disabled" wire:target="addParty">{{ __('Add party') }}</button><button class="btn btn-secondary" type="button" x-on:click="open = false">{{ __('Cancel') }}</button></x-slot:footer>
            </x-drawer>
        @endif @endcan
    @endif
</div>
