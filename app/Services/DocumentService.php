<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentField;
use App\Models\DocumentPayment;
use App\Models\DocumentSequence;
use App\Models\DocumentTemplate;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Support\DocumentMath;
use App\Support\Modules;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only writer of documents. Totals are recomputed from the lines here (App\Support\DocumentMath); the form's
 * figures are never trusted. A document switched to "Post to accounts" posts through LedgerService::postDocument(),
 * in the same transaction as the document write.
 *
 * Abilities: sales.create (new drafts), sales.update (edit, issue, accept/decline, convert), sales.void, sales.delete
 * (drafts only), sales.payments, sales.send (email and share links). Posting also needs the ledger's entries.create
 * (entries.update to re-post); every write re-checks the document's company.
 */
class DocumentService
{
    /** Most lines one document can have. */
    public const MAX_LINES = 100;

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Creates a draft (no $document) or saves an existing draft or issued document.
     *
     * Data: party_id?, issue_date, due_date?, title?, reference?, template_id?, tax_inclusive, discount_type?,
     * discount_value (paisa or basis points), notes?, terms?, body?, custom_values (field id => value),
     * post_to_accounts, source_id? (create only), lines: list of {item_id?, account_id?, description, quantity
     * (thousandths), unit?, unit_price (paisa), discount_type?, discount_value, tax_rate (basis points)}.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|AuthorizationException
     */
    public function save(?Document $document, Company $company, DocumentType $type, array $data, User $actor): Document
    {
        $this->authorize($actor, $document ? 'sales.update' : 'sales.create', $company->id);
        if ($document && ($document->company_id !== $company->id || $document->type !== $type)) {
            throw new LogicException('A document keeps its company and type.');
        }

        return DB::transaction(function () use ($document, $company, $type, $data, $actor): Document {
            $this->lockOpenCompany($company->id);
            $document = $document ? Document::query()->lockForUpdate()->findOrFail($document->id) : null;
            if ($document && in_array($document->status, [DocumentStatus::Void, DocumentStatus::Converted], true)) {
                throw ValidationException::withMessages(['document' => __('A :status document can no longer be edited.', ['status' => mb_strtolower($document->status->label())])]);
            }
            if ($document) {
                $this->refuseClosedSource($document);
            }
            $clean = $this->validated($company, $type, $data, $document);
            $source = $clean['source'];
            $document ??= (new Document)->forceFill(['company_id' => $company->id, 'type' => $type, 'status' => DocumentStatus::Draft,
                'created_by' => $actor->id, 'source_id' => $source?->id]);
            $wasPosted = $document->isPosted();
            $document->forceFill([
                'party_id' => $clean['party_id'], 'template_id' => $clean['template_id'], 'issue_date' => $clean['issue_date'], 'due_date' => $clean['due_date'],
                'title' => $clean['title'], 'reference' => $clean['reference'], 'tax_inclusive' => $clean['tax_inclusive'],
                'discount_type' => $clean['discount_type'], 'discount_value' => $clean['discount_value'],
                'notes' => $clean['notes'], 'terms' => $clean['terms'], 'body' => $clean['body'], 'custom_values' => $clean['custom_values'],
                // A note follows its invoice or bill: it is posted exactly when that is.
                'post_to_accounts' => $type->isNote() ? (bool) $source?->isPosted() : $type->isPostable() && ($wasPosted || $clean['post_to_accounts']),
                'subtotal' => $clean['totals']['subtotal'], 'discount_total' => $clean['totals']['discount_total'],
                'tax_total' => $clean['totals']['tax_total'], 'total' => $clean['totals']['total'],
                'updated_by' => $document->exists ? $actor->id : null,
            ])->save();
            $document->lines()->delete();
            $document->lines()->createMany($clean['lines']);
            $document->unsetRelation('lines');
            $this->assertCovers($document);
            $this->log($document, $document->wasRecentlyCreated ? 'created' : 'updated', $actor);

            if ($wasPosted) {
                $this->ledger->postDocument($document->load('lines', 'source'), $actor);
            } elseif ($document->isOpen() && $document->post_to_accounts) {
                $this->post($document, $actor);
            }

            return $document;
        }, 3);
    }

    /**
     * Issues a draft: it takes the next number of its type and, when switched on, posts to the books.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function issue(Document $document, User $actor): Document
    {
        $this->authorize($actor, 'sales.update', $document->company_id);

        return DB::transaction(function () use ($document, $actor): Document {
            $this->lockOpenCompany($document->company_id);
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            if (! $document->isDraft()) {
                throw ValidationException::withMessages(['document' => __('Only drafts can be issued.')]);
            }
            if ($document->type->hasLines() && ! $document->lines()->exists()) {
                throw ValidationException::withMessages(['lines' => __('Add at least one line.')]);
            }
            $this->refuseClosedSource($document);
            $document->forceFill(['number' => $this->nextNumber($document->company_id, $document->type), 'status' => DocumentStatus::Issued,
                'issued_at' => now(), 'updated_by' => $actor->id])->save();
            if ($document->type->isNote()) {
                // The invoice or bill may have been posted since this draft was saved; a note always follows it.
                $document->forceFill(['post_to_accounts' => (bool) $document->source?->isPosted()])->save();
            }
            $this->assertCovers($document);
            $this->log($document, 'issued', $actor, $document->number);
            if ($document->post_to_accounts) {
                $this->post($document, $actor);
            }

            return $document;
        }, 3);
    }

    /**
     * Switches "Post to accounts" on for an issued, unposted invoice, bill or note: posts it, then its issued notes,
     * then replays its recorded payments as ledger receipts or payments with their own dates and methods. A posted
     * document can't be switched off; void it instead.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function postToAccounts(Document $document, User $actor): Document
    {
        $this->authorize($actor, 'sales.update', $document->company_id);

        return DB::transaction(function () use ($document, $actor): Document {
            $this->lockOpenCompany($document->company_id);
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            if (! $document->type->isPostable() || ! $document->isOpen() || $document->isPosted()) {
                throw ValidationException::withMessages(['post_to_accounts' => __('Only an issued, unposted invoice, bill or note can be posted.')]);
            }
            $document->forceFill(['post_to_accounts' => true, 'updated_by' => $actor->id])->save();
            $this->post($document, $actor);

            return $document;
        }, 3);
    }

    /**
     * Records money received for an invoice or paid for a bill: a ledger receipt or payment when the document is
     * posted, a document payment otherwise.
     *
     * @param  array{paid_on: string, amount: int, account_id: int, reference?: ?string, paid_by?: ?int}  $data
     *
     * @throws ValidationException|AuthorizationException
     */
    public function recordPayment(Document $document, array $data, User $actor): void
    {
        $this->authorize($actor, 'sales.payments', $document->company_id);
        if (! $document->type->isPayable()) {
            throw new LogicException('Only invoices and bills are paid.');
        }

        DB::transaction(function () use ($document, $data, $actor): void {
            $this->lockOpenCompany($document->company_id);
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            if (! $document->isOpen()) {
                throw ValidationException::withMessages(['amount' => __('Payments are recorded on issued documents only.')]);
            }
            if ($document->isPosted()) {
                $this->ledger->settle(JournalEntry::query()->findOrFail($document->journal_entry_id), ['entry_date' => $data['paid_on'],
                    'amount' => $data['amount'], 'payment_account_id' => $data['account_id'], 'paid_by' => $data['paid_by'] ?? null,
                    'reference' => $data['reference'] ?? null, 'description' => __('Payment for :number', ['number' => $document->number])], $actor);
            } else {
                $this->documentPayment($document, $data, $actor);
            }
            $this->log($document, 'payment', $actor, Money::format((int) $data['amount']));
        }, 3);
    }

    /**
     * Deletes a payment recorded on an unposted document. Payments of a posted document are ledger entries and are
     * voided from Transactions.
     *
     * @throws AuthorizationException
     */
    public function deletePayment(DocumentPayment $payment, User $actor): void
    {
        $this->authorize($actor, 'sales.payments', $payment->document->company_id);
        DB::transaction(function () use ($payment, $actor): void {
            Document::query()->lockForUpdate()->findOrFail($payment->document_id);
            $payment->delete();
            $this->log($payment->document, 'payment_deleted', $actor, Money::format($payment->amount));
        });
    }

    /**
     * Voids an issued document with a reason, and its journal entry when posted. Refused while payments or issued
     * notes are recorded against it.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function void(Document $document, string $reason, User $actor): Document
    {
        $this->authorize($actor, 'sales.void', $document->company_id);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']], [], ['reason' => __('reason')])->validate();

        return DB::transaction(function () use ($document, $reason, $actor): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->isDraft() || $document->isVoid()) {
                throw ValidationException::withMessages(['reason' => __('Only issued documents can be voided; delete a draft instead.')]);
            }
            if ($document->payments()->exists() || $this->issuedNotes($document)->exists()) {
                throw ValidationException::withMessages(['reason' => __('Delete or void its payments and notes first.')]);
            }
            if ($document->status === DocumentStatus::Converted) {
                throw ValidationException::withMessages(['reason' => __('Void the document it was converted into first.')]);
            }
            if ($document->isPosted()) {
                $this->ledger->voidDocumentEntry($document, $reason, $actor);
            }
            $document->forceFill(['status' => DocumentStatus::Void, 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();
            $this->reopenSource($document);
            $this->log($document, 'voided', $actor, $reason);

            return $document;
        }, 3);
    }

    /**
     * Deletes a draft. Issued documents are voided, never deleted, so their numbers stay accounted for.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function deleteDraft(Document $document, User $actor): void
    {
        $this->authorize($actor, 'sales.delete', $document->company_id);
        DB::transaction(function () use ($document): void {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            if (! $document->isDraft() || $document->derived()->exists()) {
                throw ValidationException::withMessages(['document' => __('Only drafts can be deleted; void an issued document instead.')]);
            }
            if (RecurringInvoice::query()->where('source_id', $document->id)->exists()) {
                throw ValidationException::withMessages(['document' => __('A recurring schedule copies this draft. Delete the schedule first.')]);
            }
            $document->delete();
            $this->reopenSource($document);
        });
    }

    /** Marks an issued quotation, estimate or proforma as accepted or declined by the customer. */
    public function respond(Document $document, DocumentStatus $status, User $actor): Document
    {
        $this->authorize($actor, 'sales.update', $document->company_id);
        if (! $document->type->isOffer() || ! in_array($status, [DocumentStatus::Accepted, DocumentStatus::Declined, DocumentStatus::Issued], true)) {
            throw new LogicException('Only offers are accepted or declined.');
        }

        return DB::transaction(function () use ($document, $status, $actor): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            if (! in_array($document->status, [DocumentStatus::Issued, DocumentStatus::Accepted, DocumentStatus::Declined], true)) {
                throw ValidationException::withMessages(['document' => __('Issue it first.')]);
            }
            $document->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
            $this->log($document, 'status', $actor, $status->label());

            return $document;
        });
    }

    /**
     * Starts a new draft from an issued document: an offer becomes an invoice, a purchase order a bill, an invoice
     * a delivery note or credit note, a bill a debit note. Lines, party and texts are copied; the draft remembers
     * its source. An offer or order is marked converted, and can be converted only once.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function convert(Document $source, DocumentType $target, User $actor): Document
    {
        // Converting an offer or order changes it (it becomes Converted), so it needs sales.update; a note or delivery note only adds a draft.
        $this->authorize($actor, $target === $source->type->convertsTo() ? 'sales.update' : 'sales.create', $source->company_id);
        $allowed = [$source->type->convertsTo(), $source->type->note(), $source->type === DocumentType::Invoice ? DocumentType::DeliveryNote : null];
        if (! in_array($target, array_filter($allowed), true)) {
            throw ValidationException::withMessages(['document' => __('A :from can\'t be turned into a :to.', ['from' => $source->type->label(), 'to' => $target->label()])]);
        }

        return DB::transaction(function () use ($source, $target, $actor): Document {
            $company = $this->lockOpenCompany($source->company_id);
            $source = Document::query()->lockForUpdate()->findOrFail($source->id);
            if (! $source->isOpen() || $source->status === DocumentStatus::Declined) {
                throw ValidationException::withMessages(['document' => __('Only an issued document can be turned into another one.')]);
            }
            if ($target === $source->type->convertsTo()) {
                if ($source->status === DocumentStatus::Converted) {
                    throw ValidationException::withMessages(['document' => __(':number has already been converted.', ['number' => $source->number])]);
                }
                $source->forceFill(['status' => DocumentStatus::Converted, 'updated_by' => $actor->id])->save();
            }
            $sequence = DocumentSequence::for($company->id, $target);
            $document = (new Document)->forceFill([
                'company_id' => $company->id, 'type' => $target, 'status' => DocumentStatus::Draft, 'source_id' => $source->id,
                'party_id' => $source->party_id, 'template_id' => $sequence->template_id, 'issue_date' => today()->toDateString(),
                'due_date' => $target->isPayable() && $source->due_date && $source->issue_date
                    ? today()->addDays((int) $source->issue_date->diffInDays($source->due_date))->toDateString() : null,
                'reference' => $source->number, 'tax_inclusive' => $source->tax_inclusive, 'discount_type' => $source->discount_type,
                'discount_value' => $source->discount_value, 'subtotal' => $source->subtotal, 'discount_total' => $source->discount_total,
                'tax_total' => $source->tax_total, 'total' => $source->total, 'notes' => $source->notes,
                'terms' => $sequence->default_terms ?? $source->terms, 'post_to_accounts' => $target->isPostable() && $source->isPosted(),
                'created_by' => $actor->id,
            ]);
            $document->save();
            $document->lines()->createMany($source->lines->map(fn ($line): array => collect($line->getAttributes())
                ->except(['id', 'document_id'])->all())->all());
            $this->log($source, 'converted', $actor, $target->label());
            $this->log($document, 'created', $actor, __('From :number', ['number' => $source->number]));

            return $document;
        }, 3);
    }

    /**
     * A public link to view and download the document, valid for $days (null: until revoked).
     *
     * @throws AuthorizationException
     */
    public function share(Document $document, ?int $days, User $actor): string
    {
        $this->authorize($actor, 'sales.send', $document->company_id);
        if ($document->isDraft()) {
            throw ValidationException::withMessages(['document' => __('Issue the document before sharing it.')]);
        }
        $expired = $document->share_expires_at !== null && $document->share_expires_at->isPast();
        $document->forceFill(['share_token' => $document->share_token === null || $expired ? Str::random(40) : $document->share_token,
            'share_expires_at' => $days ? now()->addDays($days) : null])->save();
        $this->log($document, 'shared', $actor, $days ? __(':days days', ['days' => $days]) : __('Until revoked'));

        return route('documents.shared', $document->share_token);
    }

    /** @throws AuthorizationException */
    public function revokeShare(Document $document, User $actor): void
    {
        $this->authorize($actor, 'sales.send', $document->company_id);
        $document->forceFill(['share_token' => null, 'share_expires_at' => null])->save();
        $this->log($document, 'share_revoked', $actor);
    }

    public function log(Document $document, string $event, ?User $actor, ?string $details = null): void
    {
        $document->activities()->create(['user_id' => $actor?->id, 'event' => $event, 'details' => $details !== null ? mb_substr($details, 0, 500) : null]);
    }

    /** Next number of a type in a company; the company row is locked by the caller, so numbers never repeat. */
    private function nextNumber(int $companyId, DocumentType $type): string
    {
        $sequence = DocumentSequence::query()->lockForUpdate()->find(DocumentSequence::for($companyId, $type)->id);
        $next = $sequence->last_number;
        do {
            $number = $sequence->format(++$next);
        } while (Document::query()->where('company_id', $companyId)->where('type', $type)->where('number', $number)->exists());
        $sequence->forceFill(['last_number' => $next])->save();

        return $number;
    }

    /** Posts a document, then (for an invoice or bill) its issued notes and recorded payments. */
    private function post(Document $document, User $actor): void
    {
        $entry = $this->ledger->postDocument($document->load('lines', 'source'), $actor);
        $document->forceFill(['journal_entry_id' => $entry->id])->save();
        $this->log($document, 'posted', $actor, $entry->number);
        if (! $document->type->isPayable()) {
            return;
        }
        foreach ($this->issuedNotes($document)->whereNull('journal_entry_id')->orderBy('issue_date')->orderBy('id')->get() as $note) {
            $note->forceFill(['post_to_accounts' => true])->save();
            $this->post($note, $actor);
        }
        foreach ($document->payments()->with('account')->get() as $payment) {
            if ($payment->account === null || ! $payment->account->is_active) {
                throw ValidationException::withMessages(['post_to_accounts' => __('The payment of :amount on :date has no active payment method. Delete it and record it again first.', [
                    'amount' => Money::format($payment->amount), 'date' => $payment->paid_on->format('d M Y')])]);
            }
            $settlement = $this->ledger->settle($entry, ['entry_date' => $payment->paid_on->toDateString(), 'amount' => $payment->amount,
                'payment_account_id' => $payment->account_id, 'reference' => $payment->reference,
                'description' => __('Payment for :number', ['number' => $document->number])], $actor);
            // The receipt keeps whoever recorded the payment, not whoever switched posting on.
            $settlement->forceFill(['paid_by' => $payment->created_by])->save();
            $payment->delete();
        }
    }

    /**
     * Validates the data against the company, then prices the lines.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(Company $company, DocumentType $type, array $data, ?Document $document): array
    {
        $sales = ! $type->isPurchase();
        $data = Validator::make($data, [
            'party_id' => [$type === DocumentType::Contract ? 'nullable' : 'required', 'integer'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'title' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'template_id' => ['nullable', 'integer'],
            'tax_inclusive' => ['boolean'],
            'discount_type' => ['nullable', Rule::in([DocumentMath::DISCOUNT_AMOUNT, DocumentMath::DISCOUNT_PERCENT])],
            'discount_value' => ['integer', 'min:0', 'max:'.LedgerService::MAX_AMOUNT],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'body' => ['nullable', 'string', 'max:100000'],
            'custom_values' => ['array'],
            'post_to_accounts' => ['boolean'],
            'source_id' => [$type->isNote() && ! $document ? 'required' : 'nullable', 'integer'],
            'lines' => $type->hasLines() ? ['required', 'array', 'min:1', 'max:'.self::MAX_LINES] : ['array', 'max:0'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.DocumentMath::MAX_QUANTITY],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:'.LedgerService::MAX_AMOUNT],
            'lines.*.discount_type' => ['nullable', Rule::in([DocumentMath::DISCOUNT_AMOUNT, DocumentMath::DISCOUNT_PERCENT])],
            'lines.*.discount_value' => ['integer', 'min:0', 'max:'.LedgerService::MAX_AMOUNT],
            'lines.*.tax_rate' => ['integer', 'min:0', 'max:'.DocumentMath::FULL],
        ], [], ['party_id' => $sales ? __('customer') : __('supplier'), 'issue_date' => __('date'), 'due_date' => $type->dueLabel() ? mb_strtolower($type->dueLabel()) : __('date'),
            'lines' => __('lines'), 'lines.*.description' => __('description'), 'lines.*.quantity' => __('quantity'), 'lines.*.unit_price' => __('price')])->validate();

        $errors = [];
        $belongs = fn (?int $id, string $model): bool => $id === null || $model::query()->whereKey($id)->where('company_id', $company->id)->exists();
        $partyId = isset($data['party_id']) ? (int) $data['party_id'] : null;
        if ($partyId !== null) {
            $party = Party::query()->whereKey($partyId)->where('company_id', $company->id)->first();
            if ($party === null || (! $party->is_active && $partyId !== $document?->party_id)) {
                $errors['party_id'] = __('Choose an active party of this company.');
            }
            if ($document && $document->isPosted() && $partyId !== $document->party_id && $document->type->isPayable() && $this->hasCounterEntries($document)) {
                $errors['party_id'] = __('The party can\'t change once payments or notes are recorded.');
            }
        }
        $templateId = isset($data['template_id']) ? (int) $data['template_id'] : null;
        if (! $belongs($templateId, DocumentTemplate::class)) {
            $errors['template_id'] = __('Choose a template of this company.');
        }
        $source = $document?->source;
        if (! $document && isset($data['source_id'])) {
            $source = Document::query()->whereKey($data['source_id'])->where('company_id', $company->id)->first();
            if ($type->isNote() && ($source === null || $source->type !== $type->noteFor() || ! $source->isOpen())) {
                $errors['source_id'] = __('Choose an issued :type of this company.', ['type' => mb_strtolower($type->noteFor()->label())]);
            } elseif ($source === null) {
                $errors['source_id'] = __('The source document does not belong to this company.');
            }
        }
        if ($document && $document->isOpen() && ! $document->isPosted() && $type->isPayable()) {
            $firstPayment = $document->payments()->min('paid_on');
            if ($firstPayment !== null && $data['issue_date'] > $firstPayment) {
                $errors['issue_date'] = __('The date can\'t be after the first payment recorded on it.');
            }
            if ($partyId !== $document->party_id && $this->issuedNotes($document)->exists()) {
                $errors['party_id'] = __('The party can\'t change once notes are issued against it.');
            }
        }
        if ($type->isNote() && $source !== null && $partyId !== null && $partyId !== $source->party_id) {
            $errors['party_id'] = __('A note is for the same party as its :type.', ['type' => mb_strtolower($type->noteFor()->label())]);
        }

        $categoryType = $sales ? AccountType::Income : AccountType::Expense;
        $defaultCategory = Account::query()->where('company_id', $company->id)->categories($categoryType)->where('is_active', true)->orderBy('code')->value('id');
        $lines = [];
        foreach ($data['lines'] ?? [] as $index => $line) {
            $itemId = isset($line['item_id']) ? (int) $line['item_id'] : null;
            if (! $belongs($itemId, Item::class)) {
                $errors["lines.{$index}.item_id"] = __('Choose an item of this company.');
            }
            $accountId = isset($line['account_id']) ? (int) $line['account_id'] : null;
            // "Default category": the item's category, else the company's first active one of that side, so the line can post.
            $accountId ??= ($itemId ? Item::query()->whereKey($itemId)->value('account_id') : null) ?? $defaultCategory;
            if ($accountId !== null && ! Account::query()->whereKey($accountId)->where('company_id', $company->id)->categories($categoryType)->exists()) {
                $errors["lines.{$index}.account_id"] = $sales ? __('Choose an income category.') : __('Choose an expense category.');
            }
            $lines[] = ['item_id' => $itemId, 'account_id' => $accountId, 'description' => trim($line['description']), 'quantity' => (int) $line['quantity'],
                'unit' => ($line['unit'] ?? '') !== '' ? trim($line['unit']) : null, 'unit_price' => (int) $line['unit_price'],
                'discount_type' => $line['discount_type'] ?? null, 'discount_value' => (int) ($line['discount_value'] ?? 0),
                'tax_rate' => (int) ($line['tax_rate'] ?? 0), 'sort' => $index];
        }
        $priced = DocumentMath::calculate($lines, $data['discount_type'] ?? null, (int) ($data['discount_value'] ?? 0),
            (bool) ($data['tax_inclusive'] ?? false), LedgerService::MAX_AMOUNT);
        $errors += $priced['errors'];
        foreach ($lines as $index => $line) {
            $lines[$index] += $priced['lines'][$index];
        }

        $custom = [];
        $fields = DocumentField::query()->for($company->id, $type)->get();
        foreach ($fields as $field) {
            $value = $data['custom_values'][$field->id] ?? $data['custom_values'][(string) $field->id] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : null;
            $check = Validator::make(['value' => $value === '' ? null : $value], ['value' => $field->rules()], [], ['value' => $field->label]);
            if ($check->fails()) {
                $errors['custom_values.'.$field->id] = $check->errors()->first('value');
            } elseif ($value !== null && $value !== '') {
                $custom[(string) $field->id] = $value;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $blank = fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);

        return [
            'party_id' => $partyId, 'template_id' => $templateId, 'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'] ?? null,
            'title' => $blank($data['title'] ?? null), 'reference' => $blank($data['reference'] ?? null),
            'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? false),
            'discount_type' => ($data['discount_type'] ?? null) ?: null, 'discount_value' => ($data['discount_type'] ?? null) ? (int) ($data['discount_value'] ?? 0) : 0,
            'notes' => $blank($data['notes'] ?? null), 'terms' => $blank($data['terms'] ?? null), 'body' => $blank($data['body'] ?? null),
            'custom_values' => $custom ?: null, 'post_to_accounts' => (bool) ($data['post_to_accounts'] ?? false),
            'source' => $source, 'lines' => $lines, 'totals' => $priced,
        ];
    }

    /**
     * Refuses a state where what was paid or credited exceeds a document, or a note exceeds what its invoice or bill
     * still owes. Posted documents are checked by the ledger instead.
     */
    private function assertCovers(Document $document): void
    {
        if ($document->isDraft() || $document->isPosted()) {
            return;
        }
        if ($document->type->isPayable()) {
            $covered = (int) $document->payments()->sum('amount') + (int) $this->issuedNotes($document)->sum('total');
            if ($covered > $document->total) {
                throw ValidationException::withMessages(['lines' => __('The total can\'t be less than the :amount already paid or credited.', ['amount' => Money::format($covered)])]);
            }
        }
        if ($document->type->isNote() && $document->source) {
            $source = Document::query()->withBalance()->lockForUpdate()->findOrFail($document->source_id);
            if ($source->balance < 0) {
                throw ValidationException::withMessages(['lines' => __('The note can\'t be more than the :amount still owed on :number.', [
                    'amount' => Money::format($source->balance + $document->total), 'number' => $source->number])]);
            }
        }
    }

    /** @param array{paid_on: string, amount: int, account_id: int, reference?: ?string} $data */
    private function documentPayment(Document $document, array $data, User $actor): void
    {
        $check = Validator::make($data, [
            'paid_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$document->issue_date->toDateString()],
            'amount' => ['required', 'integer', 'min:1', 'max:'.LedgerService::MAX_AMOUNT],
            'account_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], ['paid_on.after_or_equal' => __('The date must be on or after the date of :number.', ['number' => $document->number])],
            ['paid_on' => __('date'), 'amount' => __('amount'), 'account_id' => __('payment method')]);
        $check->after(function ($validator) use ($document, $data): void {
            if (! Account::query()->whereKey($data['account_id'] ?? 0)->where('company_id', $document->company_id)->paymentMethods()->where('is_active', true)->exists()) {
                $validator->errors()->add('account_id', __('Choose a payment method.'));
            }
            $balance = (int) Document::query()->withBalance()->findOrFail($document->id)->balance;
            if ((int) ($data['amount'] ?? 0) > $balance) {
                $validator->errors()->add('amount', __('The amount can\'t be more than the outstanding :amount.', ['amount' => Money::format($balance)]));
            }
        })->validate();
        $document->payments()->create(['paid_on' => $data['paid_on'], 'amount' => (int) $data['amount'], 'account_id' => (int) $data['account_id'],
            'reference' => ($data['reference'] ?? '') !== '' ? $data['reference'] : null, 'created_by' => $actor->id]);
    }

    /** A note is issued or changed only while its invoice or bill is still open (issued, not void). */
    private function refuseClosedSource(Document $document): void
    {
        if (! $document->type->isNote() || $document->source_id === null) {
            return;
        }
        $source = Document::query()->lockForUpdate()->find($document->source_id);
        if ($source === null || ! $source->isOpen()) {
            throw ValidationException::withMessages(['document' => __('The :type this note is for is void, so the note can no longer be issued or changed.', [
                'type' => mb_strtolower($document->type->noteFor()->label())])]);
        }
    }

    /** Issued (not draft, not void) credit or debit notes against an invoice or bill. */
    private function issuedNotes(Document $document): Builder
    {
        return Document::query()->where('source_id', $document->id)->whereIn('type', [DocumentType::CreditNote, DocumentType::DebitNote])
            ->whereNotIn('status', [DocumentStatus::Draft, DocumentStatus::Void]);
    }

    private function hasCounterEntries(Document $document): bool
    {
        return JournalEntry::query()->where('bill_id', $document->journal_entry_id)->posted()->exists();
    }

    /** A voided or deleted conversion gives its offer or order back, so it can be converted again. */
    private function reopenSource(Document $document): void
    {
        $source = $document->source_id ? Document::query()->lockForUpdate()->find($document->source_id) : null;
        if ($source && $source->status === DocumentStatus::Converted && $source->type->convertsTo() === $document->type) {
            $source->forceFill(['status' => DocumentStatus::Issued])->save();
        }
    }

    private function authorize(User $actor, string $ability, int $companyId): void
    {
        Gate::forUser($actor)->authorize($ability);
        if (! $actor->canAccessCompany($companyId, Modules::SALES)) {
            throw new AuthorizationException(__('You do not have access to this company.'));
        }
    }

    /** Locks the company row (numbering and posting serialise on it) and refuses inactive companies. */
    private function lockOpenCompany(int $companyId): Company
    {
        $company = Company::query()->lockForUpdate()->findOrFail($companyId);
        if (! $company->is_active) {
            throw ValidationException::withMessages(['company' => __('This company is inactive and does not accept new documents.')]);
        }

        return $company;
    }
}
