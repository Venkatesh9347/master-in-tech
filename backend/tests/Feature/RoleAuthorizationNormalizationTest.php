<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAuthorizationNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTutor(string $name): User
    {
        return User::factory()->create(['role' => 'tutor', 'name' => $name]);
    }

    private function makeCourse(User $tutor, array $extra = []): Course
    {
        return Course::create(array_merge([
            'title' => 'Role Normalization Course',
            'slug' => 'role-normalization-'.uniqid(),
            'description' => 'Course used to verify super_admin authorization parity.',
            'category' => 'Software Engineering',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ], $extra));
    }

    // P1-3 #1: super_admin can create courses via the tutor route (was 403 before normalization).
    public function test_super_admin_can_create_course_via_tutor_route(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/tutor/courses', [
            'title' => 'Super Admin Created Course',
            'description' => 'Created through the tutor creation endpoint.',
            'category' => 'Data Science',
            'duration' => '8 weeks',
            'difficulty' => 'Advanced',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('title', 'Super Admin Created Course');

        $this->assertDatabaseHas('courses', ['title' => 'Super Admin Created Course']);
    }

    // P1-3 #2: super_admin sees all platform courses and full details in the tutor area.
    public function test_super_admin_sees_all_courses_and_pricing_in_tutor_area(): void
    {
        $otherTutor = $this->makeTutor('Other Tutor');
        $courseA = $this->makeCourse($otherTutor, ['title' => 'Course A', 'slug' => 'course-a', 'price' => 499.99]);
        $this->makeCourse($otherTutor, ['title' => 'Course B', 'slug' => 'course-b', 'price' => 299.99]);

        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin);

        $list = $this->getJson('/api/tutor/courses');
        $list->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['title' => 'Course A'])
            ->assertJsonFragment(['title' => 'Course B']);

        $stats = $this->getJson('/api/tutor/stats');
        $stats->assertOk()
            ->assertJsonPath('total_courses', 2);

        $show = $this->getJson("/api/tutor/courses/{$courseA->id}");
        $show->assertOk()
            ->assertJsonPath('price', '499.99');
    }

    // P1-3 #3: super_admin can view another tutor's unpublished curriculum.
    public function test_super_admin_sees_unpublished_content_in_curriculum_views(): void
    {
        $otherTutor = $this->makeTutor('Other Tutor');
        $course = $this->makeCourse($otherTutor);
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Draft Module',
            'sort_order' => 1,
            'is_published' => false,
        ]);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Draft Lesson',
            'type' => 'text',
            'sort_order' => 1,
            'is_published' => false,
        ]);

        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin);

        $curriculum = $this->getJson("/api/courses/{$course->id}/curriculum");
        $curriculum->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['title' => 'Draft Module']);

        $lessonShow = $this->getJson("/api/courses/{$course->id}/sections/{$section->id}/lessons/{$lesson->id}");
        $lessonShow->assertOk()
            ->assertJsonPath('title', 'Draft Lesson');
    }

    // P1-3 #4: super_admin can open any class session regardless of tutor assignment.
    public function test_super_admin_can_access_class_session_of_another_tutor(): void
    {
        $otherTutor = $this->makeTutor('Other Tutor');
        $course = $this->makeCourse($otherTutor);

        $session = ClassSession::create([
            'course_id' => $course->id,
            'tutor_id' => $otherTutor->id,
            'created_by' => $otherTutor->id,
            'title' => 'Owner Session',
            'scheduled_date' => '2026-12-01',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'scheduled',
            'platform' => 'livekit',
        ]);

        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson("/api/tutor/class-sessions/{$session->id}");
        $response->assertOk()
            ->assertJsonPath('id', $session->id);
    }

    // P1-3 #5: super_admin bypasses enrollment for playback of unpublished lessons/courses.
    public function test_super_admin_can_authorize_playback_for_unpublished_lesson(): void
    {
        $otherTutor = $this->makeTutor('Other Tutor');
        $course = $this->makeCourse($otherTutor, ['is_published' => false]);
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Locked Video',
            'type' => 'video',
            'sort_order' => 1,
            'is_published' => false,
        ]);

        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/playback-auth");
        $response->assertOk()
            ->assertJsonPath('title', 'Locked Video');
    }

    // P1-3 #6: faculty counts as a valid class-session host (tutor-tier role).
    public function test_faculty_can_be_assigned_as_class_session_host(): void
    {
        $faculty = User::factory()->create(['role' => 'faculty']);
        $course = $this->makeCourse($this->makeTutor('Hosting Tutor'));

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/class-sessions', [
            'course_id' => $course->id,
            'tutor_id' => $faculty->id,
            'title' => 'Faculty Hosted Session',
            'scheduled_date' => '2026-12-01',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'platform' => 'livekit',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('class_sessions', [
            'tutor_id' => $faculty->id,
            'title' => 'Faculty Hosted Session',
        ]);
    }

    // P1-3 #7: super_admin is a valid class-session host (full-admin tier role).
    public function test_super_admin_can_be_assigned_as_class_session_host(): void
    {
        $superHost = User::factory()->create(['role' => 'super_admin']);
        $course = $this->makeCourse($this->makeTutor('Hosting Tutor'));

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/class-sessions', [
            'course_id' => $course->id,
            'tutor_id' => $superHost->id,
            'title' => 'Super Admin Hosted Session',
            'scheduled_date' => '2026-12-02',
            'start_time' => '12:00',
            'end_time' => '13:00',
            'platform' => 'livekit',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('class_sessions', [
            'tutor_id' => $superHost->id,
            'title' => 'Super Admin Hosted Session',
        ]);
    }
}