<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LiveClassMeetingUrlVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tutor;
    private User $unrelatedTutor;
    private User $enrolledStudent;
    private User $unenrolledStudent;
    private Course $course;
    private Course $unrelatedCourse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->tutor = User::factory()->create([
            'name' => 'Professor Sharma',
            'email' => 'sharma@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->unrelatedTutor = User::factory()->create([
            'name' => 'Professor Verma',
            'email' => 'verma@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->enrolledStudent = User::factory()->create([
            'name' => 'Enrolled Student',
            'email' => 'student.enrolled@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->unenrolledStudent = User::factory()->create([
            'name' => 'Unenrolled Student',
            'email' => 'student.unenrolled@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->course = Course::create([
            'title' => 'Advanced Cloud Architecture',
            'slug' => 'advanced-cloud-architecture',
            'description' => 'AWS, GCP, Azure Enterprise Cloud Engineering',
            'instructor' => 'Professor Sharma',
            'instructor_id' => $this->tutor->id,
            'duration' => '12 Weeks',
            'difficulty' => 'Advanced',
            'price' => 499.00,
            'is_published' => true,
        ]);

        $this->unrelatedCourse = Course::create([
            'title' => 'Cybersecurity Operations',
            'slug' => 'cybersecurity-operations',
            'description' => 'SOC and Pentesting Fundamentals',
            'instructor' => 'Professor Verma',
            'instructor_id' => $this->unrelatedTutor->id,
            'duration' => '8 Weeks',
            'difficulty' => 'Intermediate',
            'price' => 399.00,
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test 1: Session with meeting URL -> Live class appears with meeting URL for enrolled student & assigned tutor.
     */
    public function test_session_with_meeting_url_appears_for_student_and_tutor(): void
    {
        $nowIst = Carbon::now('Asia/Kolkata');
        $today = $nowIst->toDateString();

        $session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Kubernetes Live Lab',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/98765432101',
            'meeting_id' => '987 6543 2101',
            'scheduled_date' => $today,
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Student endpoint returns meeting URL
        $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson('/api/student/class-sessions/today')
            ->assertOk()
            ->assertJsonPath('0.id', $session->id)
            ->assertJsonPath('0.meeting_url', 'https://zoom.us/j/98765432101');

        // Tutor endpoint returns meeting URL
        $this->actingAs($this->tutor, 'sanctum')
            ->getJson('/api/tutor/class-sessions/today')
            ->assertOk()
            ->assertJsonPath('0.id', $session->id)
            ->assertJsonPath('0.meeting_url', 'https://zoom.us/j/98765432101');

        // Student can join and receives meeting_url
        $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/student/class-sessions/{$session->id}/join")
            ->assertOk()
            ->assertJsonPath('meeting_url', 'https://zoom.us/j/98765432101');
    }

    /**
     * Test 2: Session without meeting URL -> Session has null meeting_url and join returns 400.
     */
    public function test_session_without_meeting_url_has_no_meeting_link(): void
    {
        $nowIst = Carbon::now('Asia/Kolkata');
        $today = $nowIst->toDateString();

        $session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Tentative Microservices Discussion',
            'platform' => 'zoom',
            'meeting_url' => null,
            'scheduled_date' => $today,
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Student endpoint returns null for meeting_url
        $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson('/api/student/class-sessions/today')
            ->assertOk()
            ->assertJsonPath('0.id', $session->id)
            ->assertJsonPath('0.meeting_url', null);

        // Tutor endpoint returns null for meeting_url
        $this->actingAs($this->tutor, 'sanctum')
            ->getJson('/api/tutor/class-sessions/today')
            ->assertOk()
            ->assertJsonPath('0.id', $session->id)
            ->assertJsonPath('0.meeting_url', null);

        // Student join returns 400 with message
        $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/student/class-sessions/{$session->id}/join")
            ->assertStatus(400)
            ->assertJson(['message' => 'No meeting URL has been configured for this class session.']);
    }

    /**
     * Test 3: Admin changes or removes URL -> Student & Tutor dashboard APIs immediately reflect the change.
     */
    public function test_admin_updating_or_removing_meeting_url_reflects_immediately(): void
    {
        $nowIst = Carbon::now('Asia/Kolkata');
        $today = $nowIst->toDateString();

        $session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Terraform Orchestration',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/11122233344',
            'scheduled_date' => $today,
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Verify initial URL
        $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson('/api/student/class-sessions/today')
            ->assertJsonPath('0.meeting_url', 'https://zoom.us/j/11122233344');

        // Admin updates URL to Google Meet
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/class-sessions/{$session->id}", [
                'meeting_url' => 'https://meet.google.com/abc-defg-hij',
            ])
            ->assertOk()
            ->assertJsonPath('session.meeting_url', 'https://meet.google.com/abc-defg-hij');

        // Student immediately sees new Google Meet URL
        $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson('/api/student/class-sessions/today')
            ->assertJsonPath('0.meeting_url', 'https://meet.google.com/abc-defg-hij');

        // Admin removes URL (clears it to null)
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/class-sessions/{$session->id}", [
                'meeting_url' => null,
            ])
            ->assertOk()
            ->assertJsonPath('session.meeting_url', null);

        // Student dashboard reflects null meeting URL
        $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson('/api/student/class-sessions/today')
            ->assertJsonPath('0.meeting_url', null);
    }

    /**
     * Test 4: Unauthorized user cannot access another class's meeting URL or join.
     */
    public function test_unauthorized_user_cannot_access_or_join_another_class(): void
    {
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        $session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Confidential Cloud Architecture Workshop',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/55566677788',
            'scheduled_date' => $today,
            'start_time' => '18:00',
            'end_time' => '19:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Unenrolled student cannot show session details
        $this->actingAs($this->unenrolledStudent, 'sanctum')
            ->getJson("/api/student/class-sessions/{$session->id}")
            ->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized. You are not enrolled in the course for this session.']);

        // Unenrolled student cannot join session
        $this->actingAs($this->unenrolledStudent, 'sanctum')
            ->postJson("/api/student/class-sessions/{$session->id}/join")
            ->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized. You are not enrolled in this course.']);

        // Unrelated tutor cannot join session
        $this->actingAs($this->unrelatedTutor, 'sanctum')
            ->postJson("/api/tutor/class-sessions/{$session->id}/join")
            ->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized. You are not the assigned tutor for this class session.']);

        // Unrelated tutor does not see session in today roster
        $this->actingAs($this->unrelatedTutor, 'sanctum')
            ->getJson('/api/tutor/class-sessions/today')
            ->assertOk()
            ->assertJsonCount(0);
    }
}
