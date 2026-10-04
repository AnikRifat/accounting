<?php

namespace App\Mail;

use App\Models\LeadEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An email to a CRM lead, written in the portal. Replies go to the person who sent it. */
class LeadMail extends Mailable
{
    public function __construct(public readonly LeadEmail $email, private readonly ?Address $replyAddress = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->email->subject, replyTo: $this->replyAddress ? [$this->replyAddress] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.lead', with: ['subjectLine' => $this->email->subject, 'messageText' => $this->email->message,
            'companyName' => $this->email->company->name]);
    }
}
