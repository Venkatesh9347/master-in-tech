<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\EnrollmentAssignmentService;
use App\Services\BatchAssignmentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminEnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentAssignmentService $enrollments)
    {
    }
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
            'status' => 'nullable|string|in:active,completed,dropped',
            'batch_id' => 'nullable|exists:batches,id',
        ]);

        $userId = $validated['user_id'] ?? null;

        if (! $userId && ! empty($validated['email'])) {
            // Provision via the shared admission service so the CRM, enquiry and
            // admin enrollment flows all write the same user rows.
            $user = $this->enrollments->ensureStudentUser(
                $validated['name'] ?? explode('@', $validated['email'])[0],
                $validated['email'],
                null
            );

            $userId = $user->id;
        }

        if (! $userId) {
            return response()->json([
                'message' => 'Please select a student or provide a valid student email.',
                'errors' => ['user_id' => ['Please select a student or provide a valid student email.']],
            ], 422);
        }

        $courseId = (int) $validated['course_id'];
        $status = $validated['status'] ?? 'active';

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

        $enrollment = CourseEnrollment::create([
            'user_id' => $userId,
            'course_id' => $courseId,
            'enrolled_at' => now(),
            'status' => $status,
            'progress_percentage' => 0.00,
        ]);

        // Optional cohort batch assignment via the shared admission service
        // (immutable transfer history, never duplicated or rewritten).
        $batch = null;
        $batchMembership = null;
        if (! empty($validated['batch_id']) && $status === 'active') {
            $batch = Batch::find((int) $validated['batch_id']);
            if ($batch && (int) $batch->course_id !== $courseId) {
                return response()->json([
                    'message' => "The selected cohort batch ({$batch->code}) belongs to a different course. Please choose a batch for the selected course.",
                    'errors' => [
                        'batch_id' => ["The selected cohort batch ({$batch->code}) belongs to a different course."],
                    ],
                ], 422);
            }
            if ($batch) {
                try {
                    $batchMembership = $this->enrollments->assignToBatch(
                        User::findOrFail($userId),
                        $batch,
                        $request->user()->id,
                        'enrolled',
                        'Admin created enrollment with cohort assignment',
                        'Assigned during admin enrollment creation'
                    );
                } catch (BatchAssignmentException $e) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'errors' => [
                            'batch_id' => [$e->getMessage()],
                        ],
                        'enrollment' => $enrollment,
                    ], 422);
                }
            }
        }

        $enrollment->load([
            'user:id,name,email,student_id,status,avatar,role',
            'course:id,title,slug,category,difficulty,duration,thumbnail,instructor',
        ]);

        AuditLog::log('created_enrollment', $enrollment, null, $enrollment->toArray());

        return response()->json([
            'message' => 'Course successfully assigned to student.',
            'enrollment' => $enrollment,
            'batch' => $batch,
            'batch_membership' => $batchMembership,
        ], 201);
    }

    /**
     * Update an enrollment's status (admin only).
     */
    public function update(Request $request, CourseEnrollment $enrollment)
    {
        $old = $enrollment->toArray();

        $validated = $request->validate([
            'status' => 'required|string|in:active,completed,dropped',
        ]);

        $enrollment->update([
            'status' => $validated['status'],
        ]);

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
        $enrollment->delete();

        AuditLog::log('deleted_enrollment', null, $old, null);

        return response()->json([
            'message' => 'Student enrollment removed successfully.',
        ]);
    }
}
