<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchB2QuizAuthoringProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;
    private Course $course;
    private Lesson $lesson;
    private Quiz $quiz;
    private QuizQuestion $q1;
    private QuizOption $correct;
    private QuizOption $wrong;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->create([
            'role' => 'tutor',
            'status' => 'active',
            'permissions' => [
                'create_quizzes' => true,
                'edit_quizzes' => true,
                'delete_quizzes' => true,
                'publish_quizzes' => true,
                'view_quiz_results' => true,
            ],
        ]);

        $this->course = Course::create([
            'title' => 'B2 Quiz Course',
            'slug' => 'b2-quiz-course-' . uniqid(),
            'description' => 'desc',
            'instructor' => $this->tutor->name,
            'instructor_id' => $this->tutor->id,
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        $section = Section::create(['course_id' => $this->course->id, 'title' => 'S', 'sort_order' => 0, 'is_published' => true]);
        $this->lesson = Lesson::create(['course_id' => $this->course->id, 'section_id' => $section->id, 'title' => 'L', 'type' => 'quiz', 'sort_order' => 0, 'is_published' => true]);

        $this->quiz = Quiz::create([
            'lesson_id' => $this->lesson->id,
            'title' => 'B2 Quiz',
            'passing_score' => 70,
            'max_attempts' => 3,
            'time_limit' => 15,
            'is_published' => true,
        ]);

        $this->q1 = QuizQuestion::create(['quiz_id' => $this->quiz->id, 'question' => 'Q1', 'type' => 'multiple_choice', 'marks' => 10, 'sort_order' => 0, 'is_published' => true]);
        $this->correct = QuizOption::create(['question_id' => $this->q1->id, 'option_text' => 'A', 'is_correct' => true, 'sort_order' => 0]);
        $this->wrong = QuizOption::create(['question_id' => $this->q1->id, 'option_text' => 'B', 'is_correct' => false, 'sort_order' => 1]);
    }

    private function attemptsPayload(array $overrides = []): array
    {
        return array_merge([
            'questions' => [
                [
                    'question' => 'Q1 changed', // would delete + rebuild (incl. correct answer)
                    'type' => 'multiple_choice',
                    'marks' => 10,
                    'options' => [
                        ['option_text' => 'X', 'is_correct' => true],
                        ['option_text' => 'Y', 'is_correct' => false],
                    ],
                ],
            ],
        ], $overrides);
    }

    private function createAttempt(): QuizAttempt
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        return QuizAttempt::create([
            'user_id' => $student->id,
            'quiz_id' => $this->quiz->id,
            'lesson_id' => $this->lesson->id,
            'course_id' => $this->course->id,
            'started_at' => now(),
            'status' => 'completed',
            'attempt_number' => 1,
            'score' => 0,
            'total_marks' => 10,
            'passed' => false,
        ]);
    }

    public function test_author_can_create_and_edit_quiz_before_attempts(): void
    {
        // Create via the authoring endpoint before any attempts exist.
        $res = $this->actingAs($this->tutor, 'sanctum')->postJson('/api/tutor/quizzes', [
            'course_id' => $this->course->id,
            'title' => 'Brand New Quiz',
            'passing_score' => 80,
            'questions' => [
                ['question' => 'A?', 'type' => 'multiple_choice', 'marks' => 5, 'options' => [
                    ['option_text' => '1', 'is_correct' => true],
                    ['option_text' => '2', 'is_correct' => false],
                ]],
            ],
        ]);
        $res->assertStatus(201);

        $newId = $res->json('quiz.id');

        // Structural edit is allowed before attempts exist.
        $update = $this->actingAs($this->tutor, 'sanctum')->putJson("/api/tutor/quizzes/{$newId}", $this->attemptsPayload());
        $update->assertStatus(200);
    }

    public function test_structural_edit_denied_after_attempt_exists(): void
    {
        $this->createAttempt();
        $this->actingAs($this->tutor, 'sanctum')
            ->putJson("/api/tutor/quizzes/{$this->quiz->id}", $this->attemptsPayload())
            ->assertStatus(422);
    }

    public function test_question_and_correct_answer_mutation_denied_after_attempt(): void
    {
        $this->createAttempt();
        $this->actingAs($this->tutor, 'sanctum')
            ->putJson("/api/tutor/quizzes/{$this->quiz->id}", $this->attemptsPayload())
            ->assertStatus(422);

        // Structure must be preserved (no delete-and-rebuild happened).
        $this->assertDatabaseHas('quiz_questions', ['id' => $this->q1->id, 'question' => 'Q1']);
        $this->assertDatabaseHas('quiz_options', ['id' => $this->correct->id, 'is_correct' => true]);
    }

    public function test_grading_settings_changes_denied_after_attempt(): void
    {
        $this->createAttempt();

        $this->actingAs($this->tutor, 'sanctum')
            ->putJson("/api/tutor/quizzes/{$this->quiz->id}", ['passing_score' => 90])
            ->assertStatus(422);

        $this->actingAs($this->tutor, 'sanctum')
            ->putJson("/api/tutor/quizzes/{$this->quiz->id}", ['max_attempts' => 5])
            ->assertStatus(422);
    }

    public function test_quiz_delete_denied_after_attempt(): void
    {
        $this->createAttempt();
        $this->actingAs($this->tutor, 'sanctum')
            ->deleteJson("/api/tutor/quizzes/{$this->quiz->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('quizzes', ['id' => $this->quiz->id]);
    }

    public function test_harmless_metadata_edit_allowed_after_attempt(): void
    {
        $this->createAttempt();
        $this->actingAs($this->tutor, 'sanctum')
            ->putJson("/api/tutor/quizzes/{$this->quiz->id}", [
                'title' => 'Renamed Quiz',
                'description' => 'Still editable',
                'time_limit' => 20,
                'is_published' => false,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('quizzes', ['id' => $this->quiz->id, 'title' => 'Renamed Quiz', 'time_limit' => 20, 'is_published' => false]);
        // Structure untouched.
        $this->assertDatabaseHas('quiz_questions', ['id' => $this->q1->id, 'question' => 'Q1']);
        $this->assertDatabaseHas('quiz_options', ['id' => $this->correct->id, 'is_correct' => true]);
    }

    public function test_lesson_quiz_save_structural_edit_denied_after_attempt(): void
    {
        // Initial save (before attempts) succeeds.
        $initial = $this->actingAs($this->tutor, 'sanctum')->postJson("/api/tutor/courses/{$this->course->id}/lessons/{$this->lesson->id}/quiz", [
            'title' => 'Lesson Quiz',
            'passing_score' => 70,
            'questions' => [
                ['question' => 'A?', 'type' => 'multiple_choice', 'marks' => 5, 'options' => [
                    ['option_text' => '1', 'is_correct' => true],
                    ['option_text' => '2', 'is_correct' => false],
                ]],
            ],
        ]);
        $initial->assertStatus(200);

        $this->createAttempt();

        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/tutor/courses/{$this->course->id}/lessons/{$this->lesson->id}/quiz", [
            'title' => 'Lesson Quiz',
            'passing_score' => 70,
            'questions' => [
                ['question' => 'Changed?', 'type' => 'multiple_choice', 'marks' => 10, 'options' => [
                    ['option_text' => 'Z', 'is_correct' => true],
                    ['option_text' => 'W', 'is_correct' => false],
                ]],
            ],
        ])->assertStatus(422);
    }
}
