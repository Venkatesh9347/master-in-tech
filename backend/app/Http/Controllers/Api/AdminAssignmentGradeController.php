<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AdminAssignmentGradeController extends Controller
{
    /**
     * List all student assignment submissions (admin only).
     */
    public function index(Request $request)
    {
        $query = AssignmentSubmission::with([
            'user:id,name,email',
            'assignment:id,title,max_marks',
            'course:id,title',
        ])->orderBy('updated_at', 'desc');

        if ($request->has('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->has('status') && in_array($request->status, ['submitted', 'graded', 'returned'])) {
            $query->where('status', $request->status);
        }

        // Bounded server-side pagination (P1-B convention): the submissions
        // table grows with students x assignments, so the full collection
        // must never be serialized in one response. Filters, sorting, eager
        // loads, and item fields are unchanged; `id` breaks `updated_at`
        // ties so pages are deterministic.
        return response()->json(
            $query->orderBy('id', 'desc')
                ->paginate($this->perPage($request))
        );
    }

    /**
     * Grade a student assignment submission with score and feedback (admin only).
     */
    public function grade(Request $request, $id)
    {
        $submission = AssignmentSubmission::with(['assignment', 'lesson'])->find($id);

        if (! $submission) {
            return response()->json(['message' => 'Submission not found'], 404);
        }

        $maxMarks = $submission->assignment?->max_marks ?? 100;

        $validated = $request->validate([
            'score' => "required|numeric|min:0|max:{$maxMarks}",
            'feedback' => 'nullable|string|max:2000',
            'status' => 'sometimes|in:graded,returned',
        ]);

        $score = (float) $validated['score'];
        $percentage = $maxMarks > 0 ? ($score / $maxMarks) * 100 : 0;
        $passingGrade = $percentage >= 50.0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($submission, $validated, $score, $passingGrade, $request) {
            $old = $submission->only([
                'id', 'user_id', 'course_id', 'lesson_id', 'assignment_id', 'score', 'status',
            ]);

            $submission->update([
                'score' => $score,
                'feedback' => $validated['feedback'] ?? $submission->feedback,
                'status' => $validated['status'] ?? 'graded',
            ]);

            AuditLog::log('graded_assignment_submission', $submission, $old, [
                'id' => $submission->id,
                'user_id' => $submission->user_id,
                'course_id' => $submission->course_id,
                'lesson_id' => $submission->lesson_id,
                'assignment_id' => $submission->assignment_id,
                'score' => $score,
                'status' => $submission->status,
                'graded_by' => $request->user()?->id,
            ]);

            \App\Models\LearningActivityLog::logEvent(
                $submission->user_id,
                $submission->course_id,
                $submission->lesson_id,
                'assignment_graded',
                [
                    'graded_by' => $request->user()?->id,
                    'score' => $score,
                    'passing_grade' => $passingGrade,
                ]
            );

            if ($passingGrade) {
                \App\Models\LessonProgress::updateOrCreate(
                    [
                        'user_id' => $submission->user_id,
                        'course_id' => $submission->course_id,
                        'lesson_id' => $submission->lesson_id,
                    ],
                    [
                        'section_id' => $submission->lesson?->section_id,
                        'status' => 'completed',
                        'started' => true,
                        'completed' => true,
                        'completed_at' => now(),
                        'last_accessed_at' => now(),
                        'progress_percentage' => 100.0,
                    ]
                );

                // Recalculate course overall progress
                $totalPublishedLessons = \App\Models\Lesson::where('course_id', $submission->course_id)
                    ->where('is_published', true)
                    ->count();

                $completedPublishedCount = \App\Models\LessonProgress::where('lesson_progress.user_id', $submission->user_id)
                    ->where('lesson_progress.course_id', $submission->course_id)
                    ->where('lesson_progress.completed', true)
                    ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
                    ->where('lessons.is_published', true)
                    ->count();

                $courseProgressPercentage = $totalPublishedLessons > 0
                    ? round(($completedPublishedCount / $totalPublishedLessons) * 100, 2)
                    : 0;

                $isCourseCompleted = ($totalPublishedLessons > 0 && $completedPublishedCount >= $totalPublishedLessons);

                $enrollment = \App\Models\CourseEnrollment::where('user_id', $submission->user_id)
                    ->where('course_id', $submission->course_id)
                    ->first();

                if ($enrollment) {
                    $enrollment->update([
                        'progress_percentage' => $courseProgressPercentage,
                        'status' => $isCourseCompleted ? 'completed' : 'active',
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Assignment submission graded successfully.',
            'submission' => $submission->fresh()->load(['user:id,name,email', 'assignment', 'course:id,title']),
        ]);
    }
}
