<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\LiveKit\LiveKitTokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClassSessionLiveKitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $assignedTutor;
    private User $unassignedTutor;
    private User $enrolledStudent;
    private User $unEnrolledStudent;
    private Course $course;
    private Batch $batch;
    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin.livekit@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->assignedTutor = User::factory()->create([
            'name' => 'Tutor Alice',
            'email' => 'tutor.alice@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->unassignedTutor = User::factory()->create([
            'name' => 'Tutor Bob',
            'email' => 'tutor.bob@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->enrolledStudent = User::factory()->create([
            'name' => 'Student Enrolled',
            'email' => 'student.enrolled@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->unEnrolledStudent = User::factory()->create([
            'name' => 'Student Other',
            'email' => 'student.other@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->course = Course::create([
            'title' => 'Kubernetes & WebRTC Cloud Masterclass',
            'slug' => 'k8s-webrtc-masterclass',
            'code' => 'K8S',
            'description' => 'Realtime Cloud Systems',
            'instructor' => 'Tutor Alice',
            'instructor_id' => $this->assignedTutor->id,
            'duration' => '12 Weeks',
            'difficulty' => 'Advanced',
            'price' => 499.00,
            'is_published' => true,
        ]);

        $this->batch = Batch::create([
            'name' => 'Batch 2026-A',
            'code' => 'RIT(K8S)BC230826',
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'start_date' => now()->toDateString(),
            'status' => 'ongoing',
        ]);

        // Student Enrollment
        CourseEnrollment::create([
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        BatchStudent::create([
            'batch_id' => $this->batch->id,
            'user_id' => $this->enrolledStudent->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Class Session
        $this->session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'title' => 'LiveKit SFU Deep Dive',
            'description' => 'Interactive Classroom',
            'platform' => 'zoom',
            'scheduled_date' => Carbon::now('Asia/Kolkata')->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);
    }

    protected function resetSanctumGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_enrolled_student_can_request_livekit_token_for_class_session(): void
    {
        $token = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'ws_url',
                'room_name',
                'session' => ['id', 'title', 'status', 'scheduled_date', 'start_time', 'course', 'tutor'],
                'is_host',
                'role',
                'can_publish',
            ]);

        $this->assertEquals("masterintech-session-{$this->session->id}", $response->json('room_name'));
        $this->assertFalse($response->json('is_host'));
        $this->assertEquals('participant', $response->json('role'));
        $this->assertFalse($response->json('can_publish'));

        // Decode JWT
        $jwt = $response->json('token');
        $payload = LiveKitTokenService::decodeJwt($jwt);
        $this->assertNotNull($payload);
        $this->assertEquals("masterintech-session-{$this->session->id}", $payload['video']['room']);
        $this->assertFalse($payload['video']['canPublish']);
        $this->assertTrue($payload['video']['canSubscribe']);
        $this->assertFalse($payload['video']['roomAdmin']);

        // Check attendance record created
        $this->assertDatabaseHas('class_session_attendances', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'status' => 'present',
        ]);
    }

    public function test_unenrolled_student_is_rejected_from_class_session(): void
    {
        $token = $this->unEnrolledStudent->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(403);
    }

    public function test_assigned_tutor_can_request_host_token_and_update_status(): void
    {
        $token = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(200);
        $this->assertTrue($response->json('is_host'));
        $this->assertEquals('host', $response->json('role'));
        $this->assertTrue($response->json('can_publish'));

        $jwt = $response->json('token');
        $payload = LiveKitTokenService::decodeJwt($jwt);
        $this->assertNotNull($payload);
        $this->assertTrue($payload['video']['canPublish']);
        $this->assertTrue($payload['video']['roomAdmin']);
        $this->assertTrue($payload['video']['roomCreate']);

        // Tutor updates status to active
        $statusRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-status", [
                'livekit_status' => 'active',
            ]);

        $statusRes->assertStatus(200);
        $this->assertEquals('active', $this->session->fresh()->livekit_status);
    }

    public function test_unassigned_tutor_is_rejected(): void
    {
        $token = $this->unassignedTutor->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(403);
    }

    public function test_admin_can_join_any_class_session(): void
    {
        $token = $this->admin->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(200);
        $this->assertTrue($response->json('is_host'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson("/api/class-sessions/{$this->session->id}/livekit-token");
        $response->assertStatus(401);
    }

    public function test_student_cannot_join_session_before_it_starts(): void
    {
        $futureSession = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'title' => 'Future LiveKit Session',
            'description' => 'Not yet open',
            'platform' => 'livekit',
            'scheduled_date' => Carbon::now('Asia/Kolkata')->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $token = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$futureSession->id}/livekit-token");

        $response->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'This class session has not started yet. Please join once the session is live.',
            ]);

        // No attendance should be recorded for an early-join attempt
        $this->assertDatabaseMissing('class_session_attendances', [
            'class_session_id' => $futureSession->id,
            'user_id' => $this->enrolledStudent->id,
        ]);
    }

    public function test_admin_and_tutor_can_join_session_before_it_starts(): void
    {
        $futureSession = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'title' => 'Future Host Prep Session',
            'description' => 'Hosts may prepare early',
            'platform' => 'livekit',
            'scheduled_date' => Carbon::now('Asia/Kolkata')->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;
        $tutorResponse = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/class-sessions/{$futureSession->id}/livekit-token");
        $tutorResponse->assertStatus(200)->assertJson(['is_host' => true]);

        $adminToken = $this->admin->startNewActiveSession()->plainTextToken;
        $adminResponse = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/class-sessions/{$futureSession->id}/livekit-token");
        $adminResponse->assertStatus(200)->assertJson(['is_host' => true]);
    }

    public function test_single_active_session_revocation_enforced(): void
    {
        $tokenA = $this->enrolledStudent->startNewActiveSession('Device A')->plainTextToken;

        $resA = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");
        $resA->assertStatus(200);

        $this->resetSanctumGuard();

        // Device B logs in
        $tokenB = $this->enrolledStudent->startNewActiveSession('Device B')->plainTextToken;

        $this->resetSanctumGuard();

        // Device A is revoked
        $resRevoked = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");
        $resRevoked->assertStatus(401)->assertJson(['code' => 'SESSION_REVOKED']);

        $this->resetSanctumGuard();

        // Device B succeeds
        $resB = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");
        $resB->assertStatus(200);
    }
}
