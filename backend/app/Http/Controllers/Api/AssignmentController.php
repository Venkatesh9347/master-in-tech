<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseEnrollment;
use Carbon\Carbon;
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
            ->whereNotIn('status', ['dropped', 'expired'])
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'You must enroll in the course to access this assignment.',
                'enrollment_required' => true,
            ], 403);
        }

        // HIGH-2: only published assignments are accessible to students.
        if (! $assignment->is_published) {
            return response()->json([
                'message' => 'This assignment is currently unpublished and unavailable.',
                'unpublished' => true,
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
            ->whereNotIn('status', ['dropped', 'expired'])
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'You must enroll in the course to submit assignments.',
                'enrollment_required' => true,
            ], 403);
        }

        // HIGH-2: only published assignments can be submitted.
        if (! $assignment->is_published) {
            return response()->json([
                'message' => 'This assignment is currently unpublished and unavailable.',
                'unpublished' => true,
            ], 403);
        }

        // M18 (PD-01): hard-block submissions after the due date. Deadlines are
        // compared in the business timezone so a course scheduled in IST (etc.)
        // does not drift because of the server/session UTC timezone.
        // A configurable grace window (default 0 = preserve the hard block)
        // accepts slightly-late work flagged is_late=true; see config/assignments.php.
        $isLate = false;
        if ($assignment->due_date) {
            $now = Carbon::now(config('app.business_timezone'));
            $due = $assignment->due_date->copy()->setTimezone(config('app.business_timezone'));

            if ($now->gt($due)) {
                $graceMinutes = (int) config('assignments.late_grace_minutes', 0);
                // Explicit timestamp math (Carbon diffs may be signed).
                $lateByMinutes = (int) floor(max(0, $now->getTimestamp() - $due->getTimestamp()) / 60);

                if ($lateByMinutes > $graceMinutes) {
                    return response()->json([
                        'message' => 'The submission deadline for this assignment has passed.',
                        'past_due' => true,
                        'due_date' => $assignment->due_date->toISOString(),
                    ], 403);
                }

                $isLate = true;
            }
        }

        $validated = $request->validate([
            'submission_text' => 'nullable|string',
            'file_url' => 'nullable|string|url',
        ]);

        // submitted_at is always set server-side (now()) and is never accepted from
        // the client, so a client-provided timestamp cannot bypass due-date logic
        // or misrepresent when the work was submitted.

        // Check if a submission already exists
        $existing = AssignmentSubmission::where('user_id', $user->id)
            ->where('assignment_id', $assignment->id)
            ->first();

        // M11: an already-graded submission must not be silently overwritten by a
        // student resubmission (which would un-grade and replace their work). The
        // grader must first return the submission so revision is explicit and
        // grading evidence is preserved.
        if ($existing && $existing->status === 'graded') {
            return response()->json([
                'message' => 'This submission has already been graded and cannot be resubmitted. Ask your tutor or admin to return it if revision is required.',
            ], 409);
        }

        if ($existing) {
            // Snapshot the superseded revision immutably before overwriting,
            // then bump the revision counter. Grading evidence is preserved
            // in the revision row even if the live row is later re-graded.
            \App\Models\AssignmentSubmissionRevision::create([
                'assignment_submission_id' => $existing->id,
                'revision_number' => (int) ($existing->revision_number ?? 1),
                'submission_text' => $existing->submission_text,
                'file_url' => $existing->file_url,
                'status' => $existing->status,
                'score' => $existing->score,
                'feedback' => $existing->feedback,
                'submitted_at' => $existing->submitted_at,
                'created_by' => $user->id,
            ]);

            // Update existing submission (first submitted_at is preserved).
            $existing->update([
                'submission_text' => $validated['submission_text'] ?? $existing->submission_text,
                'file_url' => $validated['file_url'] ?? $existing->file_url,
                'submitted_at' => $existing->submitted_at ?? now(),
                'is_late' => $isLate || (bool) $existing->is_late,
                'revision_number' => (int) ($existing->revision_number ?? 1) + 1,
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
            'is_late' => $isLate,
            'revision_number' => 1,
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
