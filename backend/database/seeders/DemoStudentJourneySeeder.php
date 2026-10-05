<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentPaymentGate;
use Illuminate\Database\Seeder;

/**
 * Demo fixture: gives the designated demo student a real, active enrollment so
 * the ALREADY-IMPLEMENTED student journey can be demonstrated locally:
 *
 *     enrolled course -> lessons -> lesson progress -> completion -> certificate
 *
 * Deliberate scope limits
 * ----------------------
 *  - This seeder creates ONLY the enrollment. Every downstream record
 *    (LessonProgress, QuizAttempt, AssignmentSubmission, Certificate) is
 *    produced by the application's own HTTP endpoints, so nothing here
 *    fabricates progress or a certificate.
 *  - The enrollment status is resolved through the production service
 *    EnrollmentPaymentGate with an explicit, human-readable override reason
 *    attributed to the admin account. The business rule is therefore honoured
 *    and auditable rather than bypassed: without that reason the gate would
 *    return `pending` and withhold classroom access (B3 pay-before-classroom).
 *  - Fully idempotent: re-running updates the same enrollment row instead of
 *    creating duplicates, and never touches another student's data.
 *  - Intentionally NOT wired into DatabaseSeeder, so CI's
 *    `migrate:fresh --seed` is unaffected. Run explicitly:
 *        php artisan db:seed --class=Database\\Seeders\\DemoStudentJourneySeeder
 */
class DemoStudentJourneySeeder extends Seeder
{
    /** Demo student account (seeded by DatabaseSeeder). */
    private const STUDENT_EMAIL = 'student@example.com';

    /** Admin actor recorded as the authorising override. */
    private const ADMIN_EMAIL = 'admin@example.com';

    /**
     * Course used for the demonstration journey. Chosen because it is an
     * existing published catalog entry with published sections and lessons,
     * a working thumbnail, and no pre-existing enrollments to disturb.
     */
    private const DEMO_COURSE_SLUG = 'artificial-intelligence-fundamentals';

    public function run(): void
    {
        $student = User::where('email', self::STUDENT_EMAIL)->first();
        if (! $student) {
            $this->command?->warn('Demo student ['.self::STUDENT_EMAIL.'] not found - skipping.');

            return;
        }

        $course = Course::where('slug', self::DEMO_COURSE_SLUG)->first();
        if (! $course) {
            $this->command?->warn('Demo course ['.self::DEMO_COURSE_SLUG.'] not found - skipping.');

            return;
        }

        $admin = User::where('email', self::ADMIN_EMAIL)->first();

        $overrideReason = 'Local demo fixture: seeded enrollment so the implemented '
            .'student journey (enrollment -> lessons -> progress -> completion -> '
            .'certificate) can be demonstrated without a live payment provider.';

        // Production business rule: a verified paid payment activates the
        // enrollment; otherwise an authorised admin override may do so.
        $resolved = EnrollmentPaymentGate::resolveStatus(
            $student->id,
            $course->id,
            $admin,
            $overrideReason,
        );

        if ($resolved['status'] !== EnrollmentPaymentGate::STATUS_ACTIVE) {
            $this->command?->warn(
                'Enrollment could not be activated (gate returned '
                .$resolved['status'].'). Is the admin account present?'
            );

            return;
        }

        // Idempotent: one enrollment row per (user, course).
        $enrollment = CourseEnrollment::firstOrNew([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $enrollment->status = $resolved['status'];
        $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
        $enrollment->progress_percentage = $enrollment->progress_percentage ?? 0;
        $enrollment->save();

        $this->command?->info(sprintf(
            'Demo enrollment ready: student #%d (%s) -> course #%d (%s), status=%s via_override=%s',
            $student->id,
            $student->email,
            $course->id,
            $course->title,
            $resolved['status'],
            $resolved['via_override'] ? 'yes' : 'no',
        ));
    }
}
