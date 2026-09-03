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

        $note = LessonNote::where('user_id', $user->id)
            ->where('lesson_id', $lessonId)
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

        $validated = $request->validate([
            'note' => 'nullable|string',
        ]);

        $note = LessonNote::updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lessonId],
            [
                'course_id' => $courseId,
                'note_text' => $validated['note'] ?? '',
            ]
        );

        return response()->json([
            'message' => 'Personal note saved successfully.',
            'note' => $note->note_text,
            'updated_at' => $note->updated_at->toIso8601String(),
        ]);
    }
}
