<?php

namespace App\Services\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\PaymentTransaction;
use App\Models\User;

/**
 * B3 pay-before-classroom gate.
 *
 * Authoritative condition for ACTIVE LMS access:
 *   PaymentTransaction(user_id = exact student, course_id = exact course, status = paid)
 *
 * Nothing else grants active access: not client amounts, not CRM metadata,
 * not transaction IDs supplied by staff. Emergency admin admission uses an
 * explicit override reason and is recorded as an administrative admission,
 * never as a verified Razorpay payment.
 */
class EnrollmentPaymentGate
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    /**
     * Exact-match verified payment check. Never trusts CRM/client state.
     */
    public static function hasVerifiedPaidPayment(int $userId, int $courseId): bool
    {
        if ($userId <= 0 || $courseId <= 0) {
            return false;
        }

        return PaymentTransaction::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', 'paid')
            ->exists();
    }

    /**
     * Only real administrators may override. Scoped CRM staff
     * (counsellor/telecaller/course_advisor) can never override.
     */
    public static function isOverrideActor(?User $user): bool
    {
        return $user !== null && $user->isAdmin();
    }

    /**
     * Resolve the enrollment status for a new admission request.
     *
     * @return array{status: string, via_override: bool, payment_verified: bool}
     */
    public static function resolveStatus(int $userId, int $courseId, ?User $actor, ?string $overrideReason): array
    {
        if (self::hasVerifiedPaidPayment($userId, $courseId)) {
            return ['status' => self::STATUS_ACTIVE, 'via_override' => false, 'payment_verified' => true];
        }

        $reason = trim((string) $overrideReason);

        if ($reason !== '' && self::isOverrideActor($actor)) {
            return ['status' => self::STATUS_ACTIVE, 'via_override' => true, 'payment_verified' => false];
        }

        return ['status' => self::STATUS_PENDING, 'via_override' => false, 'payment_verified' => false];
    }

    /**
     * Whether a requested status transition to active/completed is allowed.
     * Pending/cancelled/dropped transitions are always allowed (they remove access).
     */
    public static function canActivate(int $userId, int $courseId, ?User $actor, ?string $overrideReason): bool
    {
        $resolved = self::resolveStatus($userId, $courseId, $actor, $overrideReason);

        return $resolved['status'] === self::STATUS_ACTIVE;
    }

    /**
     * Validate an override reason supplied by a caller. Returns the trimmed
     * reason when usable, or null when no override was requested.
     * Aborts 403 when a non-admin attempts to override.
     */
    public static function extractOverrideReason(?User $actor, mixed $rawReason): ?string
    {
        $reason = is_string($rawReason) ? trim($rawReason) : '';

        if ($reason === '') {
            return null;
        }

        if (! self::isOverrideActor($actor)) {
            abort(403, 'Only administrators can use a payment override.');
        }

        return $reason;
    }
}
