<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TutorQuizController extends Controller
{
    /**
     * List all quizzes for the tutor's assigned courses.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        $query = Quiz::select([
            'id', 'lesson_id', 'title', 'description', 'time_limit', 'passing_score', 'max_attempts', 'is_published', 'created_at', 'updated_at',
        ])->with([
            'lesson:id,course_id,title',
            'lesson.course:id,title,instructor_id',
        ])->withCount(['questions', 'attempts']);

        if (! $isAdmin) {
            $assignedCourseIds = Course::where('instructor_id', $user->id)->pluck('id');
            $query->whereHas('lesson', fn ($l) => $l->whereIn('course_id', $assignedCourseIds));
        }

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->whereHas('lesson', fn ($l) => $l->where('course_id', $request->course_id));
        }

        $quizzes = $query->orderBy('created_at', 'desc')->get();

        return response()->json($quizzes);
    }

    /**
     * Create a new quiz for an assigned course.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        if (! $isAdmin && ! $user->hasPermission('create_quizzes')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to create quizzes.',
            ], 403);
        }

        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'lesson_id' => 'nullable|integer|exists:lessons,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'time_limit' => 'nullable|integer|min:1',
            'passing_score' => 'required|numeric|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:1',
            'is_published' => 'nullable|boolean',
            'questions' => 'nullable|array',
            'questions.*.question' => 'required_with:questions|string',
            'questions.*.type' => 'nullable|string',
            'questions.*.marks' => 'required_with:questions|numeric|min:1',
            'questions.*.options' => 'required_with:questions|array|min:2',
            'questions.*.options.*.option_text' => 'required_with:questions|string',
            'questions.*.options.*.is_correct' => 'required_with:questions|boolean',
        ]);

        $course = Course::findOrFail($validated['course_id']);

        if (! $isAdmin && $course->instructor_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. You cannot create quizzes for a course not assigned to you.',
            ], 403);
        }

        // Find or create placeholder lesson for the quiz if not provided
        $lessonId = $validated['lesson_id'] ?? null;
        if (! $lessonId) {
            $section = Section::firstOrCreate(
                ['course_id' => $course->id, 'title' => 'Module Assessments'],
                ['sort_order' => 99]
            );

            $lesson = Lesson::create([
                'course_id' => $course->id,
                'section_id' => $section->id,
                'title' => $validated['title'] . ' (Assessment)',
                'slug' => \Illuminate\Support\Str::slug($validated['title']) . '-' . \Illuminate\Support\Str::random(4),
                'type' => 'quiz',
                'sort_order' => 1,
                'is_published' => true,
            ]);
            $lessonId = $lesson->id;
        }

        return DB::transaction(function () use ($validated, $lessonId, $course) {
            $quiz = Quiz::create([
                'lesson_id' => $lessonId,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'time_limit' => $validated['time_limit'] ?? 15,
                'passing_score' => $validated['passing_score'],
                'max_attempts' => $validated['max_attempts'] ?? 3,
                'is_published' => $validated['is_published'] ?? true,
            ]);

            if (! empty($validated['questions'])) {
                foreach ($validated['questions'] as $qData) {
                    $question = QuizQuestion::create([
                        'quiz_id' => $quiz->id,
                        'question' => $qData['question'],
                        'type' => $qData['type'] ?? 'multiple_choice',
                        'marks' => $qData['marks'],
                    ]);

                    foreach ($qData['options'] as $opt) {
                        QuizOption::create([
                            'question_id' => $question->id,
                            'option_text' => $opt['option_text'],
                            'is_correct' => (bool) $opt['is_correct'],
                        ]);
                    }
                }
            }

            // Audit Log
            AuditLog::log('created_quiz', $quiz, null, [
                'course_id' => $course->id,
                'quiz_id' => $quiz->id,
                'title' => $quiz->title,
            ]);

            return response()->json([
                'message' => 'Quiz created successfully.',
                'quiz' => $quiz->fresh()->load('questions.options', 'lesson.course:id,title'),
            ], 201);
        });
    }

    /**
     * Show quiz details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        $quiz = Quiz::with(['lesson.course:id,title,instructor_id', 'questions.options'])->findOrFail($id);

        if (! $isAdmin && $quiz->lesson?->course?->instructor_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. This quiz belongs to a course not assigned to you.',
            ], 403);
        }

        return response()->json($quiz);
    }

    /**
     * Update quiz details and questions.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        if (! $isAdmin && ! $user->hasPermission('edit_quizzes')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to edit quizzes.',
            ], 403);
        }

        $quiz = Quiz::with('lesson.course')->findOrFail($id);

        if (! $isAdmin && $quiz->lesson?->course?->instructor_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. This quiz belongs to an unassigned course.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'time_limit' => 'nullable|integer|min:1',
            'passing_score' => 'sometimes|required|numeric|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:1',
            'is_published' => 'nullable|boolean',
            'questions' => 'nullable|array',
            'questions.*.question' => 'required_with:questions|string',
            'questions.*.type' => 'nullable|string',
            'questions.*.marks' => 'required_with:questions|numeric|min:1',
            'questions.*.options' => 'required_with:questions|array|min:2',
            'questions.*.options.*.option_text' => 'required_with:questions|string',
            'questions.*.options.*.is_correct' => 'required_with:questions|boolean',
        ]);

        // M9: once a quiz has student attempts, destructive authoring changes are
        // blocked so existing attempts and their grading are not invalidated.
        // Structural rebuild (questions/options/correct answers) and attempt-affecting
        // settings (passing_score, max_attempts) are rejected; harmless metadata
        // (title, description, is_published, time_limit) remains editable.
        if ($quiz->hasAttempts()) {
            $blocked = $this->rejectedPostAttemptMutations($validated);
            if ($blocked) {
                return response()->json([
                    'message' => $blocked.'. This quiz already has student attempts, so its structure and grading settings cannot be changed. You may still edit the title, description, publish status or time limit.',
                ], 422);
            }
        }

        return DB::transaction(function () use ($quiz, $validated) {
            $oldValues = $quiz->toArray();

            $quiz->update([
                'title' => $validated['title'] ?? $quiz->title,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $quiz->description,
                'time_limit' => array_key_exists('time_limit', $validated) ? $validated['time_limit'] : $quiz->time_limit,
                'passing_score' => $validated['passing_score'] ?? $quiz->passing_score,
                'max_attempts' => array_key_exists('max_attempts', $validated) ? $validated['max_attempts'] : $quiz->max_attempts,
                'is_published' => array_key_exists('is_published', $validated) ? (bool) $validated['is_published'] : $quiz->is_published,
            ]);

            if (isset($validated['questions'])) {
                $oldQuestionIds = QuizQuestion::where('quiz_id', $quiz->id)->pluck('id');
                QuizOption::whereIn('question_id', $oldQuestionIds)->delete();
                QuizQuestion::where('quiz_id', $quiz->id)->delete();

                foreach ($validated['questions'] as $qData) {
                    $question = QuizQuestion::create([
                        'quiz_id' => $quiz->id,
                        'question' => $qData['question'],
                        'type' => $qData['type'] ?? 'multiple_choice',
                        'marks' => $qData['marks'],
                    ]);

                    foreach ($qData['options'] as $opt) {
                        QuizOption::create([
                            'question_id' => $question->id,
                            'option_text' => $opt['option_text'],
                            'is_correct' => (bool) $opt['is_correct'],
                        ]);
                    }
                }
            }

            // Audit Log
            AuditLog::log('updated_quiz', $quiz, $oldValues, $quiz->toArray());

            return response()->json([
                'message' => 'Quiz updated successfully.',
                'quiz' => $quiz->fresh()->load('questions.options', 'lesson.course:id,title'),
            ]);
        });
    }

    /**
     * Delete a quiz.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        if (! $isAdmin && ! $user->hasPermission('delete_quizzes')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to delete quizzes.',
            ], 403);
        }

        $quiz = Quiz::with('lesson.course')->findOrFail($id);

        if (! $isAdmin && $quiz->lesson?->course?->instructor_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. This quiz belongs to an unassigned course.',
            ], 403);
        }

        // M9: do not delete a quiz that has student attempts (this would cascade-
        // delete attempts and answers, destroying grading evidence).
        if ($quiz->hasAttempts()) {
            return response()->json([
                'message' => 'This quiz has student attempts and cannot be deleted.',
            ], 422);
        }

        // Audit Log
        AuditLog::log('deleted_quiz', $quiz, $quiz->toArray(), null);

        $quiz->delete();

        return response()->json([
            'message' => 'Quiz deleted successfully.',
        ]);
    }

    /**
     * Toggle quiz publish status.
     */
    public function togglePublish(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        if (! $isAdmin && ! $user->hasPermission('publish_quizzes')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to publish or unpublish quizzes.',
            ], 403);
        }

        $quiz = Quiz::with('lesson.course')->findOrFail($id);

        if (! $isAdmin && $quiz->lesson?->course?->instructor_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. This quiz belongs to an unassigned course.',
            ], 403);
        }

        $quiz->is_published = ! $quiz->is_published;
        $quiz->save();

        // Audit Log
        AuditLog::log($quiz->is_published ? 'published_quiz' : 'unpublished_quiz', $quiz, null, ['is_published' => $quiz->is_published]);

        return response()->json([
            'message' => $quiz->is_published ? 'Quiz published successfully.' : 'Quiz unpublished successfully.',
            'quiz' => $quiz,
        ]);
    }

    /**
     * View student attempts / results for a quiz.
     */
    public function results(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        if (! $isAdmin && ! $user->hasPermission('view_quiz_results')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to view quiz results.',
            ], 403);
        }

        $quiz = Quiz::with('lesson.course')->findOrFail($id);

        if (! $isAdmin && $quiz->lesson?->course?->instructor_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. This quiz belongs to an unassigned course.',
            ], 403);
        }

        $attempts = QuizAttempt::where('quiz_id', $quiz->id)
            ->with(['user:id,name,email,avatar'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'quiz' => $quiz,
            'attempts' => $attempts,
        ]);
    }

    /**
     * Determine which post-attempt authoring mutation the given payload attempts,
     * returning a human-readable reason or null when no destructive change is made.
     */
    private function rejectedPostAttemptMutations(array $validated): ?string
    {
        if (isset($validated['questions'])) {
            return 'Quiz questions and options cannot be changed after attempts exist';
        }

        if (array_key_exists('passing_score', $validated)) {
            return 'passing_score cannot be changed after attempts exist';
        }

        if (array_key_exists('max_attempts', $validated)) {
            return 'max_attempts cannot be changed after attempts exist';
        }

        return null;
    }
}
