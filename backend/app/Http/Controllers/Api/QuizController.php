<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizAnswer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuizController extends Controller
{
    /**
     * Get quiz with questions and options (student view, enrolled only).
     */
    public function show(Request $request, Quiz $quiz)
    {
        $user = $request->user();

        // Load quiz with questions and options
        $quiz = $quiz->load(['questions.options']);

        // Check enrollment
        $enrollment = \App\Models\CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $quiz->lesson->course_id)
            ->whereNotIn('status', ['dropped', 'expired'])
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'You must enroll in the course to access this quiz.',
                'enrollment_required' => true,
            ], 403);
        }

        // HIGH-2: only published quizzes are accessible to students.
        if (! $quiz->is_published) {
            return response()->json([
                'message' => 'This quiz is currently unpublished and unavailable.',
                'unpublished' => true,
            ], 403);
        }

        // Get previous attempts
        $attempts = QuizAttempt::where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->orderBy('attempt_number', 'desc')
            ->get();

        // Check if user can start a new attempt
        $canStart = true;
        if ($quiz->max_attempts && $attempts->count() >= $quiz->max_attempts) {
            $canStart = false;
        }

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'time_limit' => $quiz->time_limit,
                'passing_score' => $quiz->passing_score,
                'max_attempts' => $quiz->max_attempts,
                'randomize_questions' => $quiz->randomize_questions,
            ],
            'questions' => $quiz->questions->map(function ($q) {
                return [
                    'id' => $q->id,
                    'question' => $q->question,
                    'type' => $q->type,
                    'marks' => $q->marks,
                    'options' => $q->options->map(function ($o) {
                        return [
                            'id' => $o->id,
                            'option_text' => $o->option_text,
                        ];
                    }),
                ];
            }),
            'can_start' => $canStart,
            'attempts' => $attempts->map(fn ($a) => [
                'id' => $a->id,
                'attempt_number' => $a->attempt_number,
                'score' => $a->score,
                'total_marks' => $a->total_marks,
                'passing_score' => $a->passing_score,
                'passed' => $a->passed,
                'status' => $a->status,
                'started_at' => $a->started_at,
                'completed_at' => $a->completed_at,
            ]),
        ]);
    }

    /**
     * Start a new quiz attempt (student).
     */
    public function start(Request $request, Quiz $quiz)
    {
        $user = $request->user();

        // Check enrollment
        $enrollment = \App\Models\CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $quiz->lesson->course_id)
            ->whereNotIn('status', ['dropped', 'expired'])
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'You must enroll in the course to access this quiz.',
                'enrollment_required' => true,
            ], 403);
        }

        // HIGH-2: only published quizzes can be started.
        if (! $quiz->is_published) {
            return response()->json([
                'message' => 'This quiz is currently unpublished and unavailable.',
                'unpublished' => true,
            ], 403);
        }

        // CRITICAL-4: make the attempt-limit and in-progress guards atomic and
        // race-safe. lockForUpdate() is portable (relational row-lock on
        // MySQL/Postgres; a read-consistency no-op on SQLite).
        return DB::transaction(function () use ($quiz, $user) {
            $attempts = QuizAttempt::where('user_id', $user->id)
                ->where('quiz_id', $quiz->id)
                ->lockForUpdate()
                ->get();

            // Block concurrent/duplicate in-progress attempts.
            $inProgress = $attempts->firstWhere('status', 'started');
            if ($inProgress) {
                return response()->json([
                    'message' => 'You already have an in-progress attempt. Submit it before starting a new one.',
                    'attempt_id' => $inProgress->id,
                ], 409);
            }

            // Check max attempts against completed attempts only.
            $attemptCount = $attempts->where('status', 'completed')->count();

            if ($quiz->max_attempts && $attemptCount >= $quiz->max_attempts) {
                return response()->json([
                    'message' => 'Maximum attempts reached for this quiz.',
                ], 403);
            }

            $nextAttempt = $attemptCount + 1;

            $attempt = QuizAttempt::create([
                'user_id' => $user->id,
                'quiz_id' => $quiz->id,
                'lesson_id' => $quiz->lesson_id,
                'course_id' => $quiz->lesson->course_id,
                'section_id' => $quiz->lesson->section_id,
                'started_at' => now(),
                'score' => 0,
                'total_marks' => $this->calculateTotalMarks($quiz),
                'passing_score' => $quiz->passing_score,
                'passed' => false,
                'status' => 'started',
                'attempt_number' => $nextAttempt,
            ]);

            return response()->json([
                'attempt' => $attempt,
                'attempt_id' => $attempt->id,
                'time_limit' => $quiz->time_limit,
                'questions' => $this->formatQuestions($quiz),
            ], 201);
        });
    }

    /**
     * Submit a quiz attempt (student).
     */
    public function submit(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        $quiz = $attempt->quiz;

        if (! $quiz) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        // Verify the attempt belongs to this user
        if ($attempt->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($attempt->status === 'completed') {
            return response()->json(['message' => 'This attempt has already been submitted.'], 409);
        }

        // CRITICAL-2: enforce the server-side time limit for timed quizzes
        if ($quiz->time_limit && $attempt->started_at) {
            $deadline = $attempt->started_at->copy()->addMinutes($quiz->time_limit);
            if (now()->greaterThan($deadline)) {
                return response()->json([
                    'message' => 'Time limit exceeded for this quiz attempt.',
                ], 409);
            }
        }

        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:quiz_questions,id',
            'answers.*.option_id' => 'nullable|exists:quiz_options,id',
            'answers.*.answer_text' => 'nullable|string',
        ]);

        DB::transaction(function () use ($attempt, $quiz, $validated, $user) {
            $score = 0;
            $totalMarks = 0;

            foreach ($validated['answers'] as $answer) {
                // CRITICAL-1: only grade questions that actually belong to this quiz
                $question = QuizQuestion::where('id', $answer['question_id'])
                    ->where('quiz_id', $attempt->quiz_id)
                    ->first();

                if (! $question) {
                    continue;
                }

                $totalMarks += $question->marks;

                $isCorrect = false;
                $marksAwarded = 0;

                if (isset($answer['option_id'])) {
                    // CRITICAL-1: the option must belong to the submitted question
                    $option = \App\Models\QuizOption::where('id', $answer['option_id'])
                        ->where('question_id', $question->id)
                        ->first();

                    if ($option && $option->is_correct) {
                        $isCorrect = true;
                        $marksAwarded = $question->marks;
                        $score += $question->marks;
                    }
                }

                QuizAnswer::create([
                    'quiz_attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'option_id' => $answer['option_id'] ?? null,
                    'answer_text' => $answer['answer_text'] ?? null,
                    'is_correct' => $isCorrect,
                    'marks_awarded' => $marksAwarded,
                ]);
            }

            $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0;
            $passed = $percentage >= $quiz->passing_score;

            $attempt->update([
                'score' => $score,
                'total_marks' => $totalMarks,
                'completed_at' => now(),
                'passed' => $passed,
                'status' => 'completed',
            ]);

            // Audit log quiz submission
            \App\Models\LearningActivityLog::logEvent(
                $user->id,
                $attempt->course_id,
                $attempt->lesson_id,
                'quiz_submitted',
                [
                    'quiz_id' => $quiz->id,
                    'attempt_number' => $attempt->attempt_number,
                    'score' => $score,
                    'total_marks' => $totalMarks,
                    'percentage' => $percentage,
                    'passed' => $passed,
                ]
            );

            // If passed, grant lesson completion and recalculate course progress
            if ($passed) {
                \App\Models\LessonProgress::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'course_id' => $attempt->course_id,
                        'lesson_id' => $attempt->lesson_id,
                    ],
                    [
                        'section_id' => $attempt->section_id,
                        'status' => 'completed',
                        'started' => true,
                        'completed' => true,
                        'completed_at' => now(),
                        'last_accessed_at' => now(),
                        'progress_percentage' => 100.0,
                    ]
                );

                // Recalculate course overall progress
                $totalPublishedLessons = \App\Models\Lesson::where('course_id', $attempt->course_id)
                    ->where('is_published', true)
                    ->count();

                $completedPublishedCount = \App\Models\LessonProgress::where('lesson_progress.user_id', $user->id)
                    ->where('lesson_progress.course_id', $attempt->course_id)
                    ->where('lesson_progress.completed', true)
                    ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
                    ->where('lessons.is_published', true)
                    ->count();

                $courseProgressPercentage = $totalPublishedLessons > 0
                    ? round(($completedPublishedCount / $totalPublishedLessons) * 100, 2)
                    : 0;

                $isCourseCompleted = ($totalPublishedLessons > 0 && $completedPublishedCount >= $totalPublishedLessons);

                $enrollment = \App\Models\CourseEnrollment::where('user_id', $user->id)
                    ->where('course_id', $attempt->course_id)
                    ->first();

                if ($enrollment) {
                    $enrollment->update([
                        'progress_percentage' => $courseProgressPercentage,
                        'status' => $isCourseCompleted ? 'completed' : 'active',
                    ]);
                }
            }
        });

        // Reload with answers
        $attempt = $attempt->load(['answers']);

        return response()->json([
            'attempt_id' => $attempt->id,
            'score' => (float) $attempt->score,
            'total_marks' => (float) $attempt->total_marks,
            'percentage' => $attempt->total_marks > 0 ? (float) round(($attempt->score / $attempt->total_marks) * 100, 2) : 0.0,
            'passing_score' => (float) $quiz->passing_score,
            'passed' => (bool) $attempt->passed,
        ]);
    }

    /**
     * Calculate total marks for a quiz.
     */
    private function calculateTotalMarks(Quiz $quiz): int
    {
        return QuizQuestion::where('quiz_id', $quiz->id)->sum('marks');
    }

    /**
     * Format quiz questions for the frontend.
     */
    private function formatQuestions(Quiz $quiz): array
    {
        return $quiz->questions
            ->map(fn ($q) => [
                'id' => $q->id,
                'question' => $q->question,
                'type' => $q->type,
                'marks' => $q->marks,
                'options' => $q->options->map(fn ($o) => [
                    'id' => $o->id,
                    'option_text' => $o->option_text,
                ]),
            ])
            ->toArray();
    }
}
