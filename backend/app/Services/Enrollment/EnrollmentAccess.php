<?php

namespace App\Services\Enrollment;

use App\Models\AuditLog;
use App\Models\CourseEnrollment;
use App\Models\User;

/**
 * Central LMS access predicate for B3 pay-before-classroom.
 *
 * Only active/completed enrollments grant classroom access. Pending,
 * cancelled, dropped (and legacy expired) never grant access, even though
 * older checks used `status != dropped`.
 */
class EnrollmentAccess
{
    /**
     * Statuses that grant LMS/classroom access.
     *
     * @return string[]
     */
    public static function activeStatuses(): array
    {
        return ['active', 'completed'];
    }

    public static function hasLmsAccess(int $userId, int $courseId): bool
    {
        if ($userId <= 0 || $courseId <= 0) {
            return false;
        }

        return CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->whereIn('status', self::activeStatuses())
            ->exists();
    }

    /**
     * Record an emergency administrative admission override.
     * Keeps authoritative PaymentTransaction state separate: this audit entry
     * is an administrative admission, never a verified Razorpay payment.
     */
    public static function logOverride(
        User $actor,
        int $targetUserId,
        int $courseId,
        ?string $previousEnrollmentStatus,
        string $resultingEnrollmentStatus,
        string $reason,
        bool $paymentVerified,
        $enrollmentModel = null
    ): void {
        AuditLog::log('enrollment_payment_override', $enrollmentModel, [
            'target_user_id' => $targetUserId,
            'course_id' => $courseId,
            'previous_enrollment_status' => $previousEnrollmentStatus,
            'override_by' => $actor->id,
            'override_by_role' => $actor->role,
        ], [
            'target_user_id' => $targetUserId,
            'course_id' => $courseId,
            'resulting_enrollment_status' => $resultingEnrollmentStatus,
            'override_reason' => $reason,
            'payment_verified' => $paymentVerified,
            'override_by' => $actor->id,
            'override_by_role' => $actor->role,
            'overridden_at' => now()->toISOString(),
        ]);
    }
}
