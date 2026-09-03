<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\CourseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoursePersistenceAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourseCatalogSeeder::class);
    }

    // 1. Data Persistence Test: Admin edits course description, persists, and restores
    public function test_01_admin_edit_persistence_and_restore(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::where('slug', 'ethical-hacking')->first();
        $originalDescription = $course->description;

        Sanctum::actingAs($admin);

        $testDescription = 'Updated Security Audit Description for Verification ' . uniqid();

        // 1. Admin updates description
        $updateResponse = $this->putJson("/api/courses/{$course->id}", [
            'title' => $course->title,
            'description' => $testDescription,
            'category' => $course->category,
            'instructor' => $course->instructor,
            'duration' => $course->duration,
            'difficulty' => $course->difficulty,
            'is_published' => true,
        ]);
        $updateResponse->assertOk();

        // 2. Fetch directly from DB
        $course->refresh();
        $this->assertEquals($testDescription, $course->description);

        // 3. Fetch from API endpoint
        $showResponse = $this->getJson("/api/courses/{$course->slug}");
        $showResponse->assertOk()
            ->assertJsonPath('description', $testDescription);

        // 4. Restore original description
        $restoreResponse = $this->putJson("/api/courses/{$course->id}", [
            'title' => $course->title,
            'description' => $originalDescription,
            'category' => $course->category,
            'instructor' => $course->instructor,
            'duration' => $course->duration,
            'difficulty' => $course->difficulty,
            'is_published' => true,
        ]);
        $restoreResponse->assertOk();

        $course->refresh();
        $this->assertEquals($originalDescription, $course->description);
    }

    // 2. Publish / Draft Test: Public cannot see draft; Admin can see draft; Toggle back to published
    public function test_02_publish_draft_lifecycle_visibility(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::where('slug', 'ethical-hacking')->first();

        Sanctum::actingAs($admin);

        // Toggle to draft
        $draftResponse = $this->putJson("/api/courses/{$course->id}", [
            'title' => $course->title,
            'description' => $course->description,
            'category' => $course->category,
            'instructor' => $course->instructor,
            'duration' => $course->duration,
            'difficulty' => $course->difficulty,
            'is_published' => false,
        ]);
        $draftResponse->assertOk();

        // Admin can still see it in /api/courses
        $adminCourses = $this->getJson('/api/courses');
        $adminCourses->assertOk();
        $this->assertTrue(collect($adminCourses->json())->contains('slug', 'ethical-hacking'));

        // Public / Student CANNOT see it in /api/courses
        Sanctum::actingAs($student);
        $studentCourses = $this->getJson('/api/courses');
        $studentCourses->assertOk();
        $this->assertFalse(collect($studentCourses->json())->contains('slug', 'ethical-hacking'));

        // Admin toggles back to published
        Sanctum::actingAs($admin);
        $publishResponse = $this->putJson("/api/courses/{$course->id}", [
            'title' => $course->title,
            'description' => $course->description,
            'category' => $course->category,
            'instructor' => $course->instructor,
            'duration' => $course->duration,
            'difficulty' => $course->difficulty,
            'is_published' => true,
        ]);
        $publishResponse->assertOk();

        // Public / Student can see it again
        Sanctum::actingAs($student);
        $studentCoursesAgain = $this->getJson('/api/courses');
        $studentCoursesAgain->assertOk();
        $this->assertTrue(collect($studentCoursesAgain->json())->contains('slug', 'ethical-hacking'));
    }

    // 3. Existing LMS Regression: Student enrollment -> classroom -> progress tracking
    public function test_03_student_learning_progress_on_new_catalog_course(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::where('slug', 'ethical-hacking')->first();
        $firstLesson = $course->lessons()->orderBy('id')->first();

        // Enroll student
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Fetch student enrolled courses
        $myCoursesResponse = $this->getJson('/api/my-courses');
        $myCoursesResponse->assertOk();
        $this->assertTrue(collect($myCoursesResponse->json())->contains('course_id', $course->id));

        // Start lesson progress
        $startProgressResponse = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/start");
        $startProgressResponse->assertOk()
            ->assertJsonPath('started', true);

        // Update video playback progress to 40%
        $playbackProgressResponse = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/playback-progress", [
            'current_time' => 120,
            'duration' => 300,
        ]);
        $playbackProgressResponse->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('watched_percent', 40);

        // Update video playback to >= 90% threshold for completion eligibility
        $playbackProgressFull = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/playback-progress", [
            'current_time' => 280,
            'duration' => 300,
        ]);
        $playbackProgressFull->assertOk()
            ->assertJsonPath('status', 'completed');

        // Complete lesson progress
        $completeProgressResponse = $this->postJson("/api/courses/{$course->id}/lessons/{$firstLesson->id}/complete");
        $completeProgressResponse->assertOk()
            ->assertJsonPath('completed', true);

        // Verify persisted in DB
        $dbProgress = LessonProgress::where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->where('lesson_id', $firstLesson->id)
            ->first();

        $this->assertNotNull($dbProgress);
        $this->assertTrue((bool) $dbProgress->completed);
        $this->assertNotNull($dbProgress->completed_at);
    }
}
