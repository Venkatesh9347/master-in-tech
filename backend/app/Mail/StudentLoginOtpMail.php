<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentLoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public User $user;
    public int $expirySeconds;

    /**
     * Create a new message instance.
     */
    public function __construct(string $otp, User $user, int $expirySeconds = 30)
    {
        $this->otp = $otp;
        $this->user = $user;
        $this->expirySeconds = $expirySeconds;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address') ?: config('mail.mailers.smtp.username') ?: 'noreply@masterintech.com';
        $fromName = config('mail.from.name') ?: 'MasterInTech';

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address($fromAddress, $fromName),
            subject: 'MasterInTech Student Login — Verification Code: ' . $this->otp,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.student_otp',
            with: [
                'otp' => $this->otp,
                'userName' => $this->user->name,
                'expirySeconds' => $this->expirySeconds,
            ],
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
