<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseReview;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TutorController extends Controller
{
    /**
     * Get overview statistics for the logged-in tutor.
     */
    public function stats(Request $request)
    {
        $tutorId = $request->user()->id;
        $isAdmin = $request->user()->isAdmin();

        $courseIdsQuery = Course::query();
        if (! $isAdmin) {
            $courseIdsQuery->where('instructor_id', $tutorId);
        }
        $courseIds = $courseIdsQuery->pluck('id');

        $totalCourses = $courseIds->count();

        $totalStudents = CourseEnrollment::whereIn('course_id', $courseIds)
            ->distinct('user_id')
            ->count('user_id');

        $pendingSubmissions = AssignmentSubmission::whereIn('course_id', $courseIds)
            ->where('status', 'submitted')
            ->count();

        $averageRating = CourseReview::whereIn('course_id', $courseIds)->avg('rating') ?? 5.0;

        return response()->json([
            'total_courses' => $totalCourses,
            'total_students' => $totalStudents,
            'pending_submissions' => $pendingSubmissions,
            'average_rating' => round((float) $averageRating, 1),
        ]);
    }

    /**
     * Get courses taught by this tutor (excluding price).
     */
    public function courses(Request $request)
    {
        $tutorId = $request->user()->id;
        $isAdmin = $request->user()->isAdmin();

        $query = Course::select([
            'id', 'title', 'slug', 'category', 'difficulty', 'duration',
            'thumbnail', 'status', 'is_published', 'instructor_id', 'instructor', 'price', 'created_at',
        ])->withCount(['sections', 'lessons', 'enrollments']);

        if (! $isAdmin) {
            $query->where('instructor_id', $tutorId);
        }

        $courses = $query->orderBy('created_at', 'desc')->get();

        if (! $isAdmin) {
            $courses->makeHidden(['price']);
        }

        return response()->json($courses);
    }

    /**
     * Get a single course owned by this tutor (excluding price for non-admin).
     */
    public function showCourse(Request $request, Course $course)
    {
        $this->authorizeTutorCourse($request, $course);

        $course->loadCount(['sections', 'lessons', 'enrollments']);

        if (! $request->user()->isAdmin()) {
            $course->makeHidden(['price']);
        }

        return response()->json($course);
    }

    /**
     * Create a new course (Only Admin is permitted to create courses).
     */
    public function storeCourse(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. Only administrators can create courses.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'duration' => ['required', 'string', 'max:255'],
            'difficulty' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:draft,published'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        // Strictly enforce instructor ownership
        $validated['instructor_id'] = $request->user()->id;
        $validated['instructor'] = $request->user()->name;
        $validated['slug'] = Str::slug($validated['title']) . '-' . Str::random(4);
        $validated['category'] = $validated['category'] ?? 'Software Engineering';
        $validated['price'] = 0.00; // Internal default; price controlled only by Admin

        // Always strip any malicious client-supplied price
        unset($validated['price_input'], $validated['fee'], $validated['tuition']);

        if (isset($validated['status'])) {
            $validated['is_published'] = ($validated['status'] === 'published');
        } else {
            $validated['is_published'] = $validated['is_published'] ?? true;
            $validated['status'] = $validated['is_published'] ? 'published' : 'draft';
        }

        $course = Course::create($validated);
        $course->makeHidden(['price']);

        AuditLog::log('created_course', $course, null, $course->toArray());

        return response()->json($course->fresh()->loadCount(['sections', 'lessons', 'enrollments']), 201);
    }

    /**
     * Update a course (Courses are managed solely by Administration).
     */
    public function updateCourse(Request $request, Course $course)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. Tutors cannot modify courses. Courses are managed by administration.',
            ], 403);
        }

        $this->authorizeTutorCourse($request, $course);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'category' => ['sometimes', 'string', 'max:255'],
            'duration' => ['sometimes', 'string', 'max:255'],
            'difficulty' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:draft,published'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['status'])) {
            $validated['is_published'] = ($validated['status'] === 'published');
        } elseif (isset($validated['is_published'])) {
            $validated['status'] = $validated['is_published'] ? 'published' : 'draft';
        }

        // Never allow altering instructor_id or price by tutor
        unset($validated['instructor_id'], $validated['instructor'], $validated['price'], $validated['fee']);

        $old = $course->toArray();

        $course->update($validated);

        $fresh = $course->fresh()->loadCount(['sections', 'lessons', 'enrollments']);

        AuditLog::log('updated_course', $course, $old, $fresh->toArray());

        return response()->json($fresh);
    }

    /**
     * Delete a course (Only Admin is permitted to delete courses).
     */
    public function destroyCourse(Request $request, Course $course)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. Tutors cannot delete courses. Courses are managed by administration.',
            ], 403);
        }

        $this->authorizeTutorCourse($request, $course);

        $old = $course->toArray();

        $course->delete();

        AuditLog::log('deleted_course', null, $old, null);

        return response()->json([
            'message' => 'Course deleted successfully.',
        ]);
    }

    /**
     * Get detailed analytics for a specific course.
     */
    public function courseAnalytics(Request $request, Course $course)
    {
        $this->authorizeTutorCourse($request, $course);

        $enrollments = CourseEnrollment::where('course_id', $course->id)->get();
        $totalEnrolled = $enrollments->count();
        $completedCount = $enrollments->filter(fn ($e) => (float) $e->progress_percentage >= 100 || $e->status === 'completed')->count();
        $inProgressCount = $totalEnrolled - $completedCount;
        $avgProgress = $totalEnrolled > 0 ? round($enrollments->avg('progress_percentage'), 1) : 0;

        $reviews = CourseReview::where('course_id', $course->id)->with('user:id,name')->get();
        $avgRating = $reviews->count() > 0 ? round($reviews->avg('rating'), 1) : 5.0;

        $submissionsCount = AssignmentSubmission::where('course_id', $course->id)->count();
        $gradedCount = AssignmentSubmission::where('course_id', $course->id)->where('status', 'graded')->count();

        $quizAttempts = QuizAttempt::where('course_id', $course->id)->get();
        $avgQuizScore = $quizAttempts->count() > 0 ? round($quizAttempts->avg('score'), 1) : 0;

        return response()->json([
            'course' => $course->loadCount(['sections', 'lessons']),
            'total_enrolled' => $totalEnrolled,
            'in_progress' => $inProgressCount,
            'completed' => $completedCount,
            'average_progress' => $avgProgress,
            'average_rating' => $avgRating,
            'reviews_count' => $reviews->count(),
            'reviews' => $reviews,
            'submissions_count' => $submissionsCount,
            'graded_submissions_count' => $gradedCount,
            'quiz_attempts_count' => $quizAttempts->count(),
            'average_quiz_score' => $avgQuizScore,
        ]);
    }

    /**
     * Get students enrolled in a specific course taught by this tutor.
     */
    public function courseStudents(Request $request, Course $course)
    {
        $this->authorizeTutorCourse($request, $course);

        $enrollments = CourseEnrollment::where('course_id', $course->id)
            ->with(['user:id,name,email'])
            ->orderBy('enrolled_at', 'desc')
            ->get();

        return response()->json($enrollments);
    }

    /**
     * Get all students enrolled across courses taught by this tutor.
     */
    public function students(Request $request)
    {
        $tutorId = $request->user()->id;
        $isAdmin = $request->user()->isAdmin();

        $courseIdsQuery = Course::query();
        if (! $isAdmin) {
            $courseIdsQuery->where('instructor_id', $tutorId);
        }
        $courseIds = $courseIdsQuery->pluck('id');

        $enrollments = CourseEnrollment::whereIn('course_id', $courseIds)
            ->select(['id', 'user_id', 'course_id', 'status', 'progress_percentage', 'enrolled_at'])
            ->with(['user:id,name,email,avatar', 'course:id,title,thumbnail'])
            ->orderBy('enrolled_at', 'desc')
            ->get();

        return response()->json($enrollments);
    }

    /**
     * Get assignment submissions for this tutor's courses.
     */
    public function submissions(Request $request)
    {
        $tutorId = $request->user()->id;
        $isAdmin = $request->user()->isAdmin();

        $courseIdsQuery = Course::query();
        if (! $isAdmin) {
            $courseIdsQuery->where('instructor_id', $tutorId);
        }
        $courseIds = $courseIdsQuery->pluck('id');

        $query = AssignmentSubmission::whereIn('course_id', $courseIds)
            ->with([
                'user:id,name,email',
                'assignment:id,title,max_marks',
                'course:id,title',
            ])
            ->orderBy('updated_at', 'desc');

        if ($request->has('status') && in_array($request->status, ['submitted', 'graded', 'returned'], true)) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    /**
     * Grade an assignment submission (tutor only for their course).
     */
    public function gradeSubmission(Request $request, $id)
    {
        $submission = AssignmentSubmission::with(['assignment', 'course', 'lesson'])->find($id);

        if (! $submission) {
            return response()->json(['message' => 'Submission not found'], 404);
        }

        if (! $request->user()->isAdmin() && (int) $submission->course?->instructor_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized. You can only grade submissions for your own courses.',
            ], 403);
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
                    'graded_by' => $request->user()->id,
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
                $totalPublishedLessons = Lesson::where('course_id', $submission->course_id)
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

                $enrollment = CourseEnrollment::where('user_id', $submission->user_id)
                    ->where('course_id', $submission->course_id)
                    ->first();

                if ($enrollment && in_array($enrollment->status, ['active', 'completed'], true)) {
                    // B3: grading progress must never reactivate a pending/
                    // cancelled/dropped enrollment without verified payment.
                    $enrollment->update([
                        'progress_percentage' => $courseProgressPercentage,
                        'status' => $isCourseCompleted ? 'completed' : 'active',
                    ]);
                } elseif ($enrollment) {
                    $enrollment->update([
                        'progress_percentage' => $courseProgressPercentage,
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Assignment submission graded successfully.',
            'submission' => $submission->fresh()->load(['user:id,name,email', 'assignment', 'course:id,title']),
        ]);
    }

    /**
     * Create or update a quiz on a lesson.
     */
    public function saveQuiz(Request $request, Course $course, Lesson $lesson)
    {
        $this->authorizeTutorCourse($request, $course);

        if (! $request->user()->hasPermission('create_quizzes') && ! $request->user()->hasPermission('edit_quizzes')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to create or edit quizzes.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'time_limit' => 'nullable|integer|min:1',
            'passing_score' => 'required|numeric|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:1',
            'questions' => 'required|array|min:1',
            'questions.*.question' => 'required|string',
            'questions.*.type' => 'nullable|string',
            'questions.*.marks' => 'required|numeric|min:1',
            'questions.*.options' => 'required|array|min:2',
            'questions.*.options.*.option_text' => 'required|string',
            'questions.*.options.*.is_correct' => 'required|boolean',
        ]);

        // M9: if a quiz already exists for this lesson and students have attempted
        // it, the delete-and-rebuild of questions/options would invalidate grading.
        // Block structural and attempt-affecting changes; allow metadata only.
        $existingQuiz = Quiz::where('lesson_id', $lesson->id)->first();
        if ($existingQuiz && $existingQuiz->hasAttempts()) {
            return response()->json([
                'message' => 'This quiz already has student attempts, so its questions, options and grading settings cannot be changed. You may still edit the title, description or time limit.',
            ], 422);
        }

        return DB::transaction(function () use ($course, $lesson, $validated) {
            $quiz = Quiz::updateOrCreate(
                ['lesson_id' => $lesson->id],
                [
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'time_limit' => $validated['time_limit'] ?? 15,
                    'passing_score' => $validated['passing_score'],
                    'max_attempts' => $validated['max_attempts'] ?? 3,
                ]
            );

            // Delete old questions/options and rebuild
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

            // Update lesson type to quiz
            $lesson->update(['type' => 'quiz']);

            AuditLog::log('saved_quiz', $quiz, null, [
                'id' => $quiz->id,
                'lesson_id' => $lesson->id,
                'course_id' => $course->id,
                'title' => $quiz->title,
                'question_count' => count($validated['questions']),
            ]);

            return response()->json([
                'message' => 'Quiz saved successfully.',
                'quiz' => $quiz->fresh()->load('questions.options'),
            ]);
        });
    }

    /**
     * Create or update an assignment on a lesson.
     */
    public function saveAssignment(Request $request, Course $course, Lesson $lesson)
    {
        $this->authorizeTutorCourse($request, $course);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'instructions' => 'required|string',
            'due_date' => 'nullable|date',
            'max_marks' => 'required|numeric|min:1',
            'file_url' => 'nullable|string',
        ]);

        $assignment = Assignment::updateOrCreate(
            ['lesson_id' => $lesson->id],
            [
                'course_id' => $course->id,
                'title' => $validated['title'],
                'instructions' => $validated['instructions'],
                'due_date' => $validated['due_date'] ?? null,
                'max_marks' => $validated['max_marks'],
                'file_url' => $validated['file_url'] ?? null,
            ]
        );

        // Update lesson type to assignment
        $lesson->update(['type' => 'assignment']);

        AuditLog::log('saved_assignment', $assignment, null, [
            'id' => $assignment->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'title' => $assignment->title,
            'max_marks' => $assignment->max_marks,
        ]);

        return response()->json([
            'message' => 'Assignment saved successfully.',
            'assignment' => $assignment,
        ]);
    }

    /**
     * Get tutor's profile details.
     */
    public function profile(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Update tutor profile.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'headline' => 'nullable|string|max:255',
            'expertise' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:2000',
            'password' => 'nullable|string|min:6',
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Tutor profile updated successfully.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Authorize that the tutor owns the course or is admin.
     */
    private function authorizeTutorCourse(Request $request, Course $course): void
    {
        if (! $request->user()->isAdmin() && (int) $course->instructor_id !== (int) $request->user()->id) {
            abort(403, 'Unauthorized. You can only access your own courses.');
        }
    }
}
