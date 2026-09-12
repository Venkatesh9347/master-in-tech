<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    /**
     * Enroll the authenticated user in a course.
     */
    public function enroll(Request $request, Course $course)
    {
        return response()->json([
            'message' => 'Public self-enrollment is disabled. Student portal access is granted by MasterInTech administration after counselling and admission.',
        ], 403);
    }

    /**
     * Check enrollment status for the authenticated user in a course.
     */
    public function check(Request $request, Course $course)
    {
        $user = $request->user();

        $enrollment = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if (! $enrollment) {
            return response()->json([
                'enrolled' => false,
            ]);
        }

        return response()->json([
            'enrolled' => true,
            'enrollment' => $enrollment,
        ]);
    }

    /**
     * Get all courses the authenticated user is enrolled in.
     */
    public function myCourses(Request $request)
    {
        $user = $request->user();

        if ($user && $user->isCompany()) {
            return response()->json([
                'message' => 'Corporate partners cannot access student course enrollments.',
            ], 403);
        }

        $enrollments = CourseEnrollment::where('user_id', $user->id)
            ->with(['course' => function ($query) {
                $query->select(['id', 'title', 'slug', 'category', 'difficulty', 'duration', 'thumbnail', 'status', 'is_published'])
                    ->withCount(['sections', 'lessons']);
            }])
            ->orderBy('enrolled_at', 'desc')
            ->get();

        if ($enrollments->isEmpty()) {
            return response()->json([]);
        }

        $courseIds = $enrollments->pluck('course_id')->filter()->unique()->values();

        // 1. Batch count total published lessons per course
        $totalPublishedLessons = \App\Models\Lesson::whereIn('course_id', $courseIds)
            ->where('is_published', true)
            ->select('course_id', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        // 2. Batch count completed lessons per course for this user
        $completedLessons = \App\Models\LessonProgress::where('lesson_progress.user_id', $user->id)
            ->whereIn('lesson_progress.course_id', $courseIds)
            ->where('lesson_progress.completed', true)
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->where('lessons.is_published', true)
            ->select('lesson_progress.course_id', \Illuminate\Support\Facades\DB::raw('count(*) as completed_count'))
            ->groupBy('lesson_progress.course_id')
            ->pluck('completed_count', 'lesson_progress.course_id');

        // 3. Batch load last accessed lesson per course for this user
        $lastAccessedLessons = \App\Models\LessonProgress::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('last_accessed_at')
            ->with('lesson:id,title,slug,duration')
            ->orderBy('last_accessed_at', 'desc')
            ->get()
            ->groupBy('course_id')
            ->map(fn ($items) => $items->first()?->lesson);

        foreach ($enrollments as $enrollment) {
            $total = (int) ($totalPublishedLessons->get($enrollment->course_id) ?? 0);
            $completed = (int) ($completedLessons->get($enrollment->course_id) ?? 0);
            $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
            $isCompleted = ($total > 0 && $completed >= $total);

            $enrollment->total_lessons = $total;
            $enrollment->completed_lessons = $completed;
            $enrollment->progress_percentage = $percentage;
            $enrollment->last_accessed_lesson = $lastAccessedLessons->get($enrollment->course_id) ?? null;
            $enrollment->is_course_completed = $isCompleted;

            if ($enrollment->progress_percentage != $percentage || ($isCompleted && $enrollment->status === 'active')) {
                // B3: my-courses progress sync must never reactivate a pending/
                // cancelled/dropped enrollment without verified payment.
                $enrollment->update([
                    'progress_percentage' => $percentage,
                    'status' => $isCompleted && $enrollment->status === 'active' ? 'completed' : $enrollment->status,
                ]);
            }
        }

        return response()->json($enrollments);
    }
}
