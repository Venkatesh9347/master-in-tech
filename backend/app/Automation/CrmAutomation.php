<?php

namespace App\Automation;

use App\DomainEvents\DomainEvent;
use App\Models\CourseEnrollment;
use App\Models\CrmActivity;
use App\Models\CrmFollowUp;
use App\Models\Enquiry;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\WebhookDispatcherService;

/**
 * Code-defined CRM automation (no configurable rules, no scheduler, no UI).
 *
 * Two hardcoded handlers, both idempotent via AutomationRunner claim rows
 * and safe to run inside business transactions (all writes join the
 * surrounding transaction; rollbacks undo everything including the claim).
 *
 * Handlers never throw into the bus loop, never touch webhooks, and never
 * emit follow-on bus events — there is no recursion path.
 */
final class CrmAutomation
{
    public const HANDLER_ENQUIRY_SYNC = 'crm.enquiry-sync';
    public const HANDLER_REFUND_NOTE = 'crm.refund-note';
    public const HANDLER_FOLLOWUP_DUE = 'crm.followup-due';

    public const EVENT_FOLLOWUP_DUE = 'followup.due';

    /**
     * Terminal enquiry states an automation run must never reopen.
     *
     * @var list<string>
     */
    private const TERMINAL_ENQUIRY_STATUSES = [
        Enquiry::STATUS_ENROLLED,
        Enquiry::STATUS_CONVERTED,
        Enquiry::STATUS_CLOSED,
        Enquiry::STATUS_LOST,
        Enquiry::STATUS_NOT_INTERESTED,
    ];

    public static function handle(DomainEvent $event): void
    {
        match ($event->name) {
            WebhookDispatcherService::EVENT_ENROLLMENT_CREATED => self::syncEnquiryOnEnrollment($event),
            WebhookDispatcherService::EVENT_PAYMENT_REFUNDED => self::noteRefundOnEnquiry($event),
            self::EVENT_FOLLOWUP_DUE => self::noteFollowUpDue($event),
            default => null,
        };
    }

    /**
     * Follow-up due → exactly one timeline activity for the due follow-up.
     * Informational only: enquiry status, assignment, and follow-up status
     * are never touched, and no further domain event is emitted.
     */
    private static function noteFollowUpDue(DomainEvent $event): void
    {
        $followUp = CrmFollowUp::find((int) ($event->payload['follow_up_id'] ?? 0));

        // The follow-up may have been completed/cancelled after the
        // scheduler selected it; anything but pending safely no-ops.
        if ($followUp === null || $followUp->status !== CrmFollowUp::STATUS_PENDING) {
            return;
        }

        AutomationRunner::run(
            self::HANDLER_FOLLOWUP_DUE,
            $event,
            CrmFollowUp::class,
            (int) $followUp->id,
            function () use ($followUp, $event): void {
                // B1: the scheduler re-presents the same stable event every
                // minute, so a retry after a partial failure (activity
                // committed, success unmarked) must not create a second
                // activity. The trigger_event_id is unique per occurrence.
                $alreadyNoted = CrmActivity::where('enquiry_id', (int) $followUp->enquiry_id)
                    ->where('activity_type', 'follow_up')
                    ->whereJsonContains('metadata->trigger_event_id', $event->id)
                    ->exists();

                if ($alreadyNoted) {
                    return;
                }

                CrmActivity::create([
                    'enquiry_id' => (int) $followUp->enquiry_id,
                    'user_id' => null,
                    'activity_type' => 'follow_up',
                    'title' => "Follow-up due: {$followUp->title}",
                    'description' => "Follow-up #{$followUp->id} became due and requires attention.",
                    'metadata' => [
                        'follow_up_id' => (int) $followUp->id,
                        'trigger_event_id' => $event->id,
                        'scheduled_at' => $followUp->scheduled_at?->toISOString(),
                    ],
                ]);

                \App\Models\AuditLog::log(
                    'automation_followup_due',
                    $followUp,
                    null,
                    ['follow_up_id' => (int) $followUp->id, 'enquiry_id' => (int) $followUp->enquiry_id],
                    self::actor()
                );
            }
        );
    }

    /**
     * Enrollment created → close the linked open enquiry as enrolled.
     */
    private static function syncEnquiryOnEnrollment(DomainEvent $event): void
    {
        $enrollment = CourseEnrollment::find((int) ($event->payload['enrollment_id'] ?? 0));

        if ($enrollment === null) {
            return;
        }

        AutomationRunner::run(
            self::HANDLER_ENQUIRY_SYNC,
            $event,
            CourseEnrollment::class,
            (int) $enrollment->id,
            function () use ($enrollment, $event): void {
                $enquiry = self::openEnquiryFor((int) $enrollment->user_id, (int) $enrollment->course_id);

                if ($enquiry === null) {
                    return;
                }

                $old = $enquiry->toArray();

                $enquiry->update(['status' => Enquiry::STATUS_ENROLLED]);

                CrmActivity::create([
                    'enquiry_id' => $enquiry->id,
                    'user_id' => null,
                    'activity_type' => 'status_change',
                    'title' => 'Enquiry enrolled via admission',
                    'description' => "Status updated to 'enrolled' after enrollment #{$enrollment->id} was created.",
                    'metadata' => [
                        'enrollment_id' => (int) $enrollment->id,
                        'trigger_event_id' => $event->id,
                    ],
                ]);

                \App\Models\AuditLog::log(
                    'automation_enquiry_enrolled',
                    $enquiry,
                    $old,
                    $enquiry->fresh()->toArray(),
                    self::actor()
                );
            }
        );
    }

    /**
     * Payment refunded → append a payment timeline entry on the linked open
     * enquiry. Informational only; admission state is never touched.
     */
    private static function noteRefundOnEnquiry(DomainEvent $event): void
    {
        $transaction = PaymentTransaction::find((int) ($event->payload['payment_transaction_id'] ?? 0));

        if ($transaction === null || $transaction->user_id === null || $transaction->course_id === null) {
            return;
        }

        AutomationRunner::run(
            self::HANDLER_REFUND_NOTE,
            $event,
            PaymentTransaction::class,
            (int) $transaction->id,
            function () use ($transaction, $event): void {
                $enquiry = self::openEnquiryFor((int) $transaction->user_id, (int) $transaction->course_id);

                if ($enquiry === null) {
                    return;
                }

                CrmActivity::create([
                    'enquiry_id' => $enquiry->id,
                    'user_id' => null,
                    'activity_type' => 'payment_event',
                    'title' => 'Refund recorded for linked payment',
                    'description' => "Payment {$transaction->payment_id} was refunded.",
                    'metadata' => [
                        'payment_transaction_id' => (int) $transaction->id,
                        'amount_paise' => (int) ($event->payload['amount_paise'] ?? $transaction->amount_paise),
                        'currency' => (string) ($event->payload['currency'] ?? $transaction->currency),
                        'trigger_event_id' => $event->id,
                    ],
                ]);

                \App\Models\AuditLog::log(
                    'automation_refund_noted',
                    $enquiry,
                    null,
                    ['enquiry_id' => $enquiry->id, 'payment_transaction_id' => (int) $transaction->id],
                    self::actor()
                );
            }
        );
    }

    /**
     * Most recent non-terminal enquiry for the student+course, if any.
     */
    private static function openEnquiryFor(int $userId, int $courseId): ?Enquiry
    {
        if ($userId <= 0 || $courseId <= 0) {
            return null;
        }

        $email = User::where('id', $userId)->value('email');

        return Enquiry::where('course_id', $courseId)
            ->whereNotIn('status', self::TERMINAL_ENQUIRY_STATUSES)
            ->where(function ($query) use ($userId, $email): void {
                $query->where('user_id', $userId);

                if (is_string($email) && $email !== '') {
                    $query->orWhere('email', $email);
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * @return array{id: null, name: string}
     */
    private static function actor(): array
    {
        return ['id' => null, 'name' => 'Automation'];
    }
}
