<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\InvoicePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        string $subject,
        public readonly string $body,
    ) {
        $this->subject = $subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject,
            replyTo: $this->invoice->company->email !== null
                ? [$this->invoice->company->email]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document',
            with: ['body' => $this->body],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => InvoicePdf::render($this->invoice)->output(),
                InvoicePdf::filename($this->invoice),
            )->withMime('application/pdf'),
        ];
    }
}
