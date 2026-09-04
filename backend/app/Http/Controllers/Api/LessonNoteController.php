<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonNote;
use Illuminate\Http\Request;

class LessonNoteController extends Controller
{
    /**
     * Get the authenticated student's personal notes for a specific lesson.
     */
    public function show(Request $request, $courseId, $lessonId)
    {
        $user = $request->user();

        $lesson = Lesson::where('id', $lessonId)->where('course_id', $courseId)->first();

        if (! $lesson) {
            return response()->json(['message' => 'Lesson not found in this course.'], 404);
        }

        $this->authorizeCourseAccess($user, $courseId);

        $note = LessonNote::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        return response()->json([
            'note' => $note ? $note->note_text : '',
            'updated_at' => $note ? $note->updated_at->toIso8601String() : null,
        ]);
    }

    /**
     * Save or update personal note for a specific lesson.
     */
    public function store(Request $request, $courseId, $lessonId)
    {
        $user = $request->user();

        $lesson = Lesson::where('id', $lessonId)->where('course_id', $courseId)->first();

        if (! $lesson) {
            return response()->json(['message' => 'Lesson not found in this course.'], 404);
        }

        $this->authorizeCourseAccess($user, $courseId);

        $validated = $request->validate([
            'note' => 'nullable|string',
        ]);

        $note = LessonNote::updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            [
                'course_id' => $lesson->course_id,
                'note_text' => $validated['note'] ?? '',
            ]
        );

        return response()->json([
            'message' => 'Personal note saved successfully.',
            'note' => $note->note_text,
            'updated_at' => $note->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Ensure the authenticated user has access to the given course.
     */
    private function authorizeCourseAccess($user, $courseId): void
    {
        if (in_array($user->role, ['admin', 'tutor'], true)) {
            return;
        }

        $isEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('status', '!=', 'dropped')
            ->exists();

        if (! $isEnrolled) {
            abort(403, 'Course Access Required. You must have an active enrollment in this course to access lesson notes.');
        }
    }
}
