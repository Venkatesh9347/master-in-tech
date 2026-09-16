<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Single parameterized mailable for F1 general notifications.
 *
 * The subject and body are pre-built by NotificationService from an
 * explicit internal event allowlist. This class never receives event
 * names, view names, or executable callbacks — only final strings —
 * so arbitrary template execution is impossible by construction.
 */
class TemplatedNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
    ) {}

    /**
     * Get the message envelope (same sender convention as OTP mail).
     */
    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address') ?: config('mail.mailers.smtp.username') ?: 'noreply@masterintech.com';
        $fromName = config('mail.from.name') ?: 'MasterInTech';

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address($fromAddress, $fromName),
            subject: $this->subjectLine,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
