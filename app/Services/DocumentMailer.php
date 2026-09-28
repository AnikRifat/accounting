<?php

namespace App\Services;

use App\Mail\DocumentMail;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Emails an issued document with its PDF attached. Sent synchronously (no queue on cPanel): a transport failure is
 * thrown to the caller and nothing is logged, so the form can show it.
 */
class DocumentMailer
{
    /** Most addresses in the Cc field. */
    public const MAX_CC = 10;

    public function __construct(private readonly DocumentRenderer $renderer, private readonly DocumentService $documents) {}

    /**
     * Sends the document synchronously (no queue on cPanel) and logs an `emailed` activity. Requires sales.send and
     * access to the document's company; refuses drafts and void documents. $cc takes addresses separated by commas.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function send(Document $document, string $to, ?string $cc, string $subject, string $message, User $actor): void
    {
        Gate::forUser($actor)->authorize('sales.send');
        if (! $actor->canAccessCompany($document->company_id)) {
            throw new AuthorizationException(__('You do not have access to this company.'));
        }
        $document = Document::query()->with('company')->findOrFail($document->id);
        if ($document->isDraft() || $document->isVoid()) {
            throw ValidationException::withMessages(['document' => $document->isDraft()
                ? __('Issue the document before emailing it.') : __('A void document can\'t be emailed.')]);
        }
        [$to, $subject, $message] = [trim($to), trim($subject), trim($message)];
        $copies = array_values(array_filter(array_map('trim', preg_split('/[,;]/', (string) $cc) ?: []), fn (string $address): bool => $address !== ''));
        Validator::make(['to' => $to, 'cc' => $copies, 'subject' => $subject, 'message' => $message], [
            'to' => ['required', 'email', 'max:150'],
            'cc' => ['array', 'max:'.self::MAX_CC, function (string $attribute, array $addresses, \Closure $fail): void {
                foreach ($addresses as $address) {
                    if (mb_strlen($address) > 150 || Validator::make(['address' => $address], ['address' => 'email'])->fails()) {
                        $fail(__(':address is not a valid email address.', ['address' => mb_substr($address, 0, 150)]));

                        return;
                    }
                }
            }],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:5000'],
        ], [], ['to' => __('to'), 'cc' => __('cc'), 'subject' => __('subject'), 'message' => __('message')])->validate();

        $shared = $document->share_token !== null && ($document->share_expires_at === null || $document->share_expires_at->isFuture());
        $pending = Mail::to($to);
        if ($copies !== []) {
            $pending->cc($copies);
        }
        $pending->send(new DocumentMail($document, $subject, $message, $shared ? route('documents.shared', $document->share_token) : null,
            $this->renderer->pdf($document), $this->renderer->filename($document),
            $actor->email ? new Address($actor->email, $actor->name) : null));

        $this->documents->log($document, 'emailed', $actor, $copies === [] ? $to : __(':to (cc: :cc)', ['to' => $to, 'cc' => implode(', ', $copies)]));
    }
}
