<?php

namespace App\Services;

use App\Jobs\SendTemplatedMailJob;
use App\Mail\TemplatedNotificationMail;
use App\Models\BatchStudent;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LiveClass;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * F1 narrow notification infrastructure.
 *
 * This service is intentionally NOT a business-logic framework: each public
 * entry point maps to exactly one approved business event, recipients are
 * resolved from existing ownership relations, and the queued job only ever
 * renders templates from the explicit EVENT allowlist below. There is no
 * generic "send anything to anyone" API, no arbitrary view execution, and
 * no notification history writes.
 *
 * Transaction rule: callers dispatch only after the business transaction
 * has committed (post-commit). The job additionally re-resolves every
 * record and silently discards stale/missing state, so a rolled-back or
 * deleted record can never produce mail about a state that no longer
 * exists. Queue payloads carry stable IDs plus minimal immutable
 * snapshots — never full Eloquent models and never secrets.
 */
final class NotificationService
{
    public const EVENT_ENROLLMENT_CREATED = 'enrollment.created';

    public const EVENT_PAYMENT_CONFIRMED = 'payment.confirmed';

    public const EVENT_PAYMENT_FAILED = 'payment.failed';

    public const EVENT_BATCH_ASSIGNED = 'batch.assigned';

    public const EVENT_BATCH_TRANSFERRED = 'batch.transferred';

    public const EVENT_BATCH_DISCONTINUED = 'batch.discontinued';

    public const EVENT_CERTIFICATE_ISSUED = 'certificate.issued';

    public const EVENT_LIVE_CLASS_SCHEDULED = 'live_class.scheduled';

    public const EVENT_LIVE_CLASS_UPDATED = 'live_class.updated';

    public const EVENT_LIVE_CLASS_CANCELLED = 'live_class.cancelled';

    /**
     * @var list<string>
     */
    public const EVENTS = [
        self::EVENT_ENROLLMENT_CREATED,
        self::EVENT_PAYMENT_CONFIRMED,
        self::EVENT_PAYMENT_FAILED,
        self::EVENT_BATCH_ASSIGNED,
        self::EVENT_BATCH_TRANSFERRED,
        self::EVENT_BATCH_DISCONTINUED,
        self::EVENT_CERTIFICATE_ISSUED,
        self::EVENT_LIVE_CLASS_SCHEDULED,
        self::EVENT_LIVE_CLASS_UPDATED,
        self::EVENT_LIVE_CLASS_CANCELLED,
    ];

    public static function isSupported(string $event): bool
    {
        return in_array($event, self::EVENTS, true);
    }

    /**
     * Enrollment created (pending or active) via any of the three creation
     * paths. Call only after commit.
     */
    public static function enrollmentCreated(CourseEnrollment $enrollment): void
    {
        self::dispatch(
            self::EVENT_ENROLLMENT_CREATED,
            (int) $enrollment->user_id,
            ['enrollment_id' => (int) $enrollment->id]
        );
    }

    /**
     * Payment reached a terminal user-facing state. Only paid/failed ever
     * notify; every other status is ignored. Call only after commit.
     */
    public static function paymentStatusChanged(PaymentTransaction $transaction): void
    {
        $event = match ($transaction->status) {
            'paid' => self::EVENT_PAYMENT_CONFIRMED,
            'failed' => self::EVENT_PAYMENT_FAILED,
            default => null,
        };

        if ($event === null) {
            return;
        }

        self::dispatch($event, (int) $transaction->user_id, [
            'transaction_id' => (int) $transaction->id,
        ]);
    }

    /**
     * Batch membership change. $action is allowlisted; anything else is
     * ignored. Notifies the student and, when the batch resolves one, its
     * tutor. Call only after commit.
     */
    public static function batchMembershipChanged(string $action, BatchStudent $membership): void
    {
        $event = match ($action) {
            'assigned' => self::EVENT_BATCH_ASSIGNED,
            'transferred' => self::EVENT_BATCH_TRANSFERRED,
            'discontinued' => self::EVENT_BATCH_DISCONTINUED,
            default => null,
        };

        if ($event === null) {
            Log::warning('notification.unsupported_batch_action', ['action' => $action]);

            return;
        }

        $ref = [
            'membership_id' => (int) $membership->id,
            'student_id' => (int) $membership->user_id,
        ];

        self::dispatch($event, (int) $membership->user_id, $ref);

        $tutor = $membership->batch?->tutor;
        if ($tutor instanceof User && (int) $tutor->id !== (int) $membership->user_id) {
            self::dispatch($event, (int) $tutor->id, $ref);
        }
    }

    /**
     * Certificate issued. Call only after commit.
     */
    public static function certificateIssued(Certificate $certificate): void
    {
        self::dispatch(
            self::EVENT_CERTIFICATE_ISSUED,
            (int) $certificate->user_id,
            ['certificate_id' => (int) $certificate->id]
        );
    }

    /**
     * Live class scheduled or updated. Recipients are resolved here from
     * current active/completed enrollments. Call only after commit.
     */
    public static function liveClassScheduled(LiveClass $liveClass): void
    {
        foreach (self::enrolledStudentIds($liveClass->course_id) as $studentId) {
            self::dispatch(self::EVENT_LIVE_CLASS_SCHEDULED, $studentId, [
                'live_class_id' => (int) $liveClass->id,
            ]);
        }
    }

    public static function liveClassUpdated(LiveClass $liveClass): void
    {
        foreach (self::enrolledStudentIds($liveClass->course_id) as $studentId) {
            self::dispatch(self::EVENT_LIVE_CLASS_UPDATED, $studentId, [
                'live_class_id' => (int) $liveClass->id,
            ]);
        }
    }

    /**
     * Live class cancelled (record already deleted). The snapshot carries
     * only immutable display facts captured pre-delete; recipients were
     * enrolled at delete time. Call only after commit.
     *
     * @param array{title:string,class_date:?string,start_time:?string,course_title:string} $snapshot
     * @param list<int> $studentIds
     */
    public static function liveClassCancelled(array $snapshot, array $studentIds): void
    {
        foreach ($studentIds as $studentId) {
            self::dispatch(self::EVENT_LIVE_CLASS_CANCELLED, (int) $studentId, [
                'snapshot' => [
                    'title' => (string) ($snapshot['title'] ?? ''),
                    'class_date' => $snapshot['class_date'] ?? null,
                    'start_time' => $snapshot['start_time'] ?? null,
                    'course_title' => (string) ($snapshot['course_title'] ?? ''),
                ],
            ]);
        }
    }

    /**
     * Queued-job entry point. Resolves the recipient + current records,
     * discards unknown events and stale state, and sends. Delivery
     * exceptions propagate so failed_jobs/retry semantics apply.
     */
    public static function sendForJob(string $event, int $userId, array $ref): void
    {
        if (! self::isSupported($event)) {
            Log::warning('notification.unsupported_event', ['event' => $event]);

            return;
        }

        $user = User::find($userId);
        if (! $user || empty($user->email)) {
            return;
        }

        $built = self::build($event, $user, $ref);
        if ($built === null) {
            return;
        }

        Mail::to($user->email)->send(
            new TemplatedNotificationMail($built['subject'], $built['html'])
        );
    }

    /**
     * Resolve current records and render, or return null when stale.
     *
     * @return array{subject:string,html:string}|null
     */
    private static function build(string $event, User $user, array $ref): ?array
    {
        return match ($event) {
            self::EVENT_ENROLLMENT_CREATED => self::buildEnrollmentCreated($user, $ref),
            self::EVENT_PAYMENT_CONFIRMED => self::buildPayment($user, $ref, 'paid'),
            self::EVENT_PAYMENT_FAILED => self::buildPayment($user, $ref, 'failed'),
            self::EVENT_BATCH_ASSIGNED => self::buildBatch($user, $ref, 'active', 'assigned'),
            self::EVENT_BATCH_TRANSFERRED => self::buildBatch($user, $ref, 'active', 'transferred'),
            self::EVENT_BATCH_DISCONTINUED => self::buildBatch($user, $ref, 'discontinued', 'discontinued'),
            self::EVENT_CERTIFICATE_ISSUED => self::buildCertificate($user, $ref),
            self::EVENT_LIVE_CLASS_SCHEDULED => self::buildLiveClass($user, $ref, 'scheduled'),
            self::EVENT_LIVE_CLASS_UPDATED => self::buildLiveClass($user, $ref, 'updated'),
            self::EVENT_LIVE_CLASS_CANCELLED => self::buildLiveClassCancelled($user, $ref),
            default => null,
        };
    }

    private static function buildEnrollmentCreated(User $user, array $ref): ?array
    {
        $enrollment = isset($ref['enrollment_id'])
            ? CourseEnrollment::find($ref['enrollment_id'])
            : null;

        if (! $enrollment || (int) $enrollment->user_id !== (int) $user->id) {
            return null;
        }

        $course = $enrollment->course;
        $courseTitle = $course?->title ?? 'your course';
        $isActive = in_array($enrollment->status, ['active', 'completed'], true);

        return [
            'subject' => "You have been enrolled in {$courseTitle}",
            'html' => self::layout(
                'Enrollment confirmed',
                "Dear " . e($user->name) . ",",
                [
                    ['Course', $courseTitle],
                    ['Status', $enrollment->status],
                ],
                $isActive
                    ? 'Your classroom access is active. Open your student dashboard to begin learning.'
                    : 'Your admission is recorded and pending verification. Classroom access unlocks once verified.',
                '/student',
                'Open Student Dashboard'
            ),
        ];
    }

    private static function buildPayment(User $user, array $ref, string $expectedStatus): ?array
    {
        $transaction = isset($ref['transaction_id'])
            ? PaymentTransaction::find($ref['transaction_id'])
            : null;

        if (! $transaction
            || (int) $transaction->user_id !== (int) $user->id
            || $transaction->status !== $expectedStatus
        ) {
            return null;
        }

        $course = $transaction->course_id ? Course::find($transaction->course_id) : null;
        $amount = number_format(((int) $transaction->amount_paise) / 100, 2) . ' ' . $transaction->currency;

        if ($expectedStatus === 'paid') {
            return [
                'subject' => 'Payment confirmed' . ($course ? " — {$course->title}" : ''),
                'html' => self::layout(
                    'Payment confirmed',
                    "Dear " . e($user->name) . ",",
                    [
                        ['Order reference', $transaction->order_id],
                        ['Amount', $amount],
                        ['Course', $course?->title ?? '—'],
                    ],
                    'Your payment was verified successfully and your enrollment access has been updated.',
                    '/student',
                    'Open Student Dashboard'
                ),
            ];
        }

        return [
            'subject' => 'Payment failed' . ($course ? " — {$course->title}" : ''),
            'html' => self::layout(
                'Payment failed',
                "Dear " . e($user->name) . ",",
                [
                    ['Order reference', $transaction->order_id],
                    ['Amount', $amount],
                    ['Course', $course?->title ?? '—'],
                ],
                'Your payment could not be completed. No amount was charged by MasterInTech for this attempt — please try again or contact support.',
                '/student',
                'Open Student Dashboard'
            ),
        ];
    }

    private static function buildBatch(User $user, array $ref, string $expectedStatus, string $action): ?array
    {
        $membership = isset($ref['membership_id'])
            ? BatchStudent::find($ref['membership_id'])
            : null;

        if (! $membership || (int) $membership->user_id !== (int) ($ref['student_id'] ?? 0)) {
            return null;
        }

        // The tutor copy of a membership notice resolves through the same
        // membership row; the recipient must be the student or the tutor of
        // the batch, and the row must still reflect the notified action.
        $isStudent = (int) $user->id === (int) $membership->user_id;
        $isTutor = (int) $user->id === (int) ($membership->batch?->tutor_id ?? 0);
        if (! ($isStudent || $isTutor)) {
            return null;
        }

        if ($membership->status !== $expectedStatus) {
            return null;
        }

        $batch = $membership->batch;
        $courseTitle = $batch?->course?->title ?? 'your course';
        $batchLabel = $batch?->code ?? $batch?->name ?? 'your batch';

        [$heading, $note] = match ($action) {
            'assigned' => ["You have been assigned to batch {$batchLabel}", 'Your cohort placement is active. Check your schedule for upcoming sessions.'],
            'transferred' => ["You have been transferred to batch {$batchLabel}", 'Your cohort placement has changed. Previous batch history is preserved.'],
            default => ["Update on your batch {$batchLabel}", 'Your cohort placement status has changed. Contact support if you have questions.'],
        };

        return [
            'subject' => $heading,
            'html' => self::layout(
                $heading,
                "Dear " . e($user->name) . ",",
                [
                    ['Batch', $batchLabel],
                    ['Course', $courseTitle],
                ],
                $note,
                '/student',
                'Open Student Dashboard'
            ),
        ];
    }

    private static function buildCertificate(User $user, array $ref): ?array
    {
        $certificate = isset($ref['certificate_id'])
            ? Certificate::find($ref['certificate_id'])
            : null;

        if (! $certificate || (int) $certificate->user_id !== (int) $user->id) {
            return null;
        }

        $courseTitle = $certificate->course?->title ?? 'your course';

        return [
            'subject' => "Your certificate for {$courseTitle} is ready",
            'html' => self::layout(
                'Certificate issued',
                "Dear " . e($user->name) . ",",
                [
                    ['Course', $courseTitle],
                    ['Certificate ID', $certificate->certificate_code],
                ],
                'Your certificate is ready. You can verify and download it below.',
                '/verify-certificate/' . $certificate->certificate_code,
                'Verify Certificate'
            ),
        ];
    }

    private static function buildLiveClass(User $user, array $ref, string $action): ?array
    {
        $liveClass = isset($ref['live_class_id'])
            ? LiveClass::find($ref['live_class_id'])
            : null;

        if (! $liveClass) {
            return null;
        }

        $courseTitle = $liveClass->course?->title ?? 'your course';
        $heading = $action === 'updated'
            ? "Live class updated: {$liveClass->title}"
            : "Live class scheduled: {$liveClass->title}";

        return [
            'subject' => $heading,
            'html' => self::layout(
                $heading,
                "Dear " . e($user->name) . ",",
                [
                    ['Class', $liveClass->title],
                    ['Course', $courseTitle],
                    ['Date', (string) ($liveClass->class_date ?? '')],
                    ['Time', (string) ($liveClass->start_time ?? '')],
                ],
                $action === 'updated'
                    ? 'The schedule or details of this class have changed. Please check the updated timing.'
                    : 'A live class has been scheduled for your course. Join from your student dashboard at the scheduled time.',
                '/student',
                'Open Student Dashboard'
            ),
        ];
    }

    private static function buildLiveClassCancelled(User $user, array $ref): ?array
    {
        $snapshot = $ref['snapshot'] ?? null;
        if (! is_array($snapshot) || ($snapshot['title'] ?? '') === '') {
            return null;
        }

        $heading = "Live class cancelled: {$snapshot['title']}";

        return [
            'subject' => $heading,
            'html' => self::layout(
                $heading,
                "Dear " . e($user->name) . ",",
                [
                    ['Class', (string) $snapshot['title']],
                    ['Course', (string) ($snapshot['course_title'] ?? '')],
                    ['Date', (string) ($snapshot['class_date'] ?? '')],
                    ['Time', (string) ($snapshot['start_time'] ?? '')],
                ],
                'This class will not take place as scheduled. A replacement will be announced if one is arranged.',
                '/student',
                'Open Student Dashboard'
            ),
        ];
    }

    /**
     * @return list<int>
     */
    private static function enrolledStudentIds(int $courseId): array
    {
        return CourseEnrollment::where('course_id', $courseId)
            ->whereIn('status', ['active', 'completed'])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private static function dispatch(string $event, int $userId, array $ref): void
    {
        try {
            SendTemplatedMailJob::dispatch($event, $userId, $ref);
        } catch (\Throwable $e) {
            Log::error('notification.dispatch.failed', [
                'event' => $event,
                'user_id' => $userId,
                'exception' => get_class($e),
            ]);
        }
    }

    private static function frontendUrl(): string
    {
        return rtrim((string) (env('FRONTEND_URL', config('app.url', 'http://localhost:5173'))), '/');
    }

    /**
     * Branded layout matching the existing MasterInTech email style.
     *
     * @param list<array{0:string,1:string}> $rows
     */
    private static function layout(
        string $heading,
        string $greeting,
        array $rows,
        string $note,
        string $ctaPath,
        string $ctaLabel
    ): string {
        $rowHtml = '';
        foreach ($rows as [$label, $value]) {
            $rowHtml .= '<tr><td style="padding: 10px 16px; font-weight: bold; width: 140px; color: #64748b; font-size: 13px;">' . e($label) . ':</td>'
                . '<td style="padding: 10px 16px; font-weight: 600; font-size: 14px;">' . e((string) $value) . '</td></tr>';
        }

        $ctaUrl = self::frontendUrl() . '/' . ltrim($ctaPath, '/');

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>MasterInTech — ' . e($heading) . '</title></head>'
            . '<body style="font-family: Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px; color: #1e293b;">'
            . '<div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 32px;">'
            . '<h2 style="color: #2563eb; margin-top: 0; font-size: 20px; border-bottom: 2px solid #eff6ff; padding-bottom: 12px;">MasterInTech — ' . e($heading) . '</h2>'
            . '<p style="font-size: 15px; line-height: 1.5;">' . e($greeting) . '</p>'
            . '<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background: #f8fafc; border-radius: 8px;">' . $rowHtml . '</table>'
            . '<p style="font-size: 14px; line-height: 1.5; color: #475569;">' . e($note) . '</p>'
            . '<div style="text-align: center; margin: 28px 0;">'
            . '<a href="' . e($ctaUrl) . '" target="_blank" style="background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block;">' . e($ctaLabel) . '</a>'
            . '</div>'
            . '<p style="margin-top: 32px; font-size: 13px; color: #64748b;">Thank You,<br><strong style="color: #0f172a;">MasterInTech</strong></p>'
            . '</div></body></html>';
    }
}
