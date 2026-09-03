<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\Request;

class CourseProgressController extends Controller
{
    /**
     * Get detailed course progress for the authenticated user.
     */
    public function show(Request $request, Course $course)
    {
        $user = $request->user();

        // Check active enrollment
        $enrollment = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', '!=', 'dropped')
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'Course Access Required. You must have an active enrollment in this course to view progress.',
                'enrollment_required' => true,
            ], 403);
        }

        // Get all published lessons for the course
        $publishedLessonIds = Lesson::where('course_id', $course->id)
            ->where('is_published', true)
            ->pluck('id')
            ->toArray();

        $totalLessons = count($publishedLessonIds);

        // Get completed lessons for this student
        $completedLessons = LessonProgress::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('completed', true)
            ->whereIn('lesson_id', $publishedLessonIds)
            ->get();

        $completedLessonIds = $completedLessons->pluck('lesson_id')->toArray();
        $completedCount = count($completedLessonIds);

        $percentage = $totalLessons > 0 ? round(($completedCount / $totalLessons) * 100, 2) : 0;
        $isCourseCompleted = ($totalLessons > 0 && $completedCount >= $totalLessons);

        // Update enrollment progress & status
        $enrollment->update([
            'progress_percentage' => $percentage,
            'status' => $isCourseCompleted ? 'completed' : 'active',
        ]);

        // Determine current lesson for Continue Learning:
        // 1. Check last accessed incomplete published lesson
        $lastAccessedIncomplete = LessonProgress::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('completed', false)
            ->whereIn('lesson_id', $publishedLessonIds)
            ->whereNotNull('last_accessed_at')
            ->orderBy('last_accessed_at', 'desc')
            ->first();

        $currentLesson = null;
        if ($lastAccessedIncomplete) {
            $currentLesson = Lesson::where('id', $lastAccessedIncomplete->lesson_id)
                ->where('is_published', true)
                ->first();
        }

        // 2. If no incomplete lesson was accessed, pick the first incomplete published lesson in curriculum order
        if (! $currentLesson) {
            $currentLesson = Lesson::where('course_id', $course->id)
                ->where('is_published', true)
                ->whereNotIn('id', $completedLessonIds)
                ->orderBy('sort_order')
                ->first();
        }

        // 3. If all published lessons are completed, pick the last lesson
        if (! $currentLesson && $totalLessons > 0) {
            $currentLesson = Lesson::where('course_id', $course->id)
                ->where('is_published', true)
                ->orderBy('sort_order', 'desc')
                ->first();
        }

        // Get sections with published lessons only
        $sections = $course->sections()
            ->with(['lessons' => function ($query) {
                $query->where('is_published', true)
                    ->with(['quiz.questions.options', 'assignment', 'resources'])
                    ->orderBy('sort_order');
            }])
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->get();

        // Fetch all student progress records for this course to map started/last_accessed
        $allUserProgress = LessonProgress::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->get()
            ->keyBy('lesson_id');

        $lastAccessedLessonId = LessonProgress::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('lesson_id', $publishedLessonIds)
            ->whereNotNull('last_accessed_at')
            ->orderBy('last_accessed_at', 'desc')
            ->value('lesson_id');

        return response()->json([
            'course_id' => $course->id,
            'course_title' => $course->title,
            'progress_percentage' => $percentage,
            'progress_percent' => $percentage,
            'completed_lessons' => $completedLessonIds,
            'completed_lesson_count' => $completedCount,
            'total_lessons' => $totalLessons,
            'last_accessed_lesson_id' => $lastAccessedLessonId,
            'is_course_completed' => $isCourseCompleted,
            'course_completed' => $isCourseCompleted,
            'current_lesson' => $currentLesson,
            'enrollment' => [
                'status' => $enrollment->status,
                'progress_percentage' => $enrollment->progress_percentage,
                'enrolled_at' => $enrollment->enrolled_at,
            ],
            'sections' => $sections->map(function ($section) use ($completedLessonIds, $allUserProgress) {
                $section->lessons->each(function ($lesson) use ($completedLessonIds, $allUserProgress) {
                    $prog = $allUserProgress->get($lesson->id);
                    $isCompleted = in_array($lesson->id, $completedLessonIds);
                    $lesson->completed = $isCompleted;
                    $lesson->started = $prog ? (bool) $prog->started : false;
                    $lesson->status = $isCompleted ? 'completed' : ($prog && $prog->started ? 'in_progress' : 'not_started');
                    $lesson->progress_percentage = $prog ? (float) $prog->progress_percentage : ($isCompleted ? 100.0 : 0.0);
                    $lesson->last_playback_position = $prog ? (float) $prog->last_playback_position : 0.0;
                    $lesson->duration_seconds = $prog ? (float) $prog->duration_seconds : 0.0;
                    $lesson->last_accessed_at = $prog ? $prog->last_accessed_at : null;
                });
                return $section;
            }),
        ]);
    }
}
