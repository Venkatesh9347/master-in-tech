<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\BatchTransfer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\VideoPlaybackSession;
use App\Services\Enrollment\EnrollmentAccess;
use App\Services\Enrollment\EnrollmentPaymentGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminEnrollmentController extends Controller
{
    /**
     * Get platform overview statistics for enrollments (admin only).
     */
    public function stats()
    {
        $totalEnrollments = CourseEnrollment::count();
        $activeEnrollments = CourseEnrollment::where('status', 'active')->count();
        $completedEnrollments = CourseEnrollment::where('status', 'completed')->count();
        $uniqueStudents = CourseEnrollment::distinct('user_id')->count('user_id');

        return response()->json([
            'total_enrollments' => $totalEnrollments,
            'active_enrollments' => $activeEnrollments,
            'completed_enrollments' => $completedEnrollments,
            'unique_students' => $uniqueStudents,
        ]);
    }

    /**
     * List all enrollments with search, filters, and relationships (admin only).
     */
    public function index(Request $request)
    {
        $query = CourseEnrollment::with([
            'user:id,name,email,student_id,status,avatar,role',
            'course:id,title,slug,category,difficulty,duration,thumbnail,instructor',
        ]);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('student_id', 'like', "%{$search}%");
                })->orWhereHas('course', function ($courseQuery) use ($search) {
                    $courseQuery->where('title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            });
        }

        $enrollments = $query->orderBy('enrolled_at', 'desc')
            ->orderBy('id', 'desc');

        $limit = $this->limitCap($request);
        if ($limit !== null) {
            $enrollments->limit($limit);
        }

        return response()->json($enrollments->get());
    }

    /**
     * Get all enrollments for a specific student with real-time lesson progress calculation (admin only).
     */
    public function studentEnrollments(User $user)
    {
        $enrollments = CourseEnrollment::where('user_id', $user->id)
            ->with([
                'course' => function ($query) {
                    $query->select(['id', 'title', 'slug', 'category', 'difficulty', 'duration', 'thumbnail', 'instructor', 'status', 'is_published'])
                        ->withCount(['sections', 'lessons']);
                },
            ])
            ->orderBy('enrolled_at', 'desc')
            ->get();

        if ($enrollments->isEmpty()) {
            return response()->json([]);
        }

        $courseIds = $enrollments->pluck('course_id')->filter()->unique()->values();

        // 1. Count total published lessons per course
        $totalPublishedLessons = Lesson::whereIn('course_id', $courseIds)
            ->where('is_published', true)
            ->select('course_id', DB::raw('count(*) as total'))
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        // 2. Count completed lessons per course for this student
        $completedLessons = LessonProgress::where('lesson_progress.user_id', $user->id)
            ->whereIn('lesson_progress.course_id', $courseIds)
            ->where('lesson_progress.completed', true)
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->where('lessons.is_published', true)
            ->select('lesson_progress.course_id', DB::raw('count(*) as completed_count'))
            ->groupBy('lesson_progress.course_id')
            ->pluck('completed_count', 'lesson_progress.course_id');

        foreach ($enrollments as $enrollment) {
            $total = (int) ($totalPublishedLessons->get($enrollment->course_id) ?? 0);
            $completed = (int) ($completedLessons->get($enrollment->course_id) ?? 0);
            $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : (float) $enrollment->progress_percentage;

            $enrollment->total_lessons = $total;
            $enrollment->completed_lessons = $completed;
            $enrollment->calculated_progress_percentage = $percentage;
        }

        return response()->json($enrollments);
    }

    /**
     * Assign a course to an existing student (or provision by email) (admin only).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required_without:email|nullable|exists:users,id',
            'email' => 'required_without:user_id|nullable|email',
            'name' => 'nullable|string|max:255',
            'course_id' => 'required|exists:courses,id',
            'status' => 'nullable|string|in:active,completed,pending,cancelled',
            'batch_id' => 'nullable|exists:batches,id',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);

        // B3-2: only administrators may supply an override reason.
        $overrideReason = EnrollmentPaymentGate::extractOverrideReason(
            $request->user(),
            $validated['override_reason'] ?? null
        );

        $userId = $validated['user_id'] ?? null;

        if (! $userId && ! empty($validated['email'])) {
            $user = User::firstOrCreate(
                ['email' => strtolower(trim($validated['email']))],
                [
                    'name' => $validated['name'] ?? explode('@', $validated['email'])[0],
                    'password' => \App\Models\User::generateUnusablePassword(),
                    'status' => 'active',
                ]
            );

            // HIGH-7: role is not mass-assignable; set explicitly for new users.
            if ($user->wasRecentlyCreated) {
                $user->forceFill(['role' => 'student'])->save();
            }

            if ($user->role === 'student' && empty($user->student_id)) {
                $user->student_id = 'STU-' . (1000 + $user->id);
                $user->save();
            }

            $userId = $user->id;
        }

        if (! $userId) {
            return response()->json([
                'message' => 'Please select a student or provide a valid student email.',
                'errors' => ['user_id' => ['Please select a student or provide a valid student email.']],
            ], 422);
        }

        $courseId = (int) $validated['course_id'];
        $requestedStatus = $validated['status'] ?? 'active';

        // B3 pay-before-classroom: active/completed require verified payment
        // for this exact user + course, or an explicit admin override.
        // Without either, the enrollment is created pending and LMS access
        // remains blocked. Pending/cancelled requests are honored as-is.
        $gate = EnrollmentPaymentGate::resolveStatus(
            (int) $userId,
            $courseId,
            $request->user(),
            $overrideReason
        );

        if (in_array($requestedStatus, ['active', 'completed'], true)
            && $gate['status'] !== EnrollmentPaymentGate::STATUS_ACTIVE) {
            $status = EnrollmentPaymentGate::STATUS_PENDING;
            $viaOverride = false;
            $paymentVerified = false;
            $paymentRequired = true;
        } elseif (in_array($requestedStatus, ['active', 'completed'], true)) {
            $status = $requestedStatus === 'completed' ? 'completed' : 'active';
            $viaOverride = $gate['via_override'];
            $paymentVerified = $gate['payment_verified'];
            $paymentRequired = false;
            // Completed without a verified payment is only reachable via an
            // explicit override; resolveStatus already enforces that.
        } else {
            $status = $requestedStatus;
            $viaOverride = false;
            $paymentVerified = $gate['payment_verified'];
            $paymentRequired = false;
        }

        // Check if student is already enrolled in this course
        $existing = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Student is already enrolled in this course.',
                'errors' => [
                    'course_id' => ['Student is already enrolled in this course.'],
                ],
                'enrollment' => $existing,
            ], 422);
        }

        // The enrollment + cohort write set commits atomically.
        try {
            DB::beginTransaction();

            $enrollment = CourseEnrollment::create([
                'user_id' => $userId,
                'course_id' => $courseId,
                'enrolled_at' => now(),
                'status' => $status,
                'progress_percentage' => 0.00,
            ]);

            $enrollment->load([
                'user:id,name,email,student_id,status,avatar,role',
                'course:id,title,slug,category,difficulty,duration,thumbnail,instructor',
            ]);

            // Optionally assign the student to a cohort batch (audited), consistent
            // with the CRM conversion flow. Batch history is never deleted.
            if (! empty($validated['batch_id']) && $status === 'active') {
                $batch = Batch::find($validated['batch_id']);
                if ($batch && (int) $batch->course_id === $courseId) {
                    $existingBatchStudent = BatchStudent::where('batch_id', $batch->id)
                        ->where('user_id', $userId)
                        ->first();

                    if (! $existingBatchStudent) {
                        BatchStudent::create([
                            'batch_id' => $batch->id,
                            'user_id' => $userId,
                            'status' => 'active',
                            'joined_at' => now(),
                            'notes' => 'Assigned during admin enrollment creation',
                        ]);

                        BatchTransfer::create([
                            'user_id' => $userId,
                            'from_batch_id' => null,
                            'to_batch_id' => $batch->id,
                            'action_type' => 'enrolled',
                            'reason' => 'Admin created enrollment with cohort assignment',
                            'performed_by' => $request->user()->id,
                        ]);
                    } elseif ($existingBatchStudent->status !== 'active') {
                        $existingBatchStudent->update([
                            'status' => 'active',
                            'left_at' => null,
                            'discontinued_at' => null,
                        ]);
                    }
                }
            }

            AuditLog::log('created_enrollment', $enrollment, null, $enrollment->toArray());

            if ($viaOverride) {
                EnrollmentAccess::logOverride(
                    $request->user(),
                    (int) $userId,
                    $courseId,
                    null,
                    $enrollment->status,
                    (string) $overrideReason,
                    $paymentVerified,
                    $enrollment
                );
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create enrollment: ' . $e->getMessage(),
            ], 500);
        }

        if (! empty($paymentRequired)) {
            return response()->json([
                'message' => 'Student pre-admitted pending verified payment. LMS access remains blocked until payment is verified.',
                'payment_required' => true,
                'enrollment' => $enrollment,
            ], 201);
        }

        return response()->json([
            'message' => 'Course successfully assigned to student.',
            'payment_verified' => $paymentVerified,
            'via_override' => $viaOverride,
            'enrollment' => $enrollment,
        ], 201);
    }

    /**
     * Update an enrollment's status (admin only).
     */
    public function update(Request $request, CourseEnrollment $enrollment)
    {
        $old = $enrollment->toArray();

        $validated = $request->validate([
            'status' => 'required|string|in:active,completed,pending,cancelled',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);

        $target = $validated['status'];

        // B3: activating (or completing) requires verified payment or an
        // explicit admin override. Deactivating to pending/cancelled is safe.
        if (in_array($target, ['active', 'completed'], true)
            && ! in_array($enrollment->status, ['active', 'completed'], true)) {
            $overrideReason = EnrollmentPaymentGate::extractOverrideReason(
                $request->user(),
                $validated['override_reason'] ?? null
            );

            if (! EnrollmentPaymentGate::canActivate(
                (int) $enrollment->user_id,
                (int) $enrollment->course_id,
                $request->user(),
                $overrideReason
            )) {
                return response()->json([
                    'message' => 'Verified payment is required before this enrollment can be activated.',
                    'payment_required' => true,
                    'enrollment' => $enrollment,
                ], 422);
            }

            if ($overrideReason !== null) {
                EnrollmentAccess::logOverride(
                    $request->user(),
                    (int) $enrollment->user_id,
                    (int) $enrollment->course_id,
                    $enrollment->status,
                    $target,
                    $overrideReason,
                    EnrollmentPaymentGate::hasVerifiedPaidPayment((int) $enrollment->user_id, (int) $enrollment->course_id),
                    $enrollment
                );
            }
        }

        $previousStatus = $enrollment->status;

        $enrollment->update([
            'status' => $target,
        ]);

        // S-03: when access is revoked (active/completed -> pending/cancelled),
        // invalidate this user's playback sessions for this course only.
        if (in_array($previousStatus, ['active', 'completed'], true)
            && in_array($target, ['pending', 'cancelled'], true)) {
            VideoPlaybackSession::where('user_id', $enrollment->user_id)
                ->where('course_id', $enrollment->course_id)
                ->delete();
        }

        $enrollment->load([
            'user:id,name,email,student_id,status,avatar,role',
            'course:id,title,slug,category,difficulty,duration,thumbnail,instructor',
        ]);

        AuditLog::log('updated_enrollment', $enrollment, $old, $enrollment->toArray());

        return response()->json([
            'message' => 'Enrollment status updated successfully.',
            'enrollment' => $enrollment,
        ]);
    }

    /**
     * Remove an enrollment / unenroll student (admin only).
     */
    public function destroy(CourseEnrollment $enrollment)
    {
        $old = $enrollment->toArray();
        $revokedUserId = $enrollment->user_id;
        $revokedCourseId = $enrollment->course_id;
        $enrollment->delete();

        // S-03: enrollment deletion revokes course access; invalidate that
        // user's playback sessions for this course only.
        VideoPlaybackSession::where('user_id', $revokedUserId)
            ->where('course_id', $revokedCourseId)
            ->delete();

        AuditLog::log('deleted_enrollment', null, $old, null);

        return response()->json([
            'message' => 'Student enrollment removed successfully.',
        ]);
    }
}
