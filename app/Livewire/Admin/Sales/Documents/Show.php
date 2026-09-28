<?php

namespace App\Livewire\Admin\Sales\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Document;
use App\Models\DocumentPayment;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\DocumentMailer;
use App\Services\DocumentService;
use App\Support\Money;
use App\Support\WhatsApp;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Throwable;

/**
 * One document: its preview and everything that can happen to it (issue, post, pay, convert, notes, void, email, share).
 * Every action re-loads the document through Document::visibleTo and re-checks its ability; DocumentService checks again.
 */
class Show extends Component
{
    /** Share link lifetimes offered, in days ('' = until revoked). */
    private const SHARE_DAYS = ['7', '30', ''];

    #[Locked]
    public int $documentId;

    public bool $recordingPayment = false;

    public string $paymentDate = '';

    public string $paymentAmount = '';

    public string $paymentAccountId = '';

    public string $paymentReference = '';

    /** Who received or paid the money on a posted document; only the super admin chooses (LedgerService enforces it). */
    public string $paidBy = '';

    public bool $voiding = false;

    public string $voidReason = '';

    public bool $emailing = false;

    public string $emailTo = '';

    public string $emailCc = '';

    public string $emailSubject = '';

    public string $emailMessage = '';

    public string $shareDays = '30';

    public function mount(Document $document): void
    {
        Gate::authorize('sales.view');
        abort_unless(Document::query()->visibleTo(auth()->user())->whereKey($document->id)->exists(), 404);
        $this->documentId = $document->id;
    }

    public function issue(): void
    {
        Gate::authorize('sales.update');
        $this->run(fn (DocumentService $service) => $service->issue($this->document(), auth()->user()),
            fn (Document $document): string => __(':type :number issued.', ['type' => $document->type->label(), 'number' => $document->number]));
    }

    public function deleteDraft(): Redirector|RedirectResponse|null
    {
        Gate::authorize('sales.delete');
        $document = $this->document();
        try {
            app(DocumentService::class)->deleteDraft($document, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('action', collect($exception->errors())->flatten()->first());

            return null;
        }
        session()->flash('success', __('Draft deleted.'));

        return redirect()->route('admin.sales.'.$document->type->slug().'.index');
    }

    public function postToAccounts(): void
    {
        Gate::authorize('sales.update');
        Gate::authorize('entries.create');
        $this->run(fn (DocumentService $service) => $service->postToAccounts($this->document(), auth()->user()),
            fn (Document $document): string => __(':number posted to the books.', ['number' => $document->number]));
    }

    public function respond(string $status): void
    {
        Gate::authorize('sales.update');
        $status = DocumentStatus::tryFrom($status);
        abort_unless(in_array($status, [DocumentStatus::Accepted, DocumentStatus::Declined, DocumentStatus::Issued], true), 404);
        abort_unless($this->document()->type->isOffer(), 404);
        $this->run(fn (DocumentService $service) => $service->respond($this->document(), $status, auth()->user()),
            fn (Document $document): string => __(':number marked :status.', ['number' => $document->number, 'status' => mb_strtolower($status->label())]));
    }

    /** Starts a draft from this document (invoice from an offer, bill from an order, delivery note from an invoice) and opens it. */
    public function convert(string $target): Redirector|RedirectResponse|null
    {
        $target = DocumentType::tryFrom($target);
        abort_unless($target !== null && in_array($target, $this->conversions($this->document()->type), true), 404);
        // Converting an offer or order marks it Converted, so it needs sales.update; a delivery note only adds a draft.
        Gate::authorize($target === $this->document()->type->convertsTo() ? 'sales.update' : 'sales.create');

        return $this->startFrom($target);
    }

    /** Starts a credit note (invoice) or debit note (bill) against this document and opens it. */
    public function createNote(): Redirector|RedirectResponse|null
    {
        Gate::authorize('sales.create');
        $note = $this->document()->type->note();
        abort_if($note === null, 404);

        return $this->startFrom($note);
    }

    public function openPayment(): void
    {
        $document = $this->payableDocument();
        $this->resetErrorBag();
        $this->paymentDate = max(today()->toDateString(), $document->issue_date->toDateString());
        $this->paymentAmount = Money::toInput(max(0, (int) $document->balance));
        $this->paymentAccountId = (string) Account::query()->where('company_id', $document->company_id)->paymentMethods()->where('is_active', true)
            ->orderByRaw('CASE WHEN payment_type = ? THEN 0 ELSE 1 END', [PaymentType::Cash->value])->orderBy('code')->value('id');
        $this->paymentReference = '';
        $this->paidBy = (string) auth()->id();
        $this->recordingPayment = true;
    }

    public function recordPayment(): void
    {
        $document = $this->payableDocument();
        $this->validate([
            'paymentDate' => ['required', 'date_format:Y-m-d'],
            'paymentAmount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Money::isValidInput((string) $value) || Money::toPaisa((string) $value) === 0) {
                    $fail(__('Enter an amount in taka greater than zero, for example 1,25,000.50.'));
                }
            }],
            'paymentAccountId' => ['required', 'integer'],
            'paymentReference' => ['nullable', 'string', 'max:100'],
            'paidBy' => ['nullable', 'integer'],
        ], [], ['paymentDate' => __('date'), 'paymentAmount' => __('amount'), 'paymentAccountId' => __('payment method'),
            'paymentReference' => __('reference'), 'paidBy' => $document->type->isPurchase() ? __('paid by') : __('received by')]);
        $data = ['paid_on' => $this->paymentDate, 'amount' => Money::toPaisa($this->paymentAmount), 'account_id' => (int) $this->paymentAccountId,
            'reference' => trim($this->paymentReference) ?: null];
        if ($document->isPosted() && auth()->user()->isRoot() && $this->paidBy !== '') {
            $data['paid_by'] = (int) $this->paidBy;
        }
        try {
            app(DocumentService::class)->recordPayment($document, $data, auth()->user());
        } catch (ValidationException $exception) {
            $fields = ['paid_on' => 'paymentDate', 'entry_date' => 'paymentDate', 'amount' => 'paymentAmount', 'account_id' => 'paymentAccountId',
                'payment_account_id' => 'paymentAccountId', 'reference' => 'paymentReference', 'paid_by' => 'paidBy'];
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($fields[$key] ?? 'paymentAmount', $messages[0]);
            }

            return;
        }
        $this->reset('recordingPayment', 'paymentDate', 'paymentAmount', 'paymentAccountId', 'paymentReference', 'paidBy');
        session()->now('success', $document->type->isPurchase() ? __('Payment recorded.') : __('Receipt recorded.'));
    }

    /** Deletes a payment recorded on this (unposted) document. */
    public function deletePayment(int $paymentId): void
    {
        Gate::authorize('sales.payments');
        $payment = DocumentPayment::query()->where('document_id', $this->document()->id)->findOrFail($paymentId);
        app(DocumentService::class)->deletePayment($payment, auth()->user());
        session()->now('success', __('Payment deleted.'));
    }

    public function openVoid(): void
    {
        Gate::authorize('sales.void');
        $this->resetErrorBag();
        $this->voidReason = '';
        $this->voiding = true;
    }

    public function void(): void
    {
        Gate::authorize('sales.void');
        $this->resetErrorBag('voidReason');
        try {
            $document = app(DocumentService::class)->void($this->document(), $this->voidReason, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('voidReason', collect($exception->errors())->flatten()->first());

            return;
        }
        $this->reset('voiding', 'voidReason');
        session()->now('success', __(':number voided.', ['number' => $document->number]));
    }

    public function openEmail(): void
    {
        Gate::authorize('sales.send');
        $document = $this->document()->load('party', 'company');
        abort_unless($document->isOpen(), 403);
        $this->resetErrorBag();
        $this->emailTo = (string) $document->party?->email;
        $this->emailCc = '';
        $this->emailSubject = __(':type :number from :company', ['type' => $document->type->label(), 'number' => $document->number, 'company' => $document->company->name]);
        $this->emailMessage = $this->defaultMessage($document);
        $this->emailing = true;
    }

    /** Sends the document by email now; a failure is shown (and reported), never dropped. */
    public function sendEmail(): void
    {
        Gate::authorize('sales.send');
        $document = $this->document();
        $this->validate([
            'emailTo' => ['required', 'email', 'max:255'],
            'emailCc' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail): void {
                foreach ($this->addresses((string) $value) as $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        $fail(__(':address is not an email address.', ['address' => $address]));

                        return;
                    }
                }
            }],
            'emailSubject' => ['required', 'string', 'max:200'],
            'emailMessage' => ['required', 'string', 'max:5000'],
        ], [], ['emailTo' => __('to'), 'emailCc' => __('cc'), 'emailSubject' => __('subject'), 'emailMessage' => __('message')]);
        $cc = implode(', ', $this->addresses($this->emailCc)) ?: null;
        try {
            app(DocumentMailer::class)->send($document, trim($this->emailTo), $cc, trim($this->emailSubject), $this->emailMessage, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(match ($key) {
                    'cc' => 'emailCc', 'subject' => 'emailSubject', 'message' => 'emailMessage', default => 'emailTo'
                }, $messages[0]);
            }

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('emailTo', __('The email was not sent. Check the mail settings or try again later.'));

            return;
        }
        $this->reset('emailing', 'emailTo', 'emailCc', 'emailSubject', 'emailMessage');
        session()->now('success', __(':number emailed.', ['number' => $document->number]));
    }

    public function share(): void
    {
        Gate::authorize('sales.send');
        $this->validate(['shareDays' => ['present', 'in:'.implode(',', self::SHARE_DAYS)]], [], ['shareDays' => __('expiry')]);
        $days = $this->shareDays === '' ? null : (int) $this->shareDays;
        $this->run(fn (DocumentService $service) => $service->share($this->document(), $days, auth()->user()), fn (): string => __('Share link ready.'));
    }

    public function revokeShare(): void
    {
        Gate::authorize('sales.send');
        app(DocumentService::class)->revokeShare($this->document(), auth()->user());
        session()->now('success', __('Share link revoked. The old link no longer opens.'));
    }

    public function render(): View
    {
        Gate::authorize('sales.view');
        $user = auth()->user();
        $document = Document::query()->visibleTo($user)->withBalance()
            ->with(['party', 'company:id,name,code,is_active', 'source:id,type,number', 'entry:id,number', 'creator:id,name', 'activities.user:id,name'])
            ->findOrFail($this->documentId);
        $type = $document->type;
        $related = Document::query()->where('source_id', $document->id)->withBalance()->orderBy('id')->get();
        $shareUrl = $document->share_token && ($document->share_expires_at === null || $document->share_expires_at->isFuture())
            ? route('documents.shared', $document->share_token) : null;

        return view('livewire.admin.sales.documents.show', [
            'document' => $document,
            'documentType' => $type,
            'balance' => $document->balance(),
            'dueStatus' => $document->dueStatus(),
            'notes' => $related->filter(fn (Document $related): bool => $related->type->isNote())->values(),
            'related' => $related->reject(fn (Document $related): bool => $related->type->isNote())->values(),
            'payments' => $type->isPayable() && ! $document->isPosted() ? $document->payments()->with('account:id,name')->get() : collect(),
            'settlements' => $type->isPayable() && $document->isPosted()
                ? JournalEntry::query()->where('bill_id', $document->journal_entry_id)->whereIn('type', [EntryType::Receipt, EntryType::Payment])
                    ->with('lines.account')->orderBy('entry_date')->orderBy('id')->get()
                : collect(),
            'methods' => ['' => __('Select a payment method')] + Account::query()->where('company_id', $document->company_id)->paymentMethods()
                ->where('is_active', true)->orderBy('code')->pluck('name', 'id')->all(),
            'payers' => $document->isPosted() && $user->isRoot() ? $this->payerOptions($document->company_id) : [],
            'conversions' => $document->isOpen() && $document->status !== DocumentStatus::Declined ? $this->conversions($type) : [],
            'canRecordPayment' => $type->isPayable() && $document->isOpen() && (int) $document->balance > 0
                && $user->can('sales.payments') && (! $document->isPosted() || $user->can('entries.create')),
            'canPost' => $type->isPostable() && ! $type->isNote() && $document->isOpen() && ! $document->isPosted()
                && $user->can('sales.update') && $user->can('entries.create'),
            'canEdit' => ! in_array($document->status, [DocumentStatus::Void, DocumentStatus::Converted], true) && $user->can('sales.update'),
            'shareUrl' => $shareUrl,
            'whatsApp' => $shareUrl ? WhatsApp::link($document->party?->phone, __(':type :number from :company (:total): :url', [
                'type' => $type->label(), 'number' => $document->number, 'company' => $document->company->name,
                'total' => Money::format($document->total), 'url' => $shareUrl])) : null,
            'shareOptions' => ['7' => __('7 days'), '30' => __('30 days'), '' => __('Until revoked')],
            'events' => $this->eventLabels(),
        ])->layout('layouts.admin');
    }

    /** The document, re-read with the viewer's access on every action. */
    private function document(): Document
    {
        return Document::query()->visibleTo(auth()->user())->findOrFail($this->documentId);
    }

    /** An open invoice or bill that still owes money, for the payment actions (sales.payments, plus entries.create when posted). */
    private function payableDocument(): Document
    {
        Gate::authorize('sales.payments');
        $document = Document::query()->visibleTo(auth()->user())->withBalance()->findOrFail($this->documentId);
        abort_unless($document->type->isPayable() && $document->isOpen(), 403);
        if ($document->isPosted()) {
            Gate::authorize('entries.create');
        }

        return $document;
    }

    /**
     * Runs a DocumentService action; a refusal lands in the `action` error, success in the toast.
     *
     * @param  Closure(DocumentService): mixed  $action
     * @param  Closure(Document): string  $message
     */
    private function run(Closure $action, Closure $message): void
    {
        $this->resetErrorBag('action');
        try {
            $action(app(DocumentService::class));
        } catch (ValidationException $exception) {
            $this->addError('action', collect($exception->errors())->flatten()->first());

            return;
        }
        session()->now('success', $message($this->document()));
    }

    private function startFrom(DocumentType $target): Redirector|RedirectResponse|null
    {
        try {
            $draft = app(DocumentService::class)->convert($this->document(), $target, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('action', collect($exception->errors())->flatten()->first());

            return null;
        }
        session()->flash('success', __(':type draft created from :number.', ['type' => $target->label(), 'number' => $this->document()->number]));

        return auth()->user()->can('sales.update')
            ? redirect()->route('admin.sales.documents.edit', $draft)
            : redirect()->route('admin.sales.documents.show', $draft);
    }

    /** @return list<DocumentType> what an issued document of this type can be turned into (notes have their own button) */
    private function conversions(DocumentType $type): array
    {
        return array_values(array_filter([$type->convertsTo(), $type === DocumentType::Invoice ? DocumentType::DeliveryNote : null]));
    }

    private function defaultMessage(Document $document): string
    {
        $lines = [__('Dear :name,', ['name' => $document->party?->name ?? __('Sir or Madam')]), ''];
        $lines[] = $document->type->isPayable() && $document->due_date
            ? __('Please find attached :type :number for :total, due on :date.', ['type' => mb_strtolower($document->type->label()), 'number' => $document->number,
                'total' => Money::format($document->total), 'date' => $document->due_date->format('d M Y')])
            : __('Please find attached :type :number for :total.', ['type' => mb_strtolower($document->type->label()), 'number' => $document->number,
                'total' => Money::format($document->total)]);

        return implode("\n", [...$lines, '', __('Thank you,'), $document->company->name]);
    }

    /** @return list<string> comma or semicolon separated addresses, trimmed, without blanks */
    private function addresses(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]/', $value) ?: []), fn (string $address): bool => $address !== ''));
    }

    /**
     * For the super admin: active users who can record entries in the company, plus the current payer.
     *
     * @return array<int|string, string>
     */
    private function payerOptions(int $companyId): array
    {
        $viewer = auth()->user();

        return User::query()->where(fn (Builder $query) => $query->where('is_active', true)->orWhereKey((int) $this->paidBy))
            ->with('companies:id')->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->id === (int) $this->paidBy || ($user->hasPermission('admin.access')
                && ($user->hasPermission('companies.all') || $user->companies->contains('id', $companyId))))
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->name.($user->is($viewer) ? ' ('.__('you').')' : '')])->all();
    }

    /** @return array<string, string> activity event → label */
    private function eventLabels(): array
    {
        return ['created' => __('Created'), 'updated' => __('Edited'), 'issued' => __('Issued'), 'posted' => __('Posted to the books'),
            'payment' => __('Payment recorded'), 'payment_deleted' => __('Payment deleted'), 'voided' => __('Voided'), 'status' => __('Status changed'),
            'converted' => __('Converted'), 'shared' => __('Share link created'), 'share_revoked' => __('Share link revoked'), 'emailed' => __('Emailed'),
            'viewed' => __('Viewed by the customer'), 'downloaded' => __('Downloaded')];
    }
}
