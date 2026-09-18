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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NEW-SEC-02 regression: named per-user limiters on abuse-sensitive
 * authenticated endpoints.
 *
 * In the testing environment every limiter allows 60 requests/minute
 * (testing burst), so each enforcement test issues 65 requests and
 * expects requests 1-60 to reach the controller and 61+ to return a
 * clean 429 without internal details.
 */
class RateLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Test-storage hygiene: the playback-auth limiter test reaches the
        // controller, which writes enc.key files via the video disk; isolate
        // them on a wiped fake so nothing persists in development storage.
        Storage::fake('local');
        config(['video.storage_disk' => 'local']);
    }

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);

        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'Rate limit test course.',
            'category' => 'Engineering',
            'instructor' => 'Rate Instructor',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ], $attributes));
    }

    private function createLesson(Course $course, array $attributes = []): Lesson
    {
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'slug' => 'section-1-' . Str::random(4),
            'sort_order' => 0,
            'is_published' => true,
        ]);

        return Lesson::create(array_merge([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Lesson 1',
            'slug' => 'lesson-1-' . Str::random(4),
            'type' => 'video',
            'duration' => '10 min',
            'is_published' => true,
        ], $attributes));
    }

    private function enrollStudent(User $student, Course $course): void
    {
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 0,
        ]);
    }

    private function assertClean429(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
        $content = $response->getContent();
        $this->assertStringContainsString('Too Many Attempts.', $content);
        $this->assertStringNotContainsString('Trace', $content);
        $this->assertStringNotContainsString('trace', $content);
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('Exception', $content);
    }

    public function test_lms_write_limiter_enforces_429_on_lesson_start(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $lesson = $this->createLesson($course);
        $this->enrollStudent($student, $course);

        $statuses = [];
        for ($i = 0; $i < 65; $i++) {
            $statuses[] = $this->actingAs($student, 'sanctum')
                ->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/start")
                ->getStatusCode();
        }

        // First request is legitimate business behavior.
        $this->assertEquals(200, $statuses[0]);
        // Requests 1-60 reach the controller; 61+ are throttled.
        foreach (array_slice($statuses, 0, 60) as $status) {
            $this->assertNotEquals(429, $status);
            $this->assertNotEquals(500, $status);
        }
        foreach (array_slice($statuses, 60) as $status) {
            $this->assertEquals(429, $status);
        }

        $this->assertClean429(
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/start")
        );
    }

    public function test_limiter_is_keyed_per_user(): void
    {
        $studentA = User::factory()->create(['role' => 'student']);
        $studentB = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $lesson = $this->createLesson($course);
        $this->enrollStudent($studentA, $course);
        $this->enrollStudent($studentB, $course);

        for ($i = 0; $i < 61; $i++) {
            $this->actingAs($studentA, 'sanctum')
                ->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/start");
        }

        // User A is throttled...
        $this->actingAs($studentA, 'sanctum')
            ->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/start")
            ->assertStatus(429);

        // ...while user B is unaffected.
        $this->actingAs($studentB, 'sanctum')
            ->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/start")
            ->assertStatus(200);
    }

    public function test_quiz_submit_counts_toward_lms_write_limiter(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $lesson = $this->createLesson($course, ['type' => 'quiz']);
        $this->enrollStudent($student, $course);

        $quiz = Quiz::create([
            'lesson_id' => $lesson->id,
            'title' => 'Throttle Quiz',
            'passing_score' => 70,
            'is_published' => true,
        ]);
        $question = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What is 2 + 2?',
            'type' => 'multiple_choice',
            'marks' => 10,
            'sort_order' => 0,
            'is_published' => true,
        ]);
        $correct = QuizOption::create([
            'question_id' => $question->id,
            'option_text' => '4',
            'is_correct' => true,
            'sort_order' => 0,
        ]);

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start");
        $startRes->assertCreated();
        $attemptId = $startRes->json('attempt.id');

        $payload = ['answers' => [['question_id' => $question->id, 'option_id' => $correct->id]]];
        $statuses = [];
        for ($i = 0; $i < 64; $i++) {
            $statuses[] = $this->actingAs($student, 'sanctum')
                ->postJson("/api/quiz-attempts/{$attemptId}/submit", $payload)
                ->getStatusCode();
        }

        // First submit grades normally; the tail is throttled (1 start + 64
        // submits = 65 hits, so the last 5 exceed the 60/minute cap).
        $this->assertEquals(200, $statuses[0]);
        foreach (array_slice($statuses, 59) as $status) {
            $this->assertEquals(429, $status);
        }
        $this->assertNotContains(500, $statuses);
    }

    public function test_playback_auth_limiter_enforces_429(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $lesson = $this->createLesson($course);
        $this->enrollStudent($student, $course);

        $statuses = [];
        for ($i = 0; $i < 65; $i++) {
            $statuses[] = $this->actingAs($student, 'sanctum')
                ->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/playback-auth")
                ->getStatusCode();
        }

        $this->assertEquals(200, $statuses[0]);
        foreach (array_slice($statuses, 0, 60) as $status) {
            $this->assertNotEquals(429, $status);
        }
        foreach (array_slice($statuses, 60) as $status) {
            $this->assertEquals(429, $status);
        }
    }

    public function test_certificate_download_limiter_enforces_clean_429(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $statuses = [];
        $last = null;
        for ($i = 0; $i < 65; $i++) {
            $last = $this->actingAs($student, 'sanctum')
                ->getJson('/api/student/certificates/BOGUS-CODE-0000/download');
            $statuses[] = $last->getStatusCode();
        }

        // Throttle counts every hit (even 404s); the tail must be clean 429s.
        foreach (array_slice($statuses, 60) as $status) {
            $this->assertEquals(429, $status);
        }
        $this->assertNotContains(500, $statuses);
        $this->assertClean429($last);
    }

    public function test_livekit_token_limiter_enforces_429(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $statuses = [];
        for ($i = 0; $i < 65; $i++) {
            $statuses[] = $this->actingAs($student, 'sanctum')
                ->postJson('/api/class-sessions/999999/livekit-token')
                ->getStatusCode();
        }

        foreach (array_slice($statuses, 60) as $status) {
            $this->assertEquals(429, $status);
        }
        $this->assertNotContains(500, $statuses);
    }
}
