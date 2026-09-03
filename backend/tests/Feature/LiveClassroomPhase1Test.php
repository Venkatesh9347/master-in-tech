<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Course;
use App\Models\LiveClassroomParticipant;
use App\Models\LiveClassroomSession;
use App\Models\User;
use App\Services\LiveKit\LiveKitTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LiveClassroomPhase1Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $assignedTutor;
    private User $unassignedTutor;
    private User $enrolledStudent;
    private User $otherBatchStudent;
    private Course $course;
    private Batch $batchA;
    private Batch $batchB;
    private LiveClassroomSession $sessionA;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Admin
        $this->admin = User::factory()->create([
            'name' => 'Admin Boss',
            'email' => 'admin@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        // 2. Assigned Tutor
        $this->assignedTutor = User::factory()->create([
            'name' => 'Tutor Alice',
            'email' => 'tutor.alice@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        // 3. Unassigned Tutor
        $this->unassignedTutor = User::factory()->create([
            'name' => 'Tutor Bob',
            'email' => 'tutor.bob@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        // 4. Enrolled Student in Batch A
        $this->enrolledStudent = User::factory()->create([
            'name' => 'Student Charlie',
            'email' => 'charlie@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        // 5. Student in Batch B only
        $this->otherBatchStudent = User::factory()->create([
            'name' => 'Student Dave',
            'email' => 'dave@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        // 6. Course
        $this->course = Course::create([
            'title' => 'Mastering WebRTC & Cloud Architecture',
            'slug' => 'webrtc-cloud-architecture',
            'code' => 'WEBRTC',
            'description' => 'Hands-on Realtime Systems',
            'instructor' => 'Tutor Alice',
            'instructor_id' => $this->assignedTutor->id,
            'duration' => '10 Weeks',
            'difficulty' => 'Advanced',
            'price' => 299.00,
            'is_published' => true,
        ]);

        // 7. Batches
        $this->batchA = Batch::create([
            'name' => 'Cohort Alpha',
            'code' => 'RIT(WEBRTC)BC230826-01',
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'start_date' => now()->toDateString(),
            'status' => 'ongoing',
        ]);

        $this->batchB = Batch::create([
            'name' => 'Cohort Beta',
            'code' => 'RIT(WEBRTC)BC230826-02',
            'course_id' => $this->course->id,
            'tutor_id' => $this->unassignedTutor->id,
            'start_date' => now()->toDateString(),
            'status' => 'ongoing',
        ]);

        // 8. Enrollments
        BatchStudent::create([
            'batch_id' => $this->batchA->id,
            'user_id' => $this->enrolledStudent->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        BatchStudent::create([
            'batch_id' => $this->batchB->id,
            'user_id' => $this->otherBatchStudent->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // 9. Live Classroom Session for Batch A
        $this->sessionA = LiveClassroomSession::create([
            'room_id' => 'mit-room-alpha-001',
            'batch_id' => $this->batchA->id,
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'title' => 'WebRTC SFU Architecture Deep Dive',
            'description' => 'Phase 1 Live Interactive Classroom',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Helper to authenticate with single active session token.
     */
    protected function authenticate(User $user): string
    {
        $tokenObj = $user->startNewActiveSession('test_token');
        return $tokenObj->plainTextToken;
    }

    public function test_authorized_student_can_request_a_room_token(): void
    {
        $token = $this->authenticate($this->enrolledStudent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'ws_url',
                'room_id',
                'session' => ['id', 'room_id', 'title', 'status', 'batch', 'course', 'tutor'],
                'is_host',
                'role',
                'can_publish',
                'is_mic_allowed',
                'is_camera_allowed',
            ]);

        $this->assertFalse($response->json('is_host'));
        $this->assertEquals('participant', $response->json('role'));
        $this->assertFalse($response->json('can_publish'));
        $this->assertFalse($response->json('is_mic_allowed'));
        $this->assertFalse($response->json('is_camera_allowed'));

        // Decode LiveKit token payload and verify grants
        $jwtToken = $response->json('token');
        $payload = LiveKitTokenService::decodeJwt($jwtToken);

        $this->assertNotNull($payload);
        $this->assertEquals('devkey', $payload['iss']);
        $this->assertEquals("user-{$this->enrolledStudent->id}", $payload['sub']);
        $this->assertEquals($this->sessionA->room_id, $payload['video']['room']);
        $this->assertTrue($payload['video']['roomJoin']);
        $this->assertFalse($payload['video']['canPublish']); // Mic/Camera initially disabled
        $this->assertTrue($payload['video']['canSubscribe']);
        $this->assertFalse($payload['video']['roomAdmin']);

        // Verify participant entry recorded in DB
        $this->assertDatabaseHas('live_classroom_participants', [
            'live_classroom_session_id' => $this->sessionA->id,
            'user_id' => $this->enrolledStudent->id,
            'role' => 'participant',
        ]);
    }

    public function test_student_from_another_batch_is_rejected(): void
    {
        $token = $this->authenticate($this->otherBatchStudent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");

        $response->assertStatus(403);
    }

    public function test_assigned_tutor_can_request_host_token(): void
    {
        $token = $this->authenticate($this->assignedTutor);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");

        $response->assertStatus(200);
        $this->assertTrue($response->json('is_host'));
        $this->assertEquals('host', $response->json('role'));
        $this->assertTrue($response->json('can_publish'));

        $jwtToken = $response->json('token');
        $payload = LiveKitTokenService::decodeJwt($jwtToken);

        $this->assertNotNull($payload);
        $this->assertEquals("user-{$this->assignedTutor->id}", $payload['sub']);
        $this->assertTrue($payload['video']['canPublish']);
        $this->assertTrue($payload['video']['roomAdmin']);
        $this->assertTrue($payload['video']['roomCreate']);
    }

    public function test_unrelated_tutor_is_rejected(): void
    {
        $token = $this->authenticate($this->unassignedTutor);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $response = $this->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");
        $response->assertStatus(401);
    }

    public function test_admin_can_manage_the_session(): void
    {
        $token = $this->authenticate($this->admin);

        // 1. Admin creates a session
        $createRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/live-classroom/sessions', [
                'batch_id' => $this->batchA->id,
                'tutor_id' => $this->assignedTutor->id,
                'title' => 'Special Admin Hands-On Session',
                'description' => 'Real-time infrastructure tuning',
                'scheduled_date' => now()->addDay()->toDateString(),
                'start_time' => '14:00',
                'end_time' => '16:00',
            ]);

        $createRes->assertStatus(201);
        $newSessionId = $createRes->json('session.id');
        $this->assertNotNull($newSessionId);
        $this->assertNotNull($createRes->json('session.room_id'));

        // 2. Admin gets token for the newly created session
        $tokenRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/live-classroom/sessions/{$newSessionId}/token");

        $tokenRes->assertStatus(200);
        $this->assertTrue($tokenRes->json('is_host'));

        // 3. Admin updates the session
        $updateRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/admin/live-classroom/sessions/{$newSessionId}", [
                'title' => 'Updated Session Title',
            ]);

        $updateRes->assertStatus(200);
        $this->assertEquals('Updated Session Title', $updateRes->json('session.title'));

        // 4. Admin deletes the session
        $deleteRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/admin/live-classroom/sessions/{$newSessionId}");

        $deleteRes->assertStatus(200);
        $this->assertDatabaseMissing('live_classroom_sessions', ['id' => $newSessionId]);
    }

    protected function resetSanctumGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_existing_single_active_session_behavior_remains_intact(): void
    {
        // 1. Student logs in on Device A
        $tokenA = $this->enrolledStudent->startNewActiveSession('Device A')->plainTextToken;

        // Device A requests token -> should succeed
        $resA = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");
        $resA->assertStatus(200);

        $this->resetSanctumGuard();

        // 2. Student logs in on Device B (revoking Device A's active session)
        $tokenB = $this->enrolledStudent->startNewActiveSession('Device B')->plainTextToken;

        $this->resetSanctumGuard();

        // 3. Device A attempts again with revoked session -> MUST return 401 SESSION_REVOKED
        $resRevoked = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");
        $resRevoked->assertStatus(401)
            ->assertJson([
                'code' => 'SESSION_REVOKED',
            ]);

        $this->resetSanctumGuard();

        // 4. Device B succeeds
        $resB = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");
        $resB->assertStatus(200);
    }

    public function test_tutor_can_start_and_end_session(): void
    {
        $token = $this->authenticate($this->assignedTutor);

        // Start session
        $startRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/start");

        $startRes->assertStatus(200)
            ->assertJsonPath('session.status', 'live');

        $this->assertEquals('live', $this->sessionA->fresh()->status);

        // End session
        $endRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/end");

        $endRes->assertStatus(200)
            ->assertJsonPath('session.status', 'completed');

        $this->assertEquals('completed', $this->sessionA->fresh()->status);
    }

    public function test_cancelled_session_cannot_be_joined(): void
    {
        $this->sessionA->update(['status' => 'cancelled']);

        $token = $this->authenticate($this->enrolledStudent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/live-classroom/sessions/{$this->sessionA->id}/token");

        $response->assertStatus(400)
            ->assertJsonFragment([
                'message' => 'This live classroom session has been cancelled and cannot be joined.',
            ]);
    }
}
