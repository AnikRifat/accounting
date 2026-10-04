<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A test email from Settings > Mail, proving the saved mail settings deliver. */
class TestMail extends Mailable
{
    public function __construct(public readonly User $sender, public readonly string $appName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Test email from :app', ['app' => $this->appName]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.test', with: ['senderName' => $this->sender->name, 'sentAt' => now()->format('j M Y, g:i A')]);
    }
}
