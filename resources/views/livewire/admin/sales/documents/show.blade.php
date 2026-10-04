<div class="page">
    <x-notices />
    <x-page-header :title="$documentType->label().' '.$document->displayNumber()" :back="route('admin.sales.'.$documentType->slug().'.index')" :back-label="$documentType->pluralLabel()">
        <x-slot:meta>
            <p class="btn-group mt-2">
                <x-badge :tone="$document->status->tone()">{{ $document->status->label() }}</x-badge>
                <x-badge.due-status :status="$dueStatus" />
                @if($document->isPosted())
                    <a href="{{ route('admin.entries.index', ['search' => $document->entry?->number]) }}" wire:navigate title="{{ __('Open the journal entry') }}"><x-badge tone="primary">{{ __('Posted · :number', ['number' => $document->entry?->number]) }}</x-badge></a>
                @endif
                <span class="muted">{{ $document->company->name }}</span>
            </p>
        </x-slot:meta>
        <x-slot:actions>
            @if($canEdit)<x-button variant="secondary" icon="pencil" :href="route('admin.sales.documents.edit', $document)">{{ __('Edit') }}</x-button>@endif
            @if($document->isDraft())
                @can('sales.update')<x-button icon="check" wire:click="issue" wire:loading.attr="disabled">{{ __('Issue') }}</x-button>@endcan
            @endif
            <x-button variant="secondary" icon="printer" :href="route('admin.sales.documents.print', $document)" :navigate="false" target="_blank" rel="noopener">{{ __('Print') }}</x-button>
            <x-button variant="secondary" icon="download" :href="route('admin.sales.documents.pdf', $document)" :navigate="false">{{ __('PDF') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @error('action')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    @if($document->isVoid())
        <x-alert tone="warning">{{ __('Voided :date: :reason', ['date' => $document->voided_at?->format('d M Y'), 'reason' => $document->void_reason]) }}</x-alert>
    @endif

    <div class="stats">
        <x-stat :label="$documentType->isPurchase() ? __('Supplier') : __('Customer')" emoji="🤝" :value="$document->party?->name ?? '—'" :hint="$document->party?->phone" />
        <x-stat :label="__('Total')" emoji="🧾" tone="info" :hint="__('Date :date', ['date' => $document->issue_date->format('d M Y')])"><x-slot:value><x-money :value="$document->total" /></x-slot:value></x-stat>
        @if($balance !== null)
            <x-stat :label="$documentType->isPurchase() ? __('Still to pay') : __('Still to receive')" emoji="⏳" :tone="$balance > 0 ? 'warning' : 'success'" :hint="$document->due_date ? __('Due :date', ['date' => $document->due_date->format('d M Y')]) : null"><x-slot:value><x-money :value="max(0, $balance)" /></x-slot:value></x-stat>
        @elseif($document->due_date && $documentType->dueLabel())
            <x-stat :label="$documentType->dueLabel()" emoji="📅" :value="$document->due_date->format('d M Y')" />
        @endif
    </div>

    @php($canSend = $document->isOpen() && auth()->user()->can('sales.send'))
    @if(! $document->isVoid())
        <x-card :title="__('Actions')">
            <div class="btn-group flex-wrap">
                @if($canPost)
                    <x-button icon="check" wire:click="postToAccounts" wire:confirm="{{ __('Post :number to the books? This posts the document, its issued notes and the payments recorded on it as journal entries. A posted document can\'t be switched back; void it instead.', ['number' => $document->number]) }}">{{ __('Post to accounts') }}</x-button>
                @endif
                @if($canRecordPayment)
                    <x-button icon="wallet" wire:click="openPayment">{{ $documentType->isPurchase() ? __('Record payment') : __('Record receipt') }}</x-button>
                @endif
                @if($documentType->note() && $document->isOpen())
                    @can('sales.create')<x-button variant="secondary" icon="plus" wire:click="createNote">{{ __('New :type', ['type' => mb_strtolower($documentType->note()->label())]) }}</x-button>@endcan
                @endif
                @if($documentType === \App\Enums\DocumentType::Invoice && ! $document->isVoid())
                    @can('sales.update')<x-button variant="secondary" icon="calendar" :href="route('admin.sales.recurring.index', ['sheet' => 'create:'.$document->id])">{{ __('Make recurring') }}</x-button>@endcan
                @endif
                @foreach($conversions as $target)
                    @can($target === $documentType->convertsTo() ? 'sales.update' : 'sales.create')
                        <x-button variant="secondary" icon="chevron-right" wire:click="convert('{{ $target->value }}')" wire:key="convert-{{ $target->value }}">{{ $target === \App\Enums\DocumentType::DeliveryNote ? __('Create delivery note') : __('Convert to :type', ['type' => mb_strtolower($target->label())]) }}</x-button>
                    @endcan
                @endforeach
                @if($documentType->isOffer())
                    @can('sales.update')
                        @if($document->status === \App\Enums\DocumentStatus::Issued)
                            <x-button variant="secondary" icon="check" wire:click="respond('accepted')">{{ __('Mark accepted') }}</x-button>
                            <x-button variant="secondary" icon="x" wire:click="respond('declined')">{{ __('Mark declined') }}</x-button>
                        @elseif(in_array($document->status, [\App\Enums\DocumentStatus::Accepted, \App\Enums\DocumentStatus::Declined], true))
                            <x-button variant="secondary" icon="rotate" wire:click="respond('issued')">{{ __('Reopen') }}</x-button>
                        @endif
                    @endcan
                @endif
                @if($canSend)
                    <x-button variant="secondary" icon="mail" wire:click="openEmail">{{ __('Email') }}</x-button>
                @endif
                @if($document->isOpen())
                    @can('sales.void')<x-button variant="danger" icon="ban" wire:click="openVoid">{{ __('Void') }}</x-button>@endcan
                @endif
                @if($document->isDraft())
                    @can('sales.delete')<x-button variant="danger" icon="trash" wire:click="deleteDraft" wire:confirm="{{ __('Delete this draft? This can\'t be undone.') }}">{{ __('Delete draft') }}</x-button>@endcan
                @endif
            </div>
            @if($document->isDraft())<p class="field-help mt-3">{{ __('A draft has no number yet. Issue it to number it and send it.') }}</p>@endif
        </x-card>
    @endif

    <div class="grid-2">
        @if($documentType->isPayable())
            <x-card :title="$documentType->isPurchase() ? __('Payments') : __('Receipts')" :description="$document->isPosted() ? __('Posted payments are journal entries: void or edit them from Transactions.') : null" flush>
                <x-table :caption="__('Payments')">
                    <x-slot:head><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th class="num">{{ __('Amount') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                    @if($document->isPosted())
                        @forelse($settlements as $settlement)
                            <tr wire:key="settlement-{{ $settlement->id }}" @class(['is-voided' => $settlement->isVoided()])>
                                <td class="nowrap">{{ $settlement->entry_date->format('d M Y') }}<p class="muted"><a class="text-link" href="{{ route('admin.entries.index', ['search' => $settlement->number]) }}" wire:navigate>{{ $settlement->number }}</a>@if($settlement->isVoided()) · {{ __('Voided') }}@endif</p></td>
                                <td>{{ $settlement->paymentAccount()?->name ?? '—' }}@if($settlement->reference)<p class="muted">{{ $settlement->reference }}</p>@endif</td>
                                <td class="num">@if($settlement->isVoided())<s><x-money :value="$settlement->amount" /></s>@else<x-money :value="$settlement->amount" />@endif</td>
                                <td><div class="row-actions">@unless($settlement->isVoided())<x-button variant="ghost" size="sm" icon="printer" :href="route('admin.sales.documents.receipt', ['document' => $document, 'kind' => 'entry', 'id' => $settlement->id])" :navigate="false" target="_blank" rel="noopener">{{ __('Receipt') }}</x-button>@endunless</div></td>
                            </tr>
                        @empty
                            <x-table.empty colspan="4" emoji="💸">{{ __('Nothing recorded yet.') }}</x-table.empty>
                        @endforelse
                    @else
                        @forelse($payments as $payment)
                            <tr wire:key="payment-{{ $payment->id }}">
                                <td class="nowrap">{{ $payment->paid_on->format('d M Y') }}</td>
                                <td>{{ $payment->account?->name ?? '—' }}@if($payment->reference)<p class="muted">{{ $payment->reference }}</p>@endif</td>
                                <td class="num"><x-money :value="$payment->amount" /></td>
                                <td><div class="row-actions">
                                    <x-button variant="ghost" size="sm" icon="printer" :href="route('admin.sales.documents.receipt', ['document' => $document, 'kind' => 'payment', 'id' => $payment->id])" :navigate="false" target="_blank" rel="noopener">{{ __('Receipt') }}</x-button>
                                    @can('sales.payments')<x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="deletePayment({{ $payment->id }})" wire:confirm="{{ __('Delete this payment of :amount?', ['amount' => \App\Support\Money::format($payment->amount)]) }}" :label="__('Delete payment')" />@endcan
                                </div></td>
                            </tr>
                        @empty
                            <x-table.empty colspan="4" emoji="💸">{{ __('Nothing recorded yet.') }}</x-table.empty>
                        @endforelse
                    @endif
                </x-table>
            </x-card>

            <x-card :title="$documentType->note()->pluralLabel()" flush>
                <x-table :caption="$documentType->note()->pluralLabel()">
                    <x-slot:head><th>{{ __('Number') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Total') }}</th></x-slot:head>
                    @forelse($notes as $note)
                        <tr wire:key="note-{{ $note->id }}" @class(['is-voided' => $note->isVoid()])>
                            <td class="nowrap"><a class="text-link" href="{{ route('admin.sales.documents.show', $note) }}" wire:navigate>{{ $note->displayNumber() }}</a><p class="muted">{{ $note->issue_date->format('d M Y') }}</p></td>
                            <td><x-badge :tone="$note->status->tone()">{{ $note->status->label() }}</x-badge></td>
                            <td class="num"><x-money :value="$note->total" /></td>
                        </tr>
                    @empty
                        <x-table.empty colspan="3" emoji="📝">{{ __('No :types yet.', ['types' => mb_strtolower($documentType->note()->pluralLabel())]) }}</x-table.empty>
                    @endforelse
                </x-table>
            </x-card>
        @endif

        @if($document->source || $related->isNotEmpty())
            <x-card :title="__('Related documents')">
                <ul class="stack-sm">
                    @if($document->source)
                        <li>{{ __('Made from') }} <a class="text-link" href="{{ route('admin.sales.documents.show', $document->source) }}" wire:navigate>{{ $document->source->type->label() }} {{ $document->source->number }}</a></li>
                    @endif
                    @foreach($related as $item)
                        <li wire:key="related-{{ $item->id }}"><a class="text-link" href="{{ route('admin.sales.documents.show', $item) }}" wire:navigate>{{ $item->type->label() }} {{ $item->displayNumber() }}</a> <x-badge :tone="$item->status->tone()">{{ $item->status->label() }}</x-badge></li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        @if($canSend)
            <x-card :title="__('Share link')" :description="__('Anyone with the link can view and download this document, without signing in.')">
                @if($shareUrl)
                    <div class="stack" x-data="{ copied: false }">
                        <div class="flex gap-2">
                            <input id="share-url" class="form-control" type="text" readonly value="{{ $shareUrl }}" aria-label="{{ __('Share link') }}" x-on:focus="$el.select()">
                            <x-button variant="secondary" x-on:click="navigator.clipboard.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></x-button>
                        </div>
                        <p class="muted">{{ $document->share_expires_at ? __('Expires :date.', ['date' => $document->share_expires_at->format('d M Y, h:i A')]) : __('Works until revoked.') }}</p>
                        <div class="btn-group">
                            <x-button icon="phone" :href="$whatsApp" :navigate="false" target="_blank" rel="noopener">{{ __('Send on WhatsApp') }}</x-button>
                            <x-button variant="danger" icon="ban" wire:click="revokeShare" wire:confirm="{{ __('Revoke the link? Anyone who has it can no longer open the document.') }}">{{ __('Revoke') }}</x-button>
                        </div>
                    </div>
                @else
                    <form class="flex flex-wrap items-end gap-3" wire:submit="share">
                        <div class="min-w-48"><x-form.select name="shareDays" :label="__('Link works for')" wire:model="shareDays" :options="$shareOptions" /></div>
                        <x-button type="submit" icon="external">{{ __('Create link') }}</x-button>
                    </form>
                @endif
            </x-card>
        @endif

        <x-card :title="__('Activity')">
            <ol class="stack-sm">
                @forelse($document->activities as $activity)
                    <li wire:key="activity-{{ $activity->id }}">
                        <strong>{{ $events[$activity->event] ?? $activity->event }}</strong>@if($activity->details) · {{ $activity->details }}@endif
                        <p class="muted">{{ $activity->created_at?->format('d M Y, h:i A') }} · {{ $activity->user?->name ?? __('Customer') }}</p>
                    </li>
                @empty
                    <li class="muted">{{ __('Nothing yet.') }}</li>
                @endforelse
            </ol>
        </x-card>
    </div>

    <x-card :title="__('Preview')" flush>
        <iframe src="{{ route('admin.sales.documents.print', [$document, 'embed' => 1]) }}" title="{{ __('Preview of :number', ['number' => $document->displayNumber()]) }}" class="block h-[1100px] w-full border-0 bg-white" loading="lazy"></iframe>
    </x-card>

    @if($canRecordPayment)
        <x-drawer id="record-payment" wire:model="recordingPayment" submit="recordPayment" :title="$documentType->isPurchase() ? __('Record payment') : __('Record receipt')" :description="__(':amount still owed on :number.', ['amount' => \App\Support\Money::format(max(0, (int) $balance)), 'number' => $document->number])">
            <x-form.date name="paymentDate" :label="__('Date')" wire:model="paymentDate" required />
            <x-form.input name="paymentAmount" :label="__('Amount (৳)')" wire:model="paymentAmount" required inputmode="decimal" autocomplete="off" autofocus />
            <x-form.select name="paymentAccountId" :label="__('Payment method')" wire:model="paymentAccountId" :options="$methods" required />
            <x-form.input name="paymentReference" :label="__('Reference')" wire:model="paymentReference" maxlength="100" :help="__('Cheque, transaction or voucher number.')" />
            @if($payers !== [])
                <x-form.select name="paidBy" :label="$documentType->isPurchase() ? __('Paid by') : __('Received by')" wire:model="paidBy" :options="$payers" />
            @endif
            @if($document->isPosted())<p class="field-help">{{ __('Recorded in the books as a :type against :entry.', ['type' => $documentType->isPurchase() ? __('payment') : __('receipt'), 'entry' => $document->entry?->number]) }}</p>@endif
            <x-slot:footer><x-button type="submit" wire:loading.attr="disabled" wire:target="recordPayment">{{ __('Save') }}</x-button><x-button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-button></x-slot:footer>
        </x-drawer>
    @endif

    @can('sales.void')
        <x-drawer id="void-document" wire:model="voiding" submit="void" :title="__('Void :number', ['number' => $document->displayNumber()])" :description="__('The document stays listed for audit but no longer counts. A posted document\'s journal entry is voided too.')">
            <x-form.input name="voidReason" :label="__('Reason')" wire:model="voidReason" required maxlength="500" autofocus />
            <x-slot:footer><x-button type="submit" variant="danger-solid" icon="ban">{{ __('Void') }}</x-button><x-button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-button></x-slot:footer>
        </x-drawer>
    @endcan

    @if($canSend)
        <x-drawer id="email-document" wire:model="emailing" submit="sendEmail" :title="__('Email :number', ['number' => $document->number])" :description="__('Sent now with the PDF attached.')">
            <x-form.input name="emailTo" :label="__('To')" type="email" wire:model="emailTo" required maxlength="255" autofocus />
            <x-form.input name="emailCc" :label="__('Cc')" wire:model="emailCc" maxlength="500" :help="__('Optional. Separate addresses with commas.')" />
            <x-form.input name="emailSubject" :label="__('Subject')" wire:model="emailSubject" required maxlength="200" />
            <div class="field">
                <label for="emailMessage">{{ __('Message') }}<span class="required-mark" aria-hidden="true"> *</span></label>
                <textarea id="emailMessage" name="emailMessage" class="form-control h-auto py-2" rows="8" wire:model="emailMessage" maxlength="5000" required aria-invalid="{{ $errors->has('emailMessage') ? 'true' : 'false' }}"></textarea>
                @error('emailMessage')<p class="error">{{ $message }}</p>@enderror
            </div>
            <x-slot:footer><x-button type="submit" icon="mail" wire:loading.attr="disabled" wire:target="sendEmail">{{ __('Send') }}</x-button><x-button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-button></x-slot:footer>
        </x-drawer>
    @endif
</div>
