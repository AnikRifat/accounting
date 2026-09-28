<div class="page">
    <x-notices />
    <x-page-header :title="$heading" :description="__(':company · amounts are in taka (৳).', ['company' => $companyName])"
        :back="$stored ? route('admin.sales.documents.show', $stored) : route('admin.sales.'.$documentType->slug().'.index')"
        :back-label="$stored ? $stored->displayNumber() : $documentType->pluralLabel()" />
    <form wire:submit="save" class="stack">
        @error('document')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
        @if($stored?->isPosted())<x-alert tone="warning">{{ __('This :type is posted to the books. Saving changes updates its journal entry.', ['type' => mb_strtolower($documentType->label())]) }}</x-alert>@endif

        <x-card :title="__('Details')">
            <div class="form-grid">
                @if($documentType->isNote())
                    @if($sources !== [])
                        <div class="span-full"><x-form.select name="sourceId" :label="__('Against :type', ['type' => mb_strtolower($documentType->noteFor()->label())])" wire:model.live="sourceId" :options="$sources" required :help="__('Only issued documents of this company can be chosen. The note starts as a copy of it; change the lines to what you are crediting.')" /></div>
                    @elseif($source)
                        <div class="span-full field"><span class="field-label">{{ __('Against :type', ['type' => mb_strtolower($documentType->noteFor()->label())]) }}</span><p><a class="text-link" href="{{ route('admin.sales.documents.show', $source) }}" wire:navigate>{{ $source->number }}</a></p></div>
                    @else
                        <div class="span-full">@error('sourceId')<x-alert tone="danger">{{ $message }}</x-alert>@else<x-alert tone="warning">{{ __('There is no issued :type to issue a note against yet.', ['type' => mb_strtolower($documentType->noteFor()->label())]) }}</x-alert>@enderror</div>
                    @endif
                @endif
                <div wire:key="party-{{ $companyId }}">
                    @if($documentType === \App\Enums\DocumentType::Contract)
                        <x-form.select name="partyId" :label="$partyLabel" wire:model="partyId" :options="$parties" />
                    @else
                        <x-form.select name="partyId" :label="$partyLabel" wire:model="partyId" :options="$parties" required :disabled="$documentType->isNote()" :help="$documentType->isNote() ? __('A note is for the same party as its :type.', ['type' => mb_strtolower($documentType->noteFor()->label())]) : null" />
                    @endif
                </div>
                <x-form.input name="title" :label="__('Title')" wire:model="title" maxlength="150" :help="$documentType === \App\Enums\DocumentType::Contract ? __('What the contract is for.') : __('Optional, e.g. Website redesign.')" />
                <x-form.date name="issueDate" :label="__('Date')" wire:model="issueDate" required />
                @if($documentType->dueLabel())
                    <x-form.date name="dueDate" :label="$documentType->dueLabel()" wire:model="dueDate" />
                @endif
                <x-form.input name="reference" :label="__('Reference')" wire:model="reference" maxlength="100" :help="__('PO, order or their reference number.')" />
                <div wire:key="template-{{ $companyId }}"><x-form.select name="templateId" :label="__('Template')" wire:model="templateId" :options="$templates" /></div>
                @foreach($fields as $field)
                    <div wire:key="field-{{ $field->id }}">
                        @if($field->kind === 'date')
                            <x-form.date :name="'customValues.'.$field->id" :label="$field->label.($field->is_required ? ' *' : '')" wire:model="customValues.{{ $field->id }}" />
                        @else
                            <x-form.input :name="'customValues.'.$field->id" :label="$field->label.($field->is_required ? ' *' : '')" wire:model="customValues.{{ $field->id }}" :type="$field->kind === 'number' ? 'number' : 'text'" :step="$field->kind === 'number' ? 'any' : null" maxlength="255" />
                        @endif
                    </div>
                @endforeach
            </div>
        </x-card>

        @if($documentType->hasLines())
            <x-card :title="__('Lines')" :description="__('Prices are in taka before VAT, unless “Prices include VAT” is on. Choosing an item fills the line.')">
                <div class="stack">
                    @error('lines')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
                    @foreach($lines as $index => $line)
                        <div class="stack-sm rounded-lg border border-slate-200 p-3 dark:border-slate-700" wire:key="line-{{ $line['key'] ?? $index }}" role="group" aria-label="{{ __('Line :number', ['number' => $index + 1]) }}">
                            <div class="grid gap-3 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]">
                                <x-form.select :name="'lines.'.$index.'.item'" :label="__('Item')" wire:model.live="lines.{{ $index }}.item" :options="$items" />
                                <x-form.input :name="'lines.'.$index.'.description'" :label="__('Description')" wire:model="lines.{{ $index }}.description" maxlength="500" required />
                            </div>
                            <div class="grid grid-cols-2 gap-3 md:grid-cols-6">
                                <x-form.input :name="'lines.'.$index.'.quantity'" :label="__('Quantity')" wire:model.live.debounce.400ms="lines.{{ $index }}.quantity" inputmode="decimal" autocomplete="off" required />
                                <x-form.input :name="'lines.'.$index.'.unit'" :label="__('Unit')" wire:model="lines.{{ $index }}.unit" maxlength="20" :placeholder="__('pcs, hrs…')" />
                                <x-form.input :name="'lines.'.$index.'.price'" :label="__('Unit price (৳)')" wire:model.live.debounce.400ms="lines.{{ $index }}.price" inputmode="decimal" autocomplete="off" required />
                                <x-form.select :name="'lines.'.$index.'.discountType'" :label="__('Discount')" wire:model.live="lines.{{ $index }}.discountType" :options="$discountTypes" />
                                <x-form.input :name="'lines.'.$index.'.discount'" :label="($line['discountType'] ?? '') === 'percent' ? __('Discount (%)') : __('Discount (৳)')" wire:model.live.debounce.400ms="lines.{{ $index }}.discount" inputmode="decimal" autocomplete="off" :disabled="($line['discountType'] ?? '') === ''" />
                                <x-form.input :name="'lines.'.$index.'.vat'" :label="__('VAT (%)')" wire:model.live.debounce.400ms="lines.{{ $index }}.vat" inputmode="decimal" autocomplete="off" />
                            </div>
                            <div class="flex flex-wrap items-end justify-between gap-3">
                                <div class="min-w-56 flex-1"><x-form.select :name="'lines.'.$index.'.account'" :label="$documentType->isPurchase() ? __('Expense category') : __('Income category')" wire:model="lines.{{ $index }}.account" :options="$categories" /></div>
                                <p class="muted" aria-live="polite">{{ __('Line total') }} <strong><x-money :value="$preview['lines'][$index] ?? 0" /></strong></p>
                                <div class="btn-group">
                                    <x-button variant="ghost" size="sm" wire:click="moveLine({{ $index }}, -1)" :disabled="$loop->first" :label="__('Move line :number up', ['number' => $index + 1])">↑</x-button>
                                    <x-button variant="ghost" size="sm" wire:click="moveLine({{ $index }}, 1)" :disabled="$loop->last" :label="__('Move line :number down', ['number' => $index + 1])">↓</x-button>
                                    @if(count($lines) > 1)<x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="removeLine({{ $index }})" :label="__('Remove line :number', ['number' => $index + 1])" />@endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                    @if(count($lines) < \App\Services\DocumentService::MAX_LINES)
                        <div><x-button variant="secondary" icon="plus" wire:click="addLine">{{ __('Add line') }}</x-button></div>
                    @endif
                </div>
            </x-card>

            <x-card :title="__('Totals')">
                <div class="grid-2">
                    <div class="stack">
                        <div class="form-grid">
                            <x-form.select name="discountType" :label="__('Discount on the whole document')" wire:model.live="discountType" :options="$discountTypes" />
                            <x-form.input name="discountValue" :label="$discountType === 'percent' ? __('Discount (%)') : __('Discount (৳)')" wire:model.live.debounce.400ms="discountValue" inputmode="decimal" autocomplete="off" :disabled="$discountType === ''" />
                        </div>
                        <x-form.checkbox name="taxInclusive" :label="__('Prices include VAT')" wire:model.live="taxInclusive" />
                    </div>
                    <dl class="stack-sm" aria-live="polite">
                        @foreach([__('Subtotal') => $preview['subtotal'], __('Discount') => -$preview['discount_total'], __('VAT') => $preview['tax_total']] as $label => $amount)
                            <div class="flex justify-between gap-3"><dt class="muted">{{ $label }}</dt><dd><x-money :value="$amount" /></dd></div>
                        @endforeach
                        <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 dark:border-slate-700"><dt><strong>{{ __('Total') }}</strong></dt><dd><strong><x-money :value="$preview['total']" /></strong></dd></div>
                        <p class="field-help">{{ __('A preview; totals are worked out again when you save.') }}</p>
                    </dl>
                </div>
            </x-card>
        @endif

        @if($documentType === \App\Enums\DocumentType::Contract)
            <x-card :title="__('Contract text')">
                <div class="field">
                    <label for="body">{{ __('Body') }}</label>
                    <textarea id="body" name="body" class="form-control h-auto py-2" rows="16" wire:model="body" maxlength="100000" aria-describedby="body-help" aria-invalid="{{ $errors->has('body') ? 'true' : 'false' }}"></textarea>
                    @error('body')<p class="error">{{ $message }}</p>@else<p id="body-help" class="field-help">{{ __('Placeholders filled in when printed: {party.name}, {party.address}, {company.name}, {document.number}, {document.date}.') }}</p>@enderror
                </div>
            </x-card>
        @endif

        <x-card :title="__('Notes and terms')">
            <div class="form-grid">
                @foreach(['notes' => __('Notes'), 'terms' => __('Terms and conditions')] as $name => $label)
                    <div class="field">
                        <label for="{{ $name }}">{{ $label }}</label>
                        <textarea id="{{ $name }}" name="{{ $name }}" class="form-control h-auto py-2" rows="4" wire:model="{{ $name }}" maxlength="5000" aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"></textarea>
                        @error($name)<p class="error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </x-card>

        @if($documentType->isPostable())
            <x-card :title="__('Accounts')">
                @if($stored?->isPosted())
                    <p>{{ __('Posted to the books. It stays posted; void it to reverse the entry.') }}</p>
                @elseif($documentType->isNote())
                    <p class="muted">
                        @if($source?->isPosted())
                            {{ __('This note will be posted to the books, because :number is posted.', ['number' => $source->number]) }}
                        @else
                            {{ __('This note follows its :type: it stays out of the books unless that is posted.', ['type' => mb_strtolower($documentType->noteFor()->label())]) }}
                        @endif
                    </p>
                @elseif($canPost)
                    <x-form.checkbox name="postToAccounts" :label="__('Post to accounts')" wire:model="postToAccounts" />
                    <p class="field-help mt-1">{{ __('Posts this :type to the books: income or expense, VAT and the receivable or payable.', ['type' => mb_strtolower($documentType->label())]) }}</p>
                @else
                    <p class="muted">{{ $postToAccounts ? __('This :type will be posted to the books when it is issued.', ['type' => mb_strtolower($documentType->label())]) : __('Not posted to the books.') }}</p>
                @endif
            </x-card>
        @endif

        <x-form.actions :submit="$isDraft ? __('Save draft') : __('Save changes')" :cancel="$stored ? route('admin.sales.documents.show', $stored) : route('admin.sales.'.$documentType->slug().'.index')">
            @if($canIssue)<x-button variant="secondary" icon="check" wire:click="save(true)" wire:loading.attr="disabled">{{ __('Save and issue') }}</x-button>@endif
        </x-form.actions>
    </form>
</div>
