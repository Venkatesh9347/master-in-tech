<?php

namespace Tests\Feature;

use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\ClassSessionAttendance;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Goal6ClassSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tutor1;
    private User $tutor2;
    private User $studentEnrolled;
    private User $studentNotEnrolled;
    private Course $course1;
    private Course $course2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin.live@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->tutor1 = User::factory()->create([
            'name' => 'Tutor Rakesh',
            'email' => 'tutor.rakesh@example.com',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->tutor2 = User::factory()->create([
            'name' => 'Tutor Priya',
            'email' => 'tutor.priya@example.com',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->studentEnrolled = User::factory()->create([
            'name' => 'Student Alice',
            'email' => 'alice.student@example.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->studentNotEnrolled = User::factory()->create([
            'name' => 'Student Bob',
            'email' => 'bob.student@example.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->course1 = Course::create([
            'title' => 'Full Stack Web Development',
            'slug' => 'full-stack-web-dev',
            'description' => 'Comprehensive MERN and Next.js course',
            'instructor' => 'Tutor Rakesh',
            'instructor_id' => $this->tutor1->id,
            'duration' => '12 Weeks',
            'difficulty' => 'Intermediate',
            'price' => 299.00,
            'is_published' => true,
        ]);

        $this->course2 = Course::create([
            'title' => 'Data Science & Machine Learning',
            'slug' => 'data-science-ml',
            'description' => 'Python, Pandas, ML pipelines',
            'instructor' => 'Tutor Priya',
            'instructor_id' => $this->tutor2->id,
            'duration' => '10 Weeks',
            'difficulty' => 'Advanced',
            'price' => 349.00,
            'is_published' => true,
        ]);

        // Alice is enrolled in Course 1 only
        CourseEnrollment::create([
            'user_id' => $this->studentEnrolled->id,
            'course_id' => $this->course1->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 25,
        ]);
    }

    public function test_admin_can_create_live_class_session_with_zoom_and_teams(): void
    {
        // 1. Zoom session
        $resZoom = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/class-sessions', [
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'title' => 'React State & Hooks Masterclass',
            'description' => 'Deep dive into useReducer, useMemo and Custom Hooks',
            'platform' => 'Zoom',
            'meeting_url' => 'https://zoom.us/j/9876543210',
            'meeting_id' => '987 654 3210',
            'meeting_password' => 'REACT2026',
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'admin_notes' => 'Tutor must record session',
        ]);

        $resZoom->assertStatus(201)
            ->assertJsonPath('session.title', 'React State & Hooks Masterclass')
            ->assertJsonPath('session.platform', 'zoom')
            ->assertJsonPath('session.meeting_url', 'https://zoom.us/j/9876543210');

        $this->assertDatabaseHas('class_sessions', [
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'platform' => 'zoom',
            'title' => 'React State & Hooks Masterclass',
        ]);

        // 2. Microsoft Teams session
        $resTeams = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/class-sessions', [
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor2->id,
            'title' => 'Neural Networks with PyTorch',
            'platform' => 'Microsoft Teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_xyz',
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'start_time' => '18:00',
            'end_time' => '19:30',
            'status' => 'scheduled',
        ]);

        $resTeams->assertStatus(201)
            ->assertJsonPath('session.platform', 'teams');
    }

    public function test_admin_can_update_and_cancel_and_delete_class_session(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'title' => 'Initial Title',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => now()->addDays(1)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Update
        $updateRes = $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/class-sessions/{$session->id}", [
            'title' => 'Updated Advanced Title',
            'start_time' => '11:00',
            'end_time' => '12:30',
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('session.title', 'Updated Advanced Title')
            ->assertJsonPath('session.end_time', '12:30');

        // Cancel
        $cancelRes = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/class-sessions/{$session->id}/cancel");
        $cancelRes->assertStatus(200)
            ->assertJsonPath('session.status', 'cancelled');
        $this->assertEquals('cancelled', $session->fresh()->status);

        // Delete
        $delRes = $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/class-sessions/{$session->id}");
        $delRes->assertStatus(200);
        $this->assertDatabaseMissing('class_sessions', ['id' => $session->id]);
    }

    public function test_end_time_must_be_after_start_time_validation(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/class-sessions', [
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'title' => 'Invalid Time Test',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'start_time' => '15:00',
            'end_time' => '14:00', // invalid: before start time
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    }

    public function test_student_can_see_enrolled_course_sessions_and_cannot_access_other_courses(): void
    {
        $sessionCourse1 = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'title' => 'Full Stack Live Session 1',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/111111111',
            'scheduled_date' => now()->addDays(1)->toDateString(),
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $sessionCourse2 = ClassSession::create([
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor2->id,
            'title' => 'Data Science Live Session 1',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/222',
            'scheduled_date' => now()->addDays(1)->toDateString(),
            'start_time' => '17:00',
            'end_time' => '18:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Student Alice (enrolled in course 1) sees session 1 in her list
        $studentListRes = $this->actingAs($this->studentEnrolled, 'sanctum')->getJson('/api/student/class-sessions');
        $studentListRes->assertStatus(200);
        $sessionIds = collect($studentListRes->json())->pluck('id')->toArray();
        $this->assertContains($sessionCourse1->id, $sessionIds);
        $this->assertNotContains($sessionCourse2->id, $sessionIds);

        // Student Alice can access session 1 details
        $showRes1 = $this->actingAs($this->studentEnrolled, 'sanctum')->getJson("/api/student/class-sessions/{$sessionCourse1->id}");
        $showRes1->assertStatus(200)
            ->assertJsonPath('title', 'Full Stack Live Session 1');

        // Student Alice CANNOT access session 2 details (HTTP 403)
        $showRes2 = $this->actingAs($this->studentEnrolled, 'sanctum')->getJson("/api/student/class-sessions/{$sessionCourse2->id}");
        $showRes2->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized. You are not enrolled in the course for this session.');

        // Student Bob (not enrolled in course 1) CANNOT access session 1 (HTTP 403)
        $bobRes = $this->actingAs($this->studentNotEnrolled, 'sanctum')->getJson("/api/student/class-sessions/{$sessionCourse1->id}");
        $bobRes->assertStatus(403);
    }

    public function test_student_joining_session_records_attendance_and_returns_meeting_url(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'title' => 'HTML & CSS Deep Dive',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/999888777',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $joinRes = $this->actingAs($this->studentEnrolled, 'sanctum')->postJson("/api/student/class-sessions/{$session->id}/join");
        $joinRes->assertStatus(200)
            ->assertJsonPath('meeting_url', 'https://zoom.us/j/999888777')
            ->assertJsonPath('platform', 'zoom');

        $this->assertDatabaseHas('class_session_attendances', [
            'class_session_id' => $session->id,
            'user_id' => $this->studentEnrolled->id,
            'status' => 'present',
        ]);
    }

    public function test_student_cannot_join_cancelled_session(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'title' => 'Cancelled Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/999888777',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'cancelled',
            'created_by' => $this->admin->id,
        ]);

        $joinRes = $this->actingAs($this->studentEnrolled, 'sanctum')->postJson("/api/student/class-sessions/{$session->id}/join");
        $joinRes->assertStatus(400)
            ->assertJsonPath('message', 'This class has been cancelled and cannot be joined.');
    }

    public function test_tutor_can_only_access_assigned_sessions_and_cannot_access_others(): void
    {
        $sessionTutor1 = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'title' => 'Rakesh Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123',
            'scheduled_date' => now()->addDays(1)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '15:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $sessionTutor2 = ClassSession::create([
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor2->id,
            'title' => 'Priya Session',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/456',
            'scheduled_date' => now()->addDays(1)->toDateString(),
            'start_time' => '16:00',
            'end_time' => '17:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Tutor 1 sees only his sessions
        $tutorListRes = $this->actingAs($this->tutor1, 'sanctum')->getJson('/api/tutor/class-sessions');
        $tutorListRes->assertStatus(200);
        $ids = collect($tutorListRes->json())->pluck('id')->toArray();
        $this->assertContains($sessionTutor1->id, $ids);
        $this->assertNotContains($sessionTutor2->id, $ids);

        // Tutor 1 can view details of his assigned session
        $tutorShowRes1 = $this->actingAs($this->tutor1, 'sanctum')->getJson("/api/tutor/class-sessions/{$sessionTutor1->id}");
        $tutorShowRes1->assertStatus(200);

        // Tutor 1 CANNOT view details of Tutor 2's session (HTTP 403)
        $tutorShowRes2 = $this->actingAs($this->tutor1, 'sanctum')->getJson("/api/tutor/class-sessions/{$sessionTutor2->id}");
        $tutorShowRes2->assertStatus(403);
    }

    public function test_tutor_and_admin_can_upload_class_materials(): void
    {
        Storage::fake('public');

        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor1->id,
            'title' => 'Full Stack Session Materials',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123',
            'scheduled_date' => now()->addDays(1)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '15:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $file = UploadedFile::fake()->create('html_basics.pdf', 500, 'application/pdf');

        $uploadRes = $this->actingAs($this->tutor1, 'sanctum')->postJson("/api/tutor/class-sessions/{$session->id}/materials", [
            'title' => 'HTML Basics PDF',
            'description' => 'Comprehensive HTML slide deck',
            'file' => $file,
        ]);

        $uploadRes->assertStatus(201)
            ->assertJsonPath('material.title', 'HTML Basics PDF')
            ->assertJsonPath('material.file_name', 'html_basics.pdf');

        $this->assertDatabaseHas('class_materials', [
            'class_session_id' => $session->id,
            'title' => 'HTML Basics PDF',
        ]);
    }

    public function test_tutor_cannot_create_courses_or_manage_unauthorized_classes(): void
    {
        // Tutor attempting to create course -> HTTP 403
        $courseCreateRes = $this->actingAs($this->tutor1, 'sanctum')->postJson('/api/tutor/courses', [
            'title' => 'Maliciously Created Course',
            'description' => 'Trying to bypass admin control',
            'duration' => '4 Weeks',
            'difficulty' => 'Beginner',
        ]);
        $courseCreateRes->assertStatus(403);

        // Tutor attempting to access admin class sessions endpoint -> HTTP 403
        $adminEndpointRes = $this->actingAs($this->tutor1, 'sanctum')->getJson('/api/admin/class-sessions');
        $adminEndpointRes->assertStatus(403);
    }

    public function test_admin_cannot_assign_non_tutor_as_class_session_tutor(): void
    {
        $payloadBase = [
            'course_id' => $this->course1->id,
            'title' => 'Live Session Harden',
            'scheduled_date' => now()->addDays(4)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'scheduled',
        ];

        // A student (non-tutor) cannot be assigned as the session tutor.
        $rejected = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/class-sessions', array_merge($payloadBase, [
                'tutor_id' => $this->studentNotEnrolled->id,
            ]));

        $rejected->assertStatus(422)
            ->assertJsonValidationErrors(['tutor_id']);
        $this->assertDatabaseMissing('class_sessions', ['title' => 'Live Session Harden']);

        // A genuine tutor is accepted.
        $accepted = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/class-sessions', array_merge($payloadBase, [
                'tutor_id' => $this->tutor1->id,
            ]));

        $accepted->assertStatus(201)
            ->assertJsonPath('session.tutor_id', $this->tutor1->id);
    }
}
