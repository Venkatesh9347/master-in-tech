<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    /**
     * Display assignment details for a student (enrolled only).
     */
    public function show(Request $request, Assignment $assignment)
    {
        $user = $request->user();

        $assignment = $assignment->load('lesson.section');

        // Check enrollment
        $enrollment = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $assignment->course_id)
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'You must enroll in the course to access this assignment.',
                'enrollment_required' => true,
            ], 403);
        }

        // Get existing submission
        $submission = AssignmentSubmission::where('user_id', $user->id)
            ->where('assignment_id', $assignment->id)
            ->first();

        return response()->json([
            'assignment' => [
                'id' => $assignment->id,
                'lesson_id' => $assignment->lesson_id,
                'course_id' => $assignment->course_id,
                'title' => $assignment->title,
                'instructions' => $assignment->instructions,
                'due_date' => $assignment->due_date,
                'max_marks' => $assignment->max_marks,
                'file_url' => $assignment->file_url,
            ],
            'submission' => $submission,
        ]);
    }

    /**
     * Submit an assignment (student).
     */
    public function submit(Request $request, Assignment $assignment)
    {
        $user = $request->user();

        // Check enrollment
        $enrollment = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $assignment->course_id)
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'You must enroll in the course to submit assignments.',
                'enrollment_required' => true,
            ], 403);
        }

        $validated = $request->validate([
            'submission_text' => 'nullable|string',
            'file_url' => 'nullable|string|url',
        ]);

        // Check if a submission already exists
        $existing = AssignmentSubmission::where('user_id', $user->id)
            ->where('assignment_id', $assignment->id)
            ->first();

        if ($existing) {
            // Update existing submission
            $existing->update([
                'submission_text' => $validated['submission_text'] ?? $existing->submission_text,
                'file_url' => $validated['file_url'] ?? $existing->file_url,
                'submitted_at' => $existing->submitted_at ?? now(),
                'status' => 'submitted',
            ]);

            \App\Models\LessonProgress::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $assignment->course_id,
                    'lesson_id' => $assignment->lesson_id,
                ],
                [
                    'section_id' => $assignment->lesson?->section_id,
                    'status' => 'in_progress',
                    'started' => true,
                    'started_at' => now(),
                    'last_accessed_at' => now(),
                ]
            );

            \App\Models\LearningActivityLog::logEvent(
                $user->id,
                $assignment->course_id,
                $assignment->lesson_id,
                'assignment_submitted',
                ['assignment_id' => $assignment->id, 'action' => 'updated']
            );

            return response()->json([
                'message' => 'Assignment updated successfully.',
                'submission' => $existing->fresh(),
            ]);
        }

        $submission = AssignmentSubmission::create([
            'user_id' => $user->id,
            'assignment_id' => $assignment->id,
            'lesson_id' => $assignment->lesson_id,
            'course_id' => $assignment->course_id,
            'submission_text' => $validated['submission_text'] ?? null,
            'file_url' => $validated['file_url'] ?? null,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        \App\Models\LessonProgress::updateOrCreate(
            [
                'user_id' => $user->id,
                'course_id' => $assignment->course_id,
                'lesson_id' => $assignment->lesson_id,
            ],
            [
                'section_id' => $assignment->lesson?->section_id,
                'status' => 'in_progress',
                'started' => true,
                'started_at' => now(),
                'last_accessed_at' => now(),
            ]
        );

        \App\Models\LearningActivityLog::logEvent(
            $user->id,
            $assignment->course_id,
            $assignment->lesson_id,
            'assignment_submitted',
            ['assignment_id' => $assignment->id, 'action' => 'created']
        );

        return response()->json([
            'message' => 'Assignment submitted successfully.',
            'submission' => $submission,
        ], 201);
    }
}
