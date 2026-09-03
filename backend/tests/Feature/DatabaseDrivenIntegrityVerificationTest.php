<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Database\Seeders\CourseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DatabaseDrivenIntegrityVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourseCatalogSeeder::class);
    }

    public function test_01_courses_and_levels_exist_in_database(): void
    {
        $this->assertGreaterThanOrEqual(100, Course::where('is_published', true)->count());
        $this->assertGreaterThanOrEqual(20, Course::where('difficulty', 'Basic')->where('is_published', true)->count());
        $this->assertGreaterThanOrEqual(40, Course::where('difficulty', 'Intermediate')->where('is_published', true)->count());
        $this->assertGreaterThanOrEqual(40, Course::where('difficulty', 'Advanced')->where('is_published', true)->count());
    }

    public function test_02_course_details_endpoints_for_basic_intermediate_advanced_courses(): void
    {
        $testSlugs = [
            'cyber-security-fundamentals' => 'Basic',
            'programming-fundamentals' => 'Basic',
            'ethical-hacking' => 'Intermediate',
            'full-stack-web-development' => 'Intermediate',
            'advanced-ethical-hacking' => 'Advanced',
            'advanced-full-stack-engineering' => 'Advanced',
        ];

        foreach ($testSlugs as $slug => $expectedLevel) {
            $response = $this->getJson("/api/courses/{$slug}");
            $response->assertOk();
            $data = $response->json();

            $this->assertEquals($expectedLevel, $data['difficulty']);
            $this->assertNotEmpty($data['title']);
            $this->assertNotEmpty($data['description']);
            $this->assertNotEmpty($data['prerequisites']);
            $this->assertNotEmpty($data['learning_objectives']);
            $this->assertNotEmpty($data['sections']);
        }
    }

    public function test_03_curriculum_integrity_and_cross_course_isolation(): void
    {
        $courseA = Course::where('slug', 'ethical-hacking')->first();
        $courseB = Course::where('slug', 'full-stack-web-development')->first();

        $lessonA = $courseA->lessons()->first();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $courseB->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Trying to start lesson from course A while only enrolled in course B must return 404 or 403
        $response = $this->postJson("/api/courses/{$courseB->id}/lessons/{$lessonA->id}/start");
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    public function test_04_unenrolled_student_is_blocked_by_server_authorization(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::where('slug', 'ethical-hacking')->first();
        $lesson = $course->lessons()->first();

        Sanctum::actingAs($student);

        $startRes = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/start");
        $startRes->assertStatus(403);

        $completeRes = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/complete");
        $completeRes->assertStatus(403);
    }

    public function test_05_real_database_progress_and_persistence_lifecycle(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::where('slug', 'ethical-hacking')->first();
        $publishedLessons = $course->lessons()->where('is_published', true)->orderBy('id')->get();
        $firstLesson = $publishedLessons->first();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Initial progress must be 0%
        $progressRes0 = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $progressRes0->assertOk();
        $this->assertEquals(0, (float) $progressRes0->json('progress_percentage'));

        // Start first lesson
        $startRes = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/start");
        $startRes->assertOk();

        // Simulate video playback to >= 90%
        $playbackRes = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/playback-progress", [
            'current_time' => 280,
            'duration' => 300,
        ]);
        $playbackRes->assertOk();

        // Complete first lesson
        $completeRes = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/complete");
        $completeRes->assertOk();

        $expectedPercent = round((1 / $publishedLessons->count()) * 100, 2);

        // Fetch refreshed progress from database
        $progressRes1 = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $progressRes1->assertOk();
        $this->assertEquals($expectedPercent, (float) $progressRes1->json('progress_percentage'));

        // Verify DB record
        $dbRecord = LessonProgress::where('user_id', $student->id)
            ->where('lesson_id', $firstLesson->id)
            ->first();
        $this->assertNotNull($dbRecord);
        $this->assertTrue((bool) $dbRecord->completed);
        $this->assertNotNull($dbRecord->completed_at);
    }

    public function test_06_quiz_and_assignment_database_flow(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $tutor = User::where('role', 'tutor')->first();
        $course = Course::where('slug', 'ethical-hacking')->first();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $quizLesson = $course->lessons()->where('type', 'quiz')->first();
        $this->assertNotNull($quizLesson);
        $quiz = Quiz::where('lesson_id', $quizLesson->id)->first();
        $this->assertNotNull($quiz);

        Sanctum::actingAs($student);

        // Start and submit quiz attempt
        $startQuizRes = $this->postJson("/api/quizzes/{$quiz->id}/start");
        $startQuizRes->assertSuccessful();
        $attemptId = $startQuizRes->json('attempt.id') ?? $startQuizRes->json('id');
        $question = $quiz->questions()->first();
        $option = $question?->options()->first();

        $answersPayload = [];
        if ($question && $option) {
            $answersPayload[] = [
                'question_id' => $question->id,
                'option_id' => $option->id,
            ];
        }

        $quizAttemptRes = $this->postJson("/api/quiz-attempts/{$attemptId}/submit", [
            'answers' => $answersPayload,
        ]);
        $quizAttemptRes->assertOk();

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attemptId,
            'user_id' => $student->id,
            'quiz_id' => $quiz->id,
        ]);

        // Submit assignment
        $assignLesson = $course->lessons()->where('type', 'assignment')->first();
        $this->assertNotNull($assignLesson);
        $assignment = Assignment::where('lesson_id', $assignLesson->id)->first();
        $this->assertNotNull($assignment);

        $submissionRes = $this->postJson("/api/assignments/{$assignment->id}/submit", [
            'submission_url' => 'https://github.com/example/student-capstone-lab',
            'notes' => 'Completed all penetration testing lab requirements.',
        ]);
        $submissionRes->assertSuccessful();

        $this->assertDatabaseHas('assignment_submissions', [
            'user_id' => $student->id,
            'assignment_id' => $assignment->id,
        ]);

        // Tutor can see the submission
        Sanctum::actingAs($tutor);
        $tutorSubmissions = $this->getJson('/api/tutor/submissions');
        $tutorSubmissions->assertOk();
    }

    public function test_07_cross_user_security_isolation(): void
    {
        $studentA = User::factory()->create(['role' => 'student']);
        $studentB = User::factory()->create(['role' => 'student']);
        $course = Course::where('slug', 'ethical-hacking')->first();
        $lesson = $course->lessons()->first();

        CourseEnrollment::create([
            'user_id' => $studentA->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        CourseEnrollment::create([
            'user_id' => $studentB->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // Student A completes lesson
        LessonProgress::create([
            'user_id' => $studentA->id,
            'course_id' => $course->id,
            'section_id' => $lesson->section_id,
            'lesson_id' => $lesson->id,
            'status' => 'completed',
            'completed' => true,
            'completed_at' => now(),
        ]);

        // Student B checks progress - must still be 0%
        Sanctum::actingAs($studentB);
        $progressB = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $progressB->assertOk();
        $this->assertEquals(0, (float) $progressB->json('progress_percentage'));
    }
}
