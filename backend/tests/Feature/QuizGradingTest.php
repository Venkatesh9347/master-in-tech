<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizGradingTest extends TestCase
{
    use RefreshDatabase;

    public function test_quiz_auto_grading_and_passing_score_calculation(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course = Course::create([
            'title' => 'Test Course',
            'slug' => 'test-course',
            'description' => 'Test',
            'instructor' => 'Test Instructor',
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Quiz Lesson',
            'type' => 'quiz',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $quiz = Quiz::create([
            'lesson_id' => $lesson->id,
            'title' => 'Fundamentals Quiz',
            'passing_score' => 70,
            'is_published' => true,
        ]);

        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What is 2 + 2?',
            'type' => 'multiple_choice',
            'marks' => 10,
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $o1Correct = QuizOption::create([
            'question_id' => $q1->id,
            'option_text' => '4',
            'is_correct' => true,
            'sort_order' => 0,
        ]);

        $o1Wrong = QuizOption::create([
            'question_id' => $q1->id,
            'option_text' => '5',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $q2 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What is 3 + 3?',
            'type' => 'multiple_choice',
            'marks' => 10,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $o2Correct = QuizOption::create([
            'question_id' => $q2->id,
            'option_text' => '6',
            'is_correct' => true,
            'sort_order' => 0,
        ]);

        // 1. Start Quiz Attempt
        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start");

        $startRes->assertCreated();
        $attemptId = $startRes->json('attempt.id');

        // 2. Submit Answers (100% correct)
        $submitRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/quiz-attempts/{$attemptId}/submit", [
                'answers' => [
                    ['question_id' => $q1->id, 'option_id' => $o1Correct->id],
                    ['question_id' => $q2->id, 'option_id' => $o2Correct->id],
                ],
            ]);

        $submitRes->assertOk();
        $submitRes->assertJsonPath('score', 20);
        $submitRes->assertJsonPath('total_marks', 20);
        $submitRes->assertJsonPath('percentage', 100);
        $submitRes->assertJsonPath('passed', true);
    }

    public function test_quiz_submit_rejects_answers_from_other_quizzes(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course = $this->createCourse('test-course', 'Test Course');
        $this->enroll($student, $course);

        // Quiz A (the quiz the student is legitimately taking)
        $lessonA = $this->createLesson($course, 'quiz', 'Quiz A Lesson');
        $quizA = $this->createQuiz($lessonA, 70);
        $qA1 = $this->createQuestion($quizA, 10);
        $a1Correct = $this->createOption($qA1, true);
        $this->createOption($qA1, false);
        $qA2 = $this->createQuestion($quizA, 10);
        $a2Correct = $this->createOption($qA2, true);

        // Quiz B in a separate course the student is NOT enrolled in
        $courseB = $this->createCourse('other-course', 'Other Course');
        $lessonB = $this->createLesson($courseB, 'quiz', 'Quiz B Lesson');
        $quizB = $this->createQuiz($lessonB, 70);
        $qB = $this->createQuestion($quizB, 50);
        $bCorrect = $this->createOption($qB, true);

        // Attempt 1: own correct answer + a cross-quiz question's correct option
        $attemptId = $this->startAttempt($student, $quizA);
        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/quiz-attempts/{$attemptId}/submit", [
                'answers' => [
                    ['question_id' => $qA1->id, 'option_id' => $a1Correct->id],
                    ['question_id' => $qB->id, 'option_id' => $bCorrect->id],
                ],
            ])->assertOk();

        // Only Quiz A's question is graded; the foreign Quiz B question is ignored
        $res->assertJsonPath('score', 10)
            ->assertJsonPath('total_marks', 10)
            ->assertJsonPath('percentage', 100)
            ->assertJsonPath('passed', true);

        // Attempt 2: correct option from a different question must not credit
        $attempt2Id = $this->startAttempt($student, $quizA);
        $res2 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/quiz-attempts/{$attempt2Id}/submit", [
                'answers' => [
                    ['question_id' => $qA1->id, 'option_id' => $a2Correct->id],
                    ['question_id' => $qA2->id, 'option_id' => $a2Correct->id],
                ],
            ])->assertOk();

        // qA2 with its own correct option = 10; qA1 with the wrong question's
        // option = 0, so total = 10/20.
        $res2->assertJsonPath('score', 10)
            ->assertJsonPath('total_marks', 20)
            ->assertJsonPath('percentage', 50)
            ->assertJsonPath('passed', false);
    }

    public function test_quiz_submit_enforces_time_limit(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse('timed-course', 'Timed Course');
        $this->enroll($student, $course);
        $lesson = $this->createLesson($course, 'quiz', 'Timed Quiz Lesson');
        $quiz = $this->createQuiz($lesson, 70, 1); // 1 minute time limit
        $q = $this->createQuestion($quiz, 10);
        $correct = $this->createOption($q, true);

        $attemptId = $this->startAttempt($student, $quiz);

        // Backdate the attempt so the deadline has already passed
        \App\Models\QuizAttempt::where('id', $attemptId)
            ->update(['started_at' => now()->subMinutes(5)]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/quiz-attempts/{$attemptId}/submit", [
                'answers' => [['question_id' => $q->id, 'option_id' => $correct->id]],
            ])->assertStatus(409);
    }

    public function test_quiz_start_blocks_concurrent_inprogress_attempt(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse('concurrent-course', 'Concurrent Course');
        $this->enroll($student, $course);
        $lesson = $this->createLesson($course, 'quiz', 'Concurrent Quiz Lesson');
        $quiz = $this->createQuiz($lesson, 70, 10);

        $this->startAttempt($student, $quiz);

        // A second start while one attempt is in progress must be blocked
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertStatus(409);

        $this->assertDatabaseCount('quiz_attempts', 1);
    }

    public function test_quiz_max_attempts_uses_inprogress_attempts(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse('maxattempt-course', 'Max Attempt Course');
        $this->enroll($student, $course);
        $lesson = $this->createLesson($course, 'quiz', 'Max Attempt Quiz Lesson');
        $quiz = $this->createQuiz($lesson, 70, 10, 1); // max_attempts = 1
        $q = $this->createQuestion($quiz, 10);
        $correct = $this->createOption($q, true);

        $attemptId = $this->startAttempt($student, $quiz);

        // An in-progress attempt already occupies the single allowed attempt
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertStatus(409);
        $this->assertDatabaseCount('quiz_attempts', 1);

        // After completing the in-progress attempt, starting again is refused
        // because max_attempts (1) has been reached.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/quiz-attempts/{$attemptId}/submit", [
                'answers' => [['question_id' => $q->id, 'option_id' => $correct->id]],
            ])->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertStatus(403);
    }

    private function startAttempt(User $student, Quiz $quiz): int
    {
        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated();

        return (int) $res->json('attempt.id');
    }

    public function test_unpublished_quiz_is_denied_to_enrolled_student(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse('unpub-quiz-course', 'Unpublished Quiz Course');
        $this->enroll($student, $course);
        $lesson = $this->createLesson($course, 'quiz', 'Unpublished Quiz Lesson');
        $quiz = Quiz::create([
            'lesson_id' => $lesson->id,
            'title' => 'Unpublished Quiz',
            'passing_score' => 70,
            'is_published' => false,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/quizzes/{$quiz->id}")
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertStatus(403);
    }

    public function test_dropped_student_is_denied_quiz_access(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse('dropped-quiz-course', 'Dropped Quiz Course');
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'dropped',
        ]);
        $lesson = $this->createLesson($course, 'quiz', 'Dropped Quiz Lesson');
        $quiz = $this->createQuiz($lesson, 70);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertStatus(403);

        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    private function createCourse(string $slug, string $title): Course
    {
        return Course::create([
            'title' => $title,
            'slug' => $slug,
            'description' => 'Test',
            'instructor' => 'Test Instructor',
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);
    }

    private function enroll(User $student, Course $course): void
    {
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    private function createLesson(Course $course, string $type, string $title): Lesson
    {
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        return Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => $title,
            'type' => $type,
            'sort_order' => 0,
            'is_published' => true,
        ]);
    }

    private function createQuiz(Lesson $lesson, int $passingScore, ?int $timeLimit = null, ?int $maxAttempts = null): Quiz
    {
        return Quiz::create(array_filter([
            'lesson_id' => $lesson->id,
            'title' => $lesson->title . ' Quiz',
            'passing_score' => $passingScore,
            'is_published' => true,
            'time_limit' => $timeLimit,
            'max_attempts' => $maxAttempts,
        ]));
    }

    private function createQuestion(Quiz $quiz, int $marks): QuizQuestion
    {
        return QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'Question ' . \Illuminate\Support\Str::random(6),
            'type' => 'multiple_choice',
            'marks' => $marks,
            'sort_order' => 0,
            'is_published' => true,
        ]);
    }

    private function createOption(QuizQuestion $question, bool $isCorrect): QuizOption
    {
        return QuizOption::create([
            'question_id' => $question->id,
            'option_text' => 'Option ' . \Illuminate\Support\Str::random(4),
            'is_correct' => $isCorrect,
            'sort_order' => 0,
        ]);
    }
}
