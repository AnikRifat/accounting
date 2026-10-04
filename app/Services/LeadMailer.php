<?php

namespace App\Services;

use App\Mail\LeadMail;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Emails a CRM lead and logs every attempt in `lead_emails`. Sent synchronously (no queue on cPanel) through the mail
 * settings; a refused send is logged as failed with the server's answer and returned, so the form can show it.
 * An email doesn't change the lead's status or follow-up: only a logged call does.
 */
class LeadMailer
{
    /** Most addresses in the Cc field. */
    public const MAX_CC = 10;

    /**
     * Requires crm.emails.send, a lead the actor may see and an active company. $cc takes addresses separated by commas.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function send(Lead $lead, string $to, ?string $cc, string $subject, string $message, User $actor): LeadEmail
    {
        Gate::forUser($actor)->authorize('crm.emails.send');
        $lead = Lead::visibleTo($actor)->with('company')->findOrFail($lead->id);
        if (! $lead->company->is_active) {
            throw ValidationException::withMessages(['to' => __(':company is inactive and sends no emails.', ['company' => $lead->company->name])]);
        }
        [$to, $subject, $message] = [trim($to), trim($subject), trim($message)];
        $copies = array_values(array_filter(array_map('trim', preg_split('/[,;]/', (string) $cc) ?: []), fn (string $address): bool => $address !== ''));
        Validator::make(['to' => $to, 'cc' => $copies, 'subject' => $subject, 'message' => $message], [
            'to' => ['required', 'email', 'max:255'],
            'cc' => ['array', 'max:'.self::MAX_CC],
            'cc.*' => ['email', 'max:150'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:10000'],
        ], [], ['to' => __('to'), 'cc' => __('cc'), 'cc.*' => __('cc address'), 'subject' => __('subject'), 'message' => __('message')])->validate();

        $email = new LeadEmail(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => $actor->id, 'to' => $to,
            'cc' => $copies === [] ? null : implode(', ', $copies), 'subject' => $subject, 'message' => $message, 'sent_at' => now()]);
        $email->setRelation('company', $lead->company);
        $pending = Mail::to($to);
        if ($copies !== []) {
            $pending->cc($copies);
        }
        try {
            $pending->send(new LeadMail($email, $actor->email ? new Address($actor->email, $actor->name) : null));
            $email->status = LeadEmail::SENT;
        } catch (TransportExceptionInterface $exception) {
            [$email->status, $email->error] = [LeadEmail::FAILED, mb_substr($exception->getMessage(), 0, 1000)];
        }
        $email->save();

        return $email;
    }
}
