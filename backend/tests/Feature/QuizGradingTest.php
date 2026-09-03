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
}
