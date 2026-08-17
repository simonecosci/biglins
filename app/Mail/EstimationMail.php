<?php

namespace App\Mail;

use App\Models\Estimation;
use App\Support\EstimationZip;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class EstimationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Estimation $estimation,
        string $subject,
        public readonly string $body,
    ) {
        $this->subject = $subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject,
            replyTo: $this->estimation->company->email !== null
                ? [$this->estimation->company->email]
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
                fn () => $this->zipContent(),
                EstimationZip::filename($this->estimation),
            )->withMime('application/zip'),
        ];
    }

    private function zipContent(): string
    {
        $path = EstimationZip::build($this->estimation);
        $content = file_get_contents($path);
        unlink($path);

        if ($content === false) {
            throw new RuntimeException("Failed to read the generated estimation zip file at [{$path}].");
        }

        return $content;
    }
}
