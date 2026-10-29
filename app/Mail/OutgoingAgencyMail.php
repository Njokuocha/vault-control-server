<?php

namespace App\Mail;

use App\Models\SentEmail;
use App\Services\AdminEmailFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OutgoingAgencyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly SentEmail $email) {}

    public function envelope(): Envelope
    {
        $from = new Address($this->email->from_address, $this->email->from_name ?? '');

        return new Envelope(
            from: $from,
            subject: $this->email->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.agency-message',
            with: ['bodyHtml' => app(AdminEmailFormatter::class)->sanitize($this->email->body_html)],
        );
    }

    public function attachments(): array
    {
        return $this->email->attachments->map(
            fn ($attachment): Attachment => Attachment::fromStorageDisk('vault', $attachment->stored_path)
                ->as($attachment->original_name)
                ->withMime($attachment->mime_type),
        )->all();
    }
}
