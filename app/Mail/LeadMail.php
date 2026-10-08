<?php

namespace App\Mail;

use App\Models\LeadEmail;
use App\Support\MailConfiguration;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An email to a CRM lead, written in the portal, from the company's sender. Replies go to the person who sent it. */
class LeadMail extends Mailable
{
    public function __construct(public readonly LeadEmail $email, private readonly ?Address $replyAddress = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: MailConfiguration::senderFor($this->email->company), subject: $this->email->subject,
            replyTo: $this->replyAddress ? [$this->replyAddress] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.lead', with: ['subjectLine' => $this->email->subject, 'messageText' => $this->email->message,
            'companyName' => $this->email->company->name]);
    }
}
