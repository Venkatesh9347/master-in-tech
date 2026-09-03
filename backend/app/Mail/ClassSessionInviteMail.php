<?php

namespace App\Mail;

use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClassSessionInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ClassSession $classSession,
        public User $student
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MasterInTech — Online Training Meeting Invite: ' . $this->classSession->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildEmailHtml(),
        );
    }

    protected function buildEmailHtml(): string
    {
        $session = $this->classSession;
        $courseTitle = $session->course?->title ?? 'MasterInTech Course';
        $trainerName = $session->tutor?->name ?? 'Lead Faculty';
        $platform = ucfirst($session->platform ?? 'Zoom');
        $dateFormatted = $session->scheduled_date?->format('d F Y') ?? (string) $session->scheduled_date;
        $timeFormatted = "{$session->start_time} - {$session->end_time} IST";
        $meetingId = $session->meeting_id ?? 'N/A';
        $joinUrl = $session->meeting_url;

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>MasterInTech — Online Training Meeting Invite</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px; color: #1e293b;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <h2 style="color: #2563eb; margin-top: 0; font-size: 20px; border-bottom: 2px solid #eff6ff; padding-bottom: 12px;">MasterInTech — Online Training Meeting Invite</h2>
        <p style="font-size: 15px; line-height: 1.5;">Dear {$this->student->name},</p>
        <p style="font-size: 14px; line-height: 1.5; color: #475569;">This is a reminder for your upcoming training session.</p>

        <table style="width: 100%; border-collapse: collapse; margin: 20px 0; background: #f8fafc; border-radius: 8px; overflow: hidden;">
            <tr><td style="padding: 10px 16px; font-weight: bold; width: 120px; color: #64748b; font-size: 13px;">Course:</td><td style="padding: 10px 16px; font-weight: 600; font-size: 14px;">{$courseTitle}</td></tr>
            <tr><td style="padding: 10px 16px; font-weight: bold; color: #64748b; font-size: 13px;">Trainer:</td><td style="padding: 10px 16px; font-weight: 600; font-size: 14px;">{$trainerName}</td></tr>
            <tr><td style="padding: 10px 16px; font-weight: bold; color: #64748b; font-size: 13px;">Date:</td><td style="padding: 10px 16px; font-size: 14px;">{$dateFormatted}</td></tr>
            <tr><td style="padding: 10px 16px; font-weight: bold; color: #64748b; font-size: 13px;">Time:</td><td style="padding: 10px 16px; font-size: 14px;">{$timeFormatted}</td></tr>
            <tr><td style="padding: 10px 16px; font-weight: bold; color: #64748b; font-size: 13px;">Platform:</td><td style="padding: 10px 16px; font-size: 14px;">{$platform}</td></tr>
            <tr><td style="padding: 10px 16px; font-weight: bold; color: #64748b; font-size: 13px;">Meeting ID:</td><td style="padding: 10px 16px; font-family: monospace; font-size: 14px;">{$meetingId}</td></tr>
        </table>

        <div style="text-align: center; margin: 28px 0;">
            <a href="{$joinUrl}" target="_blank" style="background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block;">JOIN LIVE CLASS</a>
        </div>

        <div style="background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 4px; margin-top: 24px;">
            <p style="margin: 0; font-size: 12px; color: #1e40af; font-weight: 500;">
                <strong>Important Notice:</strong> Your meeting link will remain available until further notice.
            </p>
        </div>

        <p style="margin-top: 32px; font-size: 13px; color: #64748b; line-height: 1.5;">
            Thank You,<br>
            <strong style="color: #0f172a;">MasterInTech</strong>
        </p>
    </div>
</body>
</html>
HTML;
    }
}
