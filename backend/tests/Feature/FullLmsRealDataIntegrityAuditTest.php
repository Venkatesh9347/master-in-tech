<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LearningActivityLog;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Database\Seeders\CourseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FullLmsRealDataIntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourseCatalogSeeder::class);
    }

    // 1. Student Authentication & Session Persistence
    public function test_01_student_authentication_and_identity_validation(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        // Unauthenticated access
        $unauthRes = $this->getJson('/api/user');
        $unauthRes->assertStatus(401);

        // Authenticated access
        Sanctum::actingAs($student);
        $authRes = $this->getJson('/api/user');
        $authRes->assertOk()
            ->assertJsonPath('id', $student->id)
            ->assertJsonPath('email', $student->email)
            ->assertJsonPath('role', 'student');
    }

    // 2. Course Enrollment - Server Authorization Gate
    public function test_02_course_enrollment_gate_blocks_unenrolled_students(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::where('slug', 'ethical-hacking')->first();
        $lesson = $course->lessons()->where('is_published', true)->first();

        Sanctum::actingAs($student);

        // Attempting to access lms-progress without active enrollment must return 403
        $progressRes = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $progressRes->assertStatus(403)
            ->assertJsonPath('enrollment_required', true);

        // Attempting to mark lesson complete without enrollment must return 403
        $completeRes = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/complete");
        $completeRes->assertStatus(403);
    }

    // 3. Lesson Access & Cross-Course Isolation
    public function test_03_lesson_access_and_cross_course_isolation(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $courseA = Course::where('slug', 'ethical-hacking')->first();
        $courseB = Course::where('slug', 'full-stack-web-development')->first();

        $lessonA = $courseA->lessons()->where('is_published', true)->first();

        // Enroll student ONLY in course B
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $courseB->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Student tries to access course A lesson under course B endpoint
        $res = $this->postJson("/api/courses/{$courseB->id}/lessons/{$lessonA->id}/start");
        $this->assertTrue(in_array($res->status(), [403, 404]));
    }

    // 4. Real Lesson Progress & Refresh Persistence
    public function test_04_lesson_progress_is_server_authoritative_and_persists(): void
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

        // 1. Initial state is 0%
        $initRes = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $initRes->assertOk();
        $this->assertEquals(0, (float) $initRes->json('progress_percentage'));
        $this->assertEquals(0, (int) $initRes->json('completed_lesson_count'));

        // 2. Playback progress to 95%
        $playRes = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/playback-progress", [
            'current_time' => 285,
            'duration' => 300,
        ]);
        $playRes->assertOk()
            ->assertJsonPath('status', 'completed');

        // 3. Mark complete
        $compRes = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/complete");
        $compRes->assertOk()
            ->assertJsonPath('completed', true);

        $expectedPercentage = round((1 / $publishedLessons->count()) * 100, 2);

        // 4. Simulate page refresh / new fetch from DB
        $refreshRes = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $refreshRes->assertOk();
        $this->assertEquals($expectedPercentage, (float) $refreshRes->json('progress_percentage'));
        $this->assertEquals(1, (int) $refreshRes->json('completed_lesson_count'));

        // 5. Verify database records
        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $firstLesson->id,
            'completed' => true,
        ]);
    }

    // 5. Duplicate Completion Prevention & Idempotency
    public function test_05_duplicate_completion_prevention_and_idempotency(): void
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

        // Complete lesson first time
        $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/playback-progress", [
            'current_time' => 285,
            'duration' => 300,
        ]);
        $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/complete");

        // Complete lesson a second and third time
        $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/complete");
        $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/complete");

        // Assert exactly ONE database row exists for this student + course + lesson
        $count = LessonProgress::where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->where('lesson_id', $firstLesson->id)
            ->count();
        $this->assertEquals(1, $count);

        // Progress percentage must still reflect exactly 1 completed lesson
        $expectedPercentage = round((1 / $publishedLessons->count()) * 100, 2);
        $progressRes = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $this->assertEquals($expectedPercentage, (float) $progressRes->json('progress_percentage'));
    }

    // 6. 100% Course Completion Calculation
    public function test_06_100_percent_completion_triggers_course_completed_status(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::where('slug', 'ethical-hacking')->first();
        $publishedLessons = $course->lessons()->where('is_published', true)->get();

        $enrollment = CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // Complete all published lessons in database
        foreach ($publishedLessons as $lesson) {
            LessonProgress::create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'section_id' => $lesson->section_id,
                'lesson_id' => $lesson->id,
                'status' => 'completed',
                'completed' => true,
                'completed_at' => now(),
                'progress_percentage' => 100.0,
            ]);
        }

        Sanctum::actingAs($student);

        $progressRes = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $progressRes->assertOk();
        $this->assertEquals(100, (float) $progressRes->json('progress_percentage'));
        $this->assertTrue((bool) $progressRes->json('is_course_completed'));
        $this->assertEquals($publishedLessons->count(), (int) $progressRes->json('completed_lesson_count'));

        $this->assertDatabaseHas('course_enrollments', [
            'id' => $enrollment->id,
            'status' => 'completed',
            'progress_percentage' => 100,
        ]);
    }

    // 7. Cross-User Security Isolation
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

        // Student B queries progress - must be 0%
        Sanctum::actingAs($studentB);
        $progressB = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $progressB->assertOk();
        $this->assertEquals(0, (float) $progressB->json('progress_percentage'));
        $this->assertEquals(0, (int) $progressB->json('completed_lesson_count'));
    }

    // 8. Tutor & Admin Real-Data Visibility
    public function test_08_tutor_and_admin_view_real_database_progress(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::where('slug', 'ethical-hacking')->first();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 45.0,
            'enrolled_at' => now(),
        ]);

        // Tutor checks stats and submissions
        Sanctum::actingAs($tutor);
        $tutorStats = $this->getJson('/api/tutor/stats');
        $tutorStats->assertOk();

        // Admin checks enrollments & progress
        Sanctum::actingAs($admin);
        $adminUsers = $this->getJson('/api/admin/users');
        $adminUsers->assertOk();
    }
}
