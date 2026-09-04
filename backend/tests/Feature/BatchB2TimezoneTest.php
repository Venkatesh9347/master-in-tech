<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Course;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchB2TimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCourse(): Course
    {
        return Course::create([
            'title' => 'TZ Course',
            'slug' => 'tz-course-' . uniqid(),
            'description' => 'desc',
            'instructor' => 'Tutor',
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);
    }

    private function makeTutorId(): int
    {
        return \App\Models\User::factory()->create(['role' => 'tutor', 'status' => 'active'])->id;
    }

    public function test_business_timezone_is_config_driven_for_class_scheduling(): void
    {
        // Fixed instant: 2026-06-15 20:00 UTC == 2026-06-16 01:30 Asia/Kolkata.
        Carbon::setTestNow(Carbon::parse('2026-06-15 20:00:00', 'UTC'));

        $course = $this->makeCourse();
        $session = ClassSession::create([
            'course_id' => $course->id,
            'tutor_id' => $this->makeTutorId(),
            'created_by' => $this->makeTutorId(),
            'title' => 'TZ Session',
            'scheduled_date' => '2026-06-16', // "today" in IST, "tomorrow" in UTC
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'scheduled',
            'platform' => 'livekit',
        ]);

        // With Asia/Kolkata (the default business timezone) the session is "today".
        config(['app.business_timezone' => 'Asia/Kolkata']);
        $this->assertSame('scheduled', $session->calculateStatus());

        // With UTC the same session is no longer "today" -> it is upcoming/scheduled.
        config(['app.business_timezone' => 'UTC']);
        $this->assertSame('scheduled', $session->calculateStatus());

        // Prove the date boundary changes with the configured timezone: in IST today's
        // list includes the session; in UTC it does not.
        config(['app.business_timezone' => 'Asia/Kolkata']);
        $this->assertSame(1, ClassSession::today()->count());

        config(['app.business_timezone' => 'UTC']);
        $this->assertSame(0, ClassSession::today()->count());
    }

    public function test_business_timezone_can_be_overridden_to_other_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 20:00:00', 'UTC'));

        $course = $this->makeCourse();
        ClassSession::create([
            'course_id' => $course->id,
            'tutor_id' => $this->makeTutorId(),
            'created_by' => $this->makeTutorId(),
            'title' => 'NYC Session',
            'scheduled_date' => '2026-06-15', // today in UTC, still 06-15 in New York at this instant
            'start_time' => '16:00',
            'end_time' => '17:00',
            'status' => 'scheduled',
            'platform' => 'livekit',
        ]);

        // America/New_York at 20:00 UTC is 16:00 local same day (EDT).
        config(['app.business_timezone' => 'America/New_York']);
        $this->assertSame(1, ClassSession::today()->count());
    }

    public function test_default_business_timezone_preserves_istanbul_scheduling(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.business_timezone'));
    }

    public function test_quiz_deadline_comparison_remains_correct_with_business_timezone(): void
    {
        // Business timezone set to IST does not change how the quiz time-limit
        // deadline is compared (both sides remain consistently serialized).
        config(['app.business_timezone' => 'Asia/Kolkata']);

        $student = \App\Models\User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse();
        \App\Models\CourseEnrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);
        $section = \App\Models\Section::create(['course_id' => $course->id, 'title' => 'S', 'sort_order' => 0, 'is_published' => true]);
        $lesson = \App\Models\Lesson::create(['course_id' => $course->id, 'section_id' => $section->id, 'title' => 'Q', 'type' => 'quiz', 'sort_order' => 0, 'is_published' => true]);
        $quiz = \App\Models\Quiz::create(['lesson_id' => $lesson->id, 'title' => 'Q', 'passing_score' => 70, 'time_limit' => 10, 'is_published' => true]);
        $q = \App\Models\QuizQuestion::create(['quiz_id' => $quiz->id, 'question' => 'q', 'type' => 'multiple_choice', 'marks' => 10, 'is_published' => true]);
        $opt = \App\Models\QuizOption::create(['question_id' => $q->id, 'option_text' => 'A', 'is_correct' => true]);

        $start = $this->actingAs($student, 'sanctum')->postJson("/api/quizzes/{$quiz->id}/start");
        $start->assertCreated();
        $attemptId = $start->json('attempt.id');

        // Backdate the started_at beyond the time limit to force an expired attempt.
        \App\Models\QuizAttempt::where('id', $attemptId)->update([
            'started_at' => now()->subMinutes(20),
        ]);

        $submit = $this->actingAs($student, 'sanctum')->postJson("/api/quiz-attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $q->id, 'option_id' => $opt->id]],
        ]);

        // Deadline comparison still enforced regardless of business timezone.
        $submit->assertStatus(409);
    }

    public function test_no_hardcoded_timezone_remains_in_affected_business_logic(): void
    {
        $files = [
            base_path('app/Models/ClassSession.php'),
            base_path('app/Http/Controllers/Api/StudentClassSessionController.php'),
            base_path('app/Http/Controllers/Api/TutorClassSessionController.php'),
            base_path('app/Http/Controllers/Api/AdminClassSessionController.php'),
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('Asia/Kolkata', $contents, "Hardcoded timezone found in {$file}");
        }
    }
}
