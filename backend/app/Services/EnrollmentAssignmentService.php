<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\BatchTransfer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;

/**
 * Single source of truth for admissions: find-or-provision the student
 * account, activate the course enrollment idempotently, and (optionally)
 * assign the student to a cohort batch with an immutable audit trail.
 *
 * Used by the CRM -> LMS conversion engine so every admission writes the
 * same consistent user/enrollment/batch rows regardless of entry point.
 */
class EnrollmentAssignmentService
{
    /**
     * Find a user by email or provision a new student account.
     *
     * Staff roles (admin/super_admin/tutor/faculty) are preserved so an
     * existing staff account is never demoted by an admission flow; any other
     * matching account is normalised to an active student.
     */
    public function ensureStudentUser(string $name, string $email, ?string $phone = null, ?string $password = null): User
    {
        $email = strtolower(trim($email));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => ($password !== null && $password !== '') ? $password : User::generateUnusablePassword(),
                'status' => 'active',
            ]);

            // role is not mass-assignable; set explicitly.
            $user->forceFill(['role' => 'student'])->save();
        } else {
            if (! $user->canAccess('tutor')) {
                $user->role = 'student';
                $user->status = 'active';
            }
            if (empty($user->phone) && ! empty($phone)) {
                $user->phone = $phone;
            }
            $user->save();
        }

        if (empty($user->student_id)) {
            $user->student_id = 'STU-' . (1000 + $user->id);
            $user->save();
        }

        return $user;
    }

    /**
     * Idempotently ensure an active LMS enrollment for the pair.
     */
    public function ensureActiveEnrollment(User $user, Course $course): CourseEnrollment
    {
        $enrollment = CourseEnrollment::firstOrCreate(
            [
                'user_id' => $user->id,
                'course_id' => $course->id,
            ],
            [
                'enrolled_at' => now(),
                'status' => 'active',
                'progress_percentage' => 0.00,
            ]
        );

        if ($enrollment->status !== 'active') {
            $enrollment->update(['status' => 'active']);
        }

        return $enrollment;
    }

    /**
     * Assign a student to a cohort batch, creating the membership and an
     * immutable batch transfer audit record the first time. Re-assigning a
     * deactivated membership simply reactivates the original row so batch
     * history is never rewritten.
     */
    public function assignToBatch(
        User $user,
        Batch $batch,
        ?int $performedBy = null,
        string $actionType = 'enrolled',
        string $reason = '',
        ?string $notes = null
    ): ?BatchStudent {
        $membership = BatchStudent::where('batch_id', $batch->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership) {
            $membership = BatchStudent::create([
                'batch_id' => $batch->id,
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
                'notes' => $notes,
            ]);

            BatchTransfer::create([
                'user_id' => $user->id,
                'from_batch_id' => null,
                'to_batch_id' => $batch->id,
                'action_type' => $actionType,
                'reason' => $reason,
                'performed_by' => $performedBy,
            ]);
        } elseif ($membership->status !== 'active') {
            $membership->update([
                'status' => 'active',
                'left_at' => null,
                'discontinued_at' => null,
            ]);
        }

        return $membership;
    }
}