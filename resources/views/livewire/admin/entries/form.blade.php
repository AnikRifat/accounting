<div class="page">
    <x-notices />
    <x-page-header :title="$title" :description="__(':company · amounts are in taka (৳).', ['company' => $companyName])" :back="route('admin.entries.index')" :back-label="__('Transactions')" />
    <form wire:submit="save" class="stack">
        @error('entry')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
        @if($type === 'opening')@error('creditAccountId')<x-alert tone="danger">{{ $message }}</x-alert>@enderror @endif
        @if($settled > 0)<x-alert tone="warning">{{ __(':amount has already been settled against this entry, so its party is fixed and the unpaid part can\'t go below that amount.', ['amount' => \App\Support\Money::format($settled)]) }}</x-alert>@endif
        <x-card :title="__('Entry details')">
            <div class="form-grid">
                <x-form.date name="entryDate" :label="__('Date')" wire:model="entryDate" required />
                @if($isBill)
                    <div class="stack-sm" wire:key="category-{{ $companyId }}">
                        <x-form.select name="categoryAccountId" :label="__('Category')" wire:model="categoryAccountId" :options="$categories" :autofocus="! $entryId" />
                        @can('accounts.manage')@if($canAdd)<button class="text-link justify-self-start text-sm" type="button" x-on:click="$wire.addingCategory = true">{{ __('+ Add a new category') }}</button>@endif @endcan
                    </div>
                    <div class="stack-sm" wire:key="party-{{ $companyId }}">
                        <x-form.select name="partyId" :label="__('Party')" wire:model.live="partyId" :options="$parties" :disabled="$settled > 0" :help="__('Who paid, received or was spent on. Required when part of the amount is unpaid.')" />
                        @can('parties.create')@if($settled === 0 && $canAdd)<button class="text-link justify-self-start text-sm" type="button" x-on:click="$wire.addingParty = true">{{ __('+ Add a new party') }}</button>@endif @endcan
                    </div>
                @else
                    @if($type === 'transfer')
                        <div wire:key="from-{{ $companyId }}"><x-form.select name="creditAccountId" :label="__('From')" wire:model="creditAccountId" :options="$methods" :autofocus="! $entryId" /></div>
                    @endif
                    <div wire:key="to-{{ $companyId }}"><x-form.select name="debitAccountId" :label="$type === 'transfer' ? __('To') : __('Payment method')" wire:model="debitAccountId" :options="$methods" /></div>
                    <x-form.input name="amount" :label="__('Amount (৳)')" wire:model="amount" required inputmode="decimal" autocomplete="off" :help="__('For example 1,25,000.50')" />
                @endif
            </div>
        </x-card>
        @if($isBill)
            <x-card :title="__('Payment')" :description="__('Split the amount across payment methods, or enter less than the total to record the rest as due.')">
                <div class="form-grid">
                    <x-form.input name="amount" :label="__('Total amount (৳)')" wire:model.live.debounce.400ms="amount" required inputmode="decimal" autocomplete="off" :help="__('For example 1,25,000.50')" />
                    <div class="stack-sm span-full" role="group" aria-labelledby="payments-heading">
                        <p id="payments-heading" class="field-label">{{ $type === 'income' ? __('Received now') : __('Paid now') }}</p>
                        @foreach($payments as $index => $payment)
                            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-start gap-3" wire:key="payment-{{ $companyId }}-{{ $index }}">
                                <x-form.select :name="'payments.'.$index.'.account'" :label="__('Payment method')" wire:model.live="payments.{{ $index }}.account" :options="$methods" />
                                <x-form.input :name="'payments.'.$index.'.amount'" :label="__('Amount (৳)')" wire:model.live.debounce.400ms="payments.{{ $index }}.amount" inputmode="decimal" autocomplete="off" />
                                @if(count($payments) > 1)
                                    <x-button class="mt-6" variant="ghost" icon="trash" :label="__('Remove this payment method')" wire:click="removePayment({{ $index }})" />
                                @endif
                            </div>
                        @endforeach
                        @error('payments')<p class="error">{{ $message }}</p>@enderror
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            @if($canAddPayment)<button class="text-link text-sm" type="button" wire:click="addPayment">{{ __('+ Add another payment method') }}</button>@else<span></span>@endif
                            <p class="muted text-sm" aria-live="polite">{{ $type === 'income' ? __('Received now') : __('Paid now') }} <x-money :value="$paidNow" /> · {{ __('Due') }} <x-money :value="$unpaid" /></p>
                        </div>
                    </div>
                    @if($showDue)
                        <x-form.date name="dueDate" :label="__('Due date for the rest')" wire:model="dueDate" required />
                    @endif
                    @if($canChoosePayer)
                        <div wire:key="payer-{{ $companyId }}"><x-form.select name="paidBy" :label="$type === 'income' ? __('Received by') : __('Paid by')" wire:model="paidBy" :options="$payers" required :help="__('Who handed over or took the money.')" /></div>
                    @else
                        <x-form.input name="payerName" :label="$type === 'income' ? __('Received by') : __('Paid by')" :value="$payerName" disabled required :help="$paidBy === (string) auth()->id() ? __('Recorded as you. Only the super admin can change it.') : __('Only the super admin can change it.')" />
                    @endif
                    <x-form.input name="recorderName" :label="__('Recorded by')" :value="$recorderName" disabled :help="$entryId ? __('Who created this entry.') : __('You, when you save.')" />
                </div>
            </x-card>
        @endif
        <x-card :title="__('Reference')">
            <div class="form-grid">
                <x-form.input name="reference" :label="__('Reference number')" wire:model="reference" maxlength="100" :help="__('Voucher, invoice or cheque number.')" />
                <x-form.input name="description" :label="__('Description')" wire:model="description" maxlength="500" />
                <div class="stack-sm span-full">
                    <x-form.image name="referenceFile" :label="__('Voucher, invoice or receipt file')" accept="image/jpeg,image/png,image/webp,application/pdf" :help="__('Optional. JPG, PNG, WebP or PDF. Photos can be cropped before upload.')" />
                    @if($currentFile)
                        <div class="flex flex-wrap items-center gap-3">
                            <a class="text-link inline-flex items-center gap-1" href="{{ $currentFileUrl }}" target="_blank" rel="noopener"><x-icon name="external" width="14" height="14" />{{ $currentFile->filename }}</a>
                            @if($referenceFile)<span class="muted">{{ __('The new file replaces it when you save.') }}</span>@else<x-form.checkbox name="removeReferenceFile" :label="__('Remove the current file')" wire:model="removeReferenceFile" />@endif
                        </div>
                    @endif
                </div>
            </div>
        </x-card>
        <x-form.actions :submit="__('Save entry')" :cancel="route('admin.entries.index')">
            @unless($entryId)<x-button variant="secondary" wire:click="save(true)" wire:loading.attr="disabled">{{ __('Save & add another') }}</x-button>@endunless
        </x-form.actions>
    </form>
    @if($isBill && $canAdd)
        @can('accounts.manage')
            <x-drawer id="add-category" wire:model="addingCategory" submit="addCategory" :title="$type === 'income' ? __('New income category') : __('New expense category')" :description="__('Adds the category to :company and selects it.', ['company' => $companyName])">
                <x-form.input name="newCategoryName" :label="__('Category name')" wire:model="newCategoryName" required maxlength="150" autocomplete="off" autofocus />
                <x-slot:footer><x-button type="submit" wire:loading.attr="disabled" wire:target="addCategory">{{ __('Add category') }}</x-button><x-button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-button></x-slot:footer>
            </x-drawer>
        @endcan
        @can('parties.create')@if($settled === 0)
            <x-drawer id="add-party" wire:model="addingParty" submit="addParty" :title="__('New party')" :description="__('Adds the party to :company and selects it.', ['company' => $companyName])">
                <x-form.input name="newPartyName" :label="__('Party name')" wire:model="newPartyName" required maxlength="150" autocomplete="off" autofocus />
                <x-form.input name="newPartyPhone" :label="__('Phone')" type="tel" wire:model="newPartyPhone" maxlength="40" autocomplete="off" />
                <x-slot:footer><x-button type="submit" wire:loading.attr="disabled" wire:target="addParty">{{ __('Add party') }}</x-button><x-button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-button></x-slot:footer>
            </x-drawer>
        @endif @endcan
    @endif
</div>
