<?php

namespace App\Mail;

use App\Models\Document;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A document emailed to its customer or supplier: the sender's message, the PDF attached and, when shared, the link. */
class DocumentMail extends Mailable
{
    public function __construct(
        public readonly Document $document,
        public readonly string $mailSubject,
        public readonly string $messageText,
        public readonly ?string $shareUrl,
        private readonly string $pdf,
        public readonly string $filename,
        private readonly ?Address $replyAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject, replyTo: $this->replyAddress ? [$this->replyAddress] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.document', with: [
            'companyName' => $this->document->company->name, 'messageText' => $this->messageText, 'shareUrl' => $this->shareUrl,
            'label' => $this->document->type->label().' '.$this->document->displayNumber(),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
