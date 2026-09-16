<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LessonController extends Controller
{
    /**
     * Display a listing of lessons for a section (public view).
     */
    public function index(Request $request, Course $course, Section $section)
    {
        if ($section->course_id !== $course->id) {
            abort(404, 'Section not found for this course.');
        }

        $lessons = $section->lessons()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json($lessons);
    }

    /**
     * Display the specified lesson (authenticated + enrolled).
     */
    public function show(Request $request, Course $course, Section $section, Lesson $lesson)
    {
        if ($section->course_id !== $course->id) {
            abort(404, 'Section not found for this course.');
        }

        if ($lesson->section_id !== $section->id) {
            abort(404, 'Lesson not found for this section.');
        }

        $user = $request->user();

        // Check active enrollment and publication status for student access
        if (! $user->isAdmin() && (int) $course->instructor_id !== (int) $user->id) {
            $enrolled = $course->enrollments()
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            if (! $enrolled) {
                return response()->json([
                    'message' => 'Course Access Required. You must have an active enrollment in this course to access lessons.',
                    'enrollment_required' => true,
                ], 403);
            }

            if (! $lesson->is_published) {
                return response()->json([
                    'message' => 'This lesson is currently unpublished and unavailable.',
                ], 403);
            }

            // Track lesson start and last accessed timestamp for student
            $progress = \App\Models\LessonProgress::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'section_id' => $section->id,
                    'started' => true,
                    'started_at' => now(),
                    'last_accessed_at' => now(),
                    'completed' => false,
                ]
            );

            if (! $progress->wasRecentlyCreated) {
                $progress->update([
                    'started' => true,
                    'started_at' => $progress->started_at ?? now(),
                    'last_accessed_at' => now(),
                ]);
            }
        }

        // Load quiz/assignment/resources data if the lesson is a quiz or assignment type
        $lesson->load(['quiz.questions.options', 'assignment', 'resources']);

        $userProgress = \App\Models\LessonProgress::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        $lessonData = $lesson->toArray();
        $lessonData['is_completed'] = $userProgress ? (bool) $userProgress->completed : false;
        $lessonData['started'] = $userProgress ? (bool) $userProgress->started : false;
        $lessonData['last_accessed_at'] = $userProgress ? $userProgress->last_accessed_at : null;

        return response()->json($lessonData);
    }

    /**
     * Mark a lesson as started / record last access.
     */
    public function start(Request $request, Course $course, Lesson $lesson)
    {
        if ($lesson->course_id !== $course->id) {
            abort(404, 'Lesson not found for this course.');
        }

        $user = $request->user();

        if (! $user->isAdmin() && (int) $course->instructor_id !== (int) $user->id) {
            $enrolled = $course->enrollments()
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            if (! $enrolled) {
                return response()->json([
                    'message' => 'Course Access Required. You must have an active enrollment in this course.',
                    'enrollment_required' => true,
                ], 403);
            }

            if (! $lesson->is_published) {
                return response()->json([
                    'message' => 'This lesson is currently unpublished and unavailable.',
                ], 403);
            }
        }

        $progress = \App\Models\LessonProgress::firstOrCreate(
            [
                'user_id' => $user->id,
                'course_id' => $course->id,
                'lesson_id' => $lesson->id,
            ],
            [
                'section_id' => $lesson->section_id,
                'started' => true,
                'started_at' => now(),
                'last_accessed_at' => now(),
                'completed' => false,
            ]
        );

        if (! $progress->wasRecentlyCreated) {
            $progress->update([
                'started' => true,
                'started_at' => $progress->started_at ?? now(),
                'last_accessed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Lesson recorded as started.',
            'lesson_id' => $lesson->id,
            'started' => true,
            'started_at' => $progress->started_at,
            'last_accessed_at' => $progress->last_accessed_at,
        ]);
    }

    /**
     * Update video playback progress (student heartbeat).
     */
    public function playbackProgress(Request $request, Course $course, Lesson $lesson)
    {
        if ($lesson->course_id !== $course->id) {
            abort(404, 'Lesson not found for this course.');
        }

        $user = $request->user();

        if (! $user->isAdmin() && (int) $course->instructor_id !== (int) $user->id) {
            $enrolled = $course->enrollments()
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            if (! $enrolled) {
                return response()->json([
                    'message' => 'Course Access Required. You must have an active enrollment in this course.',
                    'enrollment_required' => true,
                ], 403);
            }

            if (! $lesson->is_published) {
                return response()->json([
                    'message' => 'This lesson is currently unpublished and unavailable.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'current_time' => 'required|numeric|min:0',
            'duration' => 'required|numeric|min:0',
        ]);

        $currentTime = (float) $validated['current_time'];
        $duration = (float) $validated['duration'];
        $watchedPercent = $duration > 0 ? min(100, round(($currentTime / $duration) * 100, 2)) : 0;

        $completionThreshold = config('lms.video_completion_threshold', 90.0);
        $shouldAutoComplete = ($watchedPercent >= $completionThreshold);

        $progressData = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $course, $lesson, $currentTime, $duration, $watchedPercent, $shouldAutoComplete) {
            $existing = \App\Models\LessonProgress::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('lesson_id', $lesson->id)
                ->first();

            $isAlreadyCompleted = $existing && $existing->completed;
            $newCompleted = $isAlreadyCompleted || $shouldAutoComplete;
            $highestPercent = $existing ? max((float) $existing->progress_percentage, $watchedPercent) : $watchedPercent;

            $progress = \App\Models\LessonProgress::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'section_id' => $lesson->section_id,
                    'status' => $newCompleted ? 'completed' : 'in_progress',
                    'started' => true,
                    'started_at' => $existing?->started_at ?? now(),
                    'completed' => $newCompleted,
                    'completed_at' => $newCompleted ? ($existing?->completed_at ?? now()) : null,
                    'last_accessed_at' => now(),
                    'progress_percentage' => $newCompleted ? 100.0 : $highestPercent,
                    'current_playback_seconds' => $currentTime,
                    'duration_seconds' => $duration,
                    'last_playback_position' => $currentTime,
                ]
            );

            // Audit log on completion threshold reached
            if ($shouldAutoComplete && ! $isAlreadyCompleted) {
                \App\Models\LearningActivityLog::logEvent(
                    $user->id,
                    $course->id,
                    $lesson->id,
                    'lesson_completed',
                    ['trigger' => 'video_playback_threshold', 'watched_percent' => $watchedPercent]
                );
            }

            // Recalculate course overall progress
            $totalPublishedLessons = Lesson::where('course_id', $course->id)
                ->where('is_published', true)
                ->count();

            $completedCount = \App\Models\LessonProgress::where('lesson_progress.user_id', $user->id)
                ->where('lesson_progress.course_id', $course->id)
                ->where('lesson_progress.completed', true)
                ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
                ->where('lessons.is_published', true)
                ->count();

            $courseProgressPercentage = $totalPublishedLessons > 0
                ? round(($completedCount / $totalPublishedLessons) * 100, 2)
                : 0;

            $isCourseCompleted = ($totalPublishedLessons > 0 && $completedCount >= $totalPublishedLessons);

            $enrollment = \App\Models\CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            if ($enrollment && in_array($enrollment->status, ['active', 'completed'], true)) {
                $enrollment->update([
                    'progress_percentage' => $courseProgressPercentage,
                    'status' => $isCourseCompleted ? 'completed' : 'active',
                ]);
            } elseif ($enrollment) {
                // B3: progress sync must never reactivate a pending/
                // cancelled/dropped enrollment without verified payment.
                $enrollment->update([
                    'progress_percentage' => $courseProgressPercentage,
                ]);
            }

            return [
                'lesson_progress' => $progress,
                'course_progress_percentage' => $courseProgressPercentage,
                'completed_count' => $completedCount,
                'total_lessons' => $totalPublishedLessons,
                'is_course_completed' => $isCourseCompleted,
            ];
        });

        return response()->json([
            'message' => 'Playback progress synced.',
            'lesson_id' => $lesson->id,
            'watched_percent' => $watchedPercent,
            'completed' => (bool) $progressData['lesson_progress']->completed,
            'status' => $progressData['lesson_progress']->status,
            'course_progress_percentage' => $progressData['course_progress_percentage'],
            'completed_count' => $progressData['completed_count'],
            'total_lessons' => $progressData['total_lessons'],
            'is_course_completed' => $progressData['is_course_completed'],
        ]);
    }

    /**
     * Mark a lesson as completed (transactional with eligibility validation).
     */
    public function complete(Request $request, Course $course, Lesson $lesson)
    {
        if ($lesson->course_id !== $course->id) {
            abort(404, 'Lesson not found for this course.');
        }

        $user = $request->user();

        if (! $user->isAdmin() && (int) $course->instructor_id !== (int) $user->id) {
            $enrolled = $course->enrollments()
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            if (! $enrolled) {
                return response()->json([
                    'message' => 'Course Access Required. You must be enrolled in this course to mark lessons as completed.',
                    'enrollment_required' => true,
                ], 403);
            }

            if (! $lesson->is_published) {
                return response()->json([
                    'message' => 'This lesson is unpublished and cannot be completed.',
                ], 403);
            }
        }

        $completed = $request->has('completed') ? (bool) $request->boolean('completed') : true;

        // When marking as completed, check lesson type eligibility
        if ($completed && $user->role === 'student') {
            if ($lesson->type === 'quiz') {
                $quiz = \App\Models\Quiz::where('lesson_id', $lesson->id)->first();
                $hasPassedQuiz = false;
                if ($quiz) {
                    $hasPassedQuiz = \App\Models\QuizAttempt::where('user_id', $user->id)
                        ->where('quiz_id', $quiz->id)
                        ->where('passed', true)
                        ->exists();
                }
                if (! $hasPassedQuiz) {
                    return response()->json([
                        'message' => 'You must attempt and pass the quiz with at least ' . ($quiz?->passing_score ?? 70) . '% score to complete this lesson.',
                        'requirement_unmet' => 'quiz_not_passed',
                    ], 422);
                }
            } elseif ($lesson->type === 'assignment') {
                $assignment = \App\Models\Assignment::where('lesson_id', $lesson->id)->first();
                $hasSubmitted = false;
                if ($assignment) {
                    $hasSubmitted = \App\Models\AssignmentSubmission::where('user_id', $user->id)
                        ->where('assignment_id', $assignment->id)
                        ->exists();
                }
                if (! $hasSubmitted) {
                    return response()->json([
                        'message' => 'You must submit the required project assignment to complete this lesson.',
                        'requirement_unmet' => 'assignment_not_submitted',
                    ], 422);
                }
            } elseif ($lesson->type === 'video') {
                $existingProgress = \App\Models\LessonProgress::where('user_id', $user->id)
                    ->where('lesson_id', $lesson->id)
                    ->first();
                $duration = (float) ($existingProgress?->duration_seconds ?? 0);
                $playback = (float) ($existingProgress?->current_playback_seconds ?? 0);
                $percent = $duration > 0 ? round(($playback / $duration) * 100, 1) : 100;

                // If a video lesson has a recorded duration > 30 seconds and student watched < 90%
                if ($duration > 30 && $percent < 90.0 && ! ($existingProgress && $existingProgress->completed)) {
                    return response()->json([
                        'message' => "You must watch at least 90% of the video lesson before marking it as complete. Current progress: {$percent}%.",
                        'requirement_unmet' => 'video_watch_incomplete',
                        'watched_percent' => $percent,
                    ], 422);
                }
            }
        }

        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $course, $lesson, $completed) {
            $progress = \App\Models\LessonProgress::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'section_id' => $lesson->section_id,
                    'status' => $completed ? 'completed' : 'in_progress',
                    'started' => true,
                    'started_at' => now(),
                    'completed' => $completed,
                    'completed_at' => $completed ? now() : null,
                    'last_accessed_at' => now(),
                    'progress_percentage' => $completed ? 100.0 : 0.0,
                ]
            );

            // Audit logging
            \App\Models\LearningActivityLog::logEvent(
                $user->id,
                $course->id,
                $lesson->id,
                $completed ? 'lesson_completed' : 'lesson_marked_incomplete',
                ['type' => $lesson->type]
            );

            // Recalculate total course progress from PUBLISHED lessons only
            $totalPublishedLessons = Lesson::where('course_id', $course->id)
                ->where('is_published', true)
                ->count();

            $completedPublishedCount = \App\Models\LessonProgress::where('lesson_progress.user_id', $user->id)
                ->where('lesson_progress.course_id', $course->id)
                ->where('lesson_progress.completed', true)
                ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
                ->where('lessons.is_published', true)
                ->count();

            $progressPercentage = $totalPublishedLessons > 0
                ? round(($completedPublishedCount / $totalPublishedLessons) * 100, 2)
                : 0;

            $isCourseCompleted = ($totalPublishedLessons > 0 && $completedPublishedCount >= $totalPublishedLessons);

            // Update CourseEnrollment
            $enrollment = \App\Models\CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            if ($enrollment) {
                $enrollment->update([
                    'progress_percentage' => $progressPercentage,
                    'status' => $isCourseCompleted ? 'completed' : 'active',
                ]);
            }

            return [
                'progress' => $progress,
                'progress_percentage' => $progressPercentage,
                'completed_count' => $completedPublishedCount,
                'total_lessons' => $totalPublishedLessons,
                'is_course_completed' => $isCourseCompleted,
            ];
        });

        return response()->json([
            'message' => $completed ? 'Lesson completed successfully.' : 'Lesson marked as incomplete.',
            'lesson_id' => $lesson->id,
            'completed' => $completed,
            'status' => $result['progress']->status,
            'completed_at' => $result['progress']->completed_at,
            'course_id' => $course->id,
            'progress_percentage' => $result['progress_percentage'],
            'completed_count' => $result['completed_count'],
            'total_lessons' => $result['total_lessons'],
            'is_course_completed' => $result['is_course_completed'],
        ]);
    }

    /**
     * Store a new lesson (admin or course instructor).
     */
    public function store(Request $request, Course $course, Section $section)
    {
        $this->authorizeCourseAccess($request, $course);

        if ($section->course_id !== $course->id) {
            abort(404, 'Section not found for this course.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'duration' => 'nullable|string|max:50',
            'type' => 'required|in:video,text,article,document,quiz,assignment,project',
            'metadata' => 'nullable|array',
            'video_url' => 'nullable|string|max:2000',
            'content' => 'nullable|string',
            'document_url' => 'nullable|string|max:2000',
            'document_title' => 'nullable|string|max:255',
            'sort_order' => 'integer|min:0',
            'is_published' => 'boolean',
        ]);

        $metadata = $validated['metadata'] ?? [];
        if ($request->filled('video_url')) {
            $metadata['video_url'] = $request->input('video_url');
        }
        if ($request->filled('content')) {
            $metadata['content'] = $request->input('content');
        }
        if ($request->filled('document_url')) {
            $metadata['document_url'] = $request->input('document_url');
        }
        if ($request->filled('document_title')) {
            $metadata['document_title'] = $request->input('document_title');
        }

        $validated['metadata'] = ! empty($metadata) ? $metadata : null;
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);
        $validated['course_id'] = $course->id;
        $validated['section_id'] = $section->id;
        $validated['is_published'] = $validated['is_published'] ?? true;

        if (! isset($validated['sort_order'])) {
            $maxSort = $section->lessons()->max('sort_order') ?? 0;
            $validated['sort_order'] = $maxSort + 1;
        }

        $lesson = Lesson::create($validated);

        AuditLog::log('created_lesson', $lesson, null, $lesson->toArray());

        return response()->json($lesson->load(['quiz.questions.options', 'assignment', 'resources']), 201);
    }

    /**
     * Update a lesson (admin or course instructor).
     */
    public function update(Request $request, Course $course, Section $section, Lesson $lesson)
    {
        $this->authorizeCourseAccess($request, $course);

        if ($section->course_id !== $course->id) {
            abort(404, 'Section not found for this course.');
        }

        if ($lesson->section_id !== $section->id) {
            abort(404, 'Lesson not found for this section.');
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'duration' => 'sometimes|string|max:50',
            'type' => 'sometimes|in:video,text,article,document,quiz,assignment,project',
            'metadata' => 'sometimes|array',
            'video_url' => 'nullable|string|max:2000',
            'content' => 'nullable|string',
            'document_url' => 'nullable|string|max:2000',
            'document_title' => 'nullable|string|max:255',
            'sort_order' => 'integer|min:0',
            'is_published' => 'boolean',
        ]);

        $metadata = $lesson->metadata ?? [];
        if (isset($validated['metadata']) && is_array($validated['metadata'])) {
            $metadata = array_merge($metadata, $validated['metadata']);
        }
        if ($request->has('video_url')) {
            $metadata['video_url'] = $request->input('video_url');
        }
        if ($request->has('content')) {
            $metadata['content'] = $request->input('content');
        }
        if ($request->has('document_url')) {
            $metadata['document_url'] = $request->input('document_url');
        }
        if ($request->has('document_title')) {
            $metadata['document_title'] = $request->input('document_title');
        }

        $validated['metadata'] = ! empty($metadata) ? $metadata : null;

        $old = $lesson->toArray();

        $lesson->update($validated);

        AuditLog::log('updated_lesson', $lesson, $old, $lesson->fresh()->toArray());

        return response()->json($lesson->fresh()->load(['quiz.questions.options', 'assignment', 'resources']));
    }

    /**
     * Toggle publish state of a lesson.
     */
    public function togglePublish(Request $request, Course $course, Section $section, Lesson $lesson)
    {
        $this->authorizeCourseAccess($request, $course);

        if ($section->course_id !== $course->id) {
            abort(404, 'Section not found for this course.');
        }

        if ($lesson->section_id !== $section->id) {
            abort(404, 'Lesson not found for this section.');
        }

        $wasPublished = (bool) $lesson->is_published;

        $lesson->update([
            'is_published' => ! $lesson->is_published,
        ]);

        AuditLog::log($wasPublished ? 'unpublished_lesson' : 'published_lesson', $lesson, [
            'id' => $lesson->id,
            'course_id' => $course->id,
            'section_id' => $section->id,
            'is_published' => $wasPublished,
        ], [
            'id' => $lesson->id,
            'course_id' => $course->id,
            'section_id' => $section->id,
            'is_published' => ! $wasPublished,
        ]);

        return response()->json([
            'message' => $lesson->is_published ? 'Lesson published successfully.' : 'Lesson unpublished (draft).',
            'lesson' => $lesson->fresh()->load(['quiz.questions.options', 'assignment', 'resources']),
        ]);
    }

    /**
     * Delete a lesson (admin or course instructor) with delete safety.
     */
    public function destroy(Request $request, Course $course, Section $section, Lesson $lesson)
    {
        $this->authorizeCourseAccess($request, $course);

        if ($section->course_id !== $course->id) {
            abort(404, 'Section not found for this course.');
        }

        if ($lesson->section_id !== $section->id) {
            abort(404, 'Lesson not found for this section.');
        }

        // Delete safety check: protect student history
        $hasProgress = \App\Models\LessonProgress::where('lesson_id', $lesson->id)
            ->where(function ($q) {
                $q->where('started', true)->orWhere('completed', true);
            })
            ->exists();

        $hasQuizAttempts = \App\Models\QuizAttempt::where('lesson_id', $lesson->id)->exists();
        $hasSubmissions = \App\Models\AssignmentSubmission::where('lesson_id', $lesson->id)->exists();

        if ($hasProgress || $hasQuizAttempts || $hasSubmissions) {
            return response()->json([
                'message' => 'Cannot delete lesson because student learning progress or submissions exist. Please unpublish it instead to preserve learning history.',
                'delete_blocked' => true,
            ], 422);
        }

        $old = $lesson->toArray();

        $lesson->delete();

        AuditLog::log('deleted_lesson', null, $old, null);

        return response()->json(['message' => 'Lesson deleted successfully.']);
    }

    /**
     * Verify the user is authorized to manage this course.
     */
    private function authorizeCourseAccess(Request $request, Course $course): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if (! $user || ! $user->isAdmin()) {
            if (! $user || (int) $course->instructor_id !== (int) $user->id) {
                abort(403, 'Unauthorized. You can only manage curriculum for your own courses.');
            }
        }
    }
}
