<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\ClassSession;
use App\Models\ClassSessionAttendance;
use App\Models\ClassroomModerationEvent;
use App\Models\ClassroomParticipant;
use App\Models\ClassroomPermissionRequest;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClassroomPhase3ComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $assignedTutor;
    protected User $otherTutor;
    protected User $enrolledStudent;
    protected User $otherBatchStudent;
    protected User $unauthorizedStudent;
    protected Course $course;
    protected Batch $batch1;
    protected Batch $batch2;
    protected ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'name' => 'Platform Admin',
            'email' => 'admin@masterintech.test',
            'password' => Hash::make('password123'),
        ]);

        $this->assignedTutor = User::factory()->create([
            'role' => 'tutor',
            'status' => 'active',
            'name' => 'Assigned Tutor',
            'email' => 'tutor1@masterintech.test',
            'password' => Hash::make('password123'),
        ]);

        $this->otherTutor = User::factory()->create([
            'role' => 'tutor',
            'status' => 'active',
            'name' => 'Unassigned Tutor',
            'email' => 'tutor2@masterintech.test',
            'password' => Hash::make('password123'),
        ]);

        $this->enrolledStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'name' => 'Enrolled Student',
            'email' => 'student1@masterintech.test',
            'password' => Hash::make('password123'),
        ]);

        $this->otherBatchStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'name' => 'Other Batch Student',
            'email' => 'student2@masterintech.test',
            'password' => Hash::make('password123'),
        ]);

        $this->unauthorizedStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'name' => 'Unauthorized Student',
            'email' => 'student3@masterintech.test',
            'password' => Hash::make('password123'),
        ]);

        $this->course = Course::create([
            'title' => 'Advanced Cloud Architecture',
            'slug' => 'adv-cloud-arch',
            'code' => 'ACA',
            'description' => 'Scalable systems engineering',
            'instructor' => 'Assigned Tutor',
            'instructor_id' => $this->assignedTutor->id,
            'duration' => '12 Weeks',
            'difficulty' => 'Advanced',
            'price' => 499.00,
            'is_published' => true,
        ]);

        $this->batch1 = Batch::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'name' => 'Batch ACA-01',
            'code' => 'ACA-01',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
            'max_students' => 50,
            'status' => 'active',
        ]);

        $this->batch2 = Batch::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->otherTutor->id,
            'name' => 'Batch ACA-02',
            'code' => 'ACA-02',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
            'max_students' => 50,
            'status' => 'active',
        ]);

        // Enroll Student 1 in Course & Batch 1
        CourseEnrollment::create([
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        BatchStudent::create([
            'batch_id' => $this->batch1->id,
            'user_id' => $this->enrolledStudent->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Enroll Student 2 in Course & Batch 2
        CourseEnrollment::create([
            'user_id' => $this->otherBatchStudent->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        BatchStudent::create([
            'batch_id' => $this->batch2->id,
            'user_id' => $this->otherBatchStudent->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Today's Scheduled Class Session for Batch 1 & Assigned Tutor
        $today = Carbon::now('Asia/Kolkata')->toDateString();
        $this->session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'title' => 'Microservices WebRTC Lecture',
            'description' => 'Realtime streaming architectures in production',
            'scheduled_date' => $today,
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'status' => 'scheduled',
            'platform' => 'livekit',
            'livekit_room_name' => 'masterintech-session-1',
            'livekit_status' => 'idle',
            'is_chat_enabled' => false,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function resetSanctumGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** 1. Admin Token */
    public function test_1_admin_token_generation_and_host_privileges(): void
    {
        $token = $this->admin->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'ws_url', 'room_name', 'is_host', 'can_publish'])
            ->assertJson([
                'is_host' => true,
                'role' => 'host',
                'can_publish' => true,
            ]);
    }

    /** 2. Tutor Token */
    public function test_2_assigned_tutor_token_generation_and_host_role(): void
    {
        $token = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(200)
            ->assertJson([
                'is_host' => true,
                'role' => 'host',
                'can_publish' => true,
            ]);
    }

    /** 3. Student Token */
    public function test_3_enrolled_student_token_generation_with_restricted_mic(): void
    {
        $token = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(200)
            ->assertJson([
                'is_host' => false,
                'role' => 'participant',
                'can_publish' => false,
            ]);

        // Attendance recorded
        $this->assertDatabaseHas('class_session_attendances', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'status' => 'present',
        ]);
    }

    /** 4. Unauthorized Student */
    public function test_4_unauthorized_student_is_rejected(): void
    {
        $token = $this->unauthorizedStudent->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(403);
    }

    /** 5. Student from Wrong Batch */
    public function test_5_student_from_wrong_batch_is_rejected(): void
    {
        $token = $this->otherBatchStudent->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(403);
    }

    /** 6. Tutor Assigned to Another Class */
    public function test_6_unassigned_tutor_is_rejected(): void
    {
        $token = $this->otherTutor->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(403);
    }

    /** 7. Host Transfer */
    public function test_7_admin_can_transfer_host_to_assigned_tutor(): void
    {
        $token = $this->admin->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/classrooms/{$this->session->id}/transfer-host", [
                'target_user_id' => $this->assignedTutor->id,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('class_sessions', [
            'id' => $this->session->id,
            'current_host_id' => $this->assignedTutor->id,
        ]);

        $this->assertDatabaseHas('classroom_moderation_events', [
            'class_session_id' => $this->session->id,
            'actor_id' => $this->admin->id,
            'target_user_id' => $this->assignedTutor->id,
            'action' => 'transfer_host',
        ]);
    }

    /** 8. Admin Leaving After Transfer */
    public function test_8_admin_leaving_after_transfer_preserves_session_with_tutor_as_host(): void
    {
        $this->session->update(['current_host_id' => $this->assignedTutor->id]);

        $adminToken = $this->admin->startNewActiveSession()->plainTextToken;
        $leaveRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/classrooms/{$this->session->id}/leave");
        $leaveRes->assertStatus(200);

        $this->resetSanctumGuard();

        // Tutor continues to be host
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;
        $stateRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->getJson("/api/classrooms/{$this->session->id}/state");
        $stateRes->assertStatus(200)
            ->assertJson([
                'is_host' => true,
            ]);
    }

    /** 9. Student Raise Hand */
    public function test_9_student_raise_hand_with_duplicate_prevention(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        $res1 = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");
        $res1->assertStatus(200)->assertJson([
            'request' => [
                'type' => 'speak',
                'status' => 'pending',
            ],
        ]);

        // Duplicate attempt blocked
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");
        $res2->assertStatus(400);
    }

    /** 10. Tutor Approval */
    public function test_10_tutor_approval_unlocks_student_microphone(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        $raiseRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");
        $requestId = $raiseRes->json('request.id');

        $this->resetSanctumGuard();

        $approveRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/requests/{$requestId}/resolve", [
                'action' => 'approve',
            ]);

        $approveRes->assertStatus(200);

        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'is_mic_allowed' => true,
            'is_hand_raised' => false,
        ]);
    }

    /** 11. Tutor Rejection */
    public function test_11_tutor_rejection_keeps_student_microphone_restricted(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        $raiseRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");
        $requestId = $raiseRes->json('request.id');

        $this->resetSanctumGuard();

        $denyRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/requests/{$requestId}/resolve", [
                'action' => 'deny',
            ]);

        $denyRes->assertStatus(200);

        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'is_mic_allowed' => false,
            'is_hand_raised' => false,
        ]);
    }

    /** 12. Chat Permission */
    public function test_12_chat_permission_strictly_enforced_server_side(): void
    {
        $this->session->update(['is_chat_enabled' => false]);

        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        $blockedRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/messages", [
                'message' => 'Can anyone hear me?',
            ]);
        $blockedRes->assertStatus(403);

        $this->resetSanctumGuard();

        // Tutor sends message even with student chat disabled
        $tutorMsgRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/messages", [
                'message' => 'Welcome students to today lecture.',
            ]);
        $tutorMsgRes->assertStatus(201);
    }

    /** 13. Student Microphone Permission Mute/Unmute */
    public function test_13_student_mic_locked_and_host_can_mute_unmute(): void
    {
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        // Host enables mic
        $unmuteRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/moderation/mic", [
                'target_user_id' => $this->enrolledStudent->id,
                'is_mic_allowed' => true,
            ]);
        $unmuteRes->assertStatus(200);

        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'is_mic_allowed' => true,
        ]);

        $this->resetSanctumGuard();

        // Host mutes mic
        $muteRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/moderation/mic", [
                'target_user_id' => $this->enrolledStudent->id,
                'is_mic_allowed' => false,
            ]);
        $muteRes->assertStatus(200);

        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'is_mic_allowed' => false,
        ]);
    }

    /** 14. Participant Removal */
    public function test_14_host_can_remove_participant_from_classroom(): void
    {
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        $removeRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/remove-participant", [
                'target_user_id' => $this->enrolledStudent->id,
            ]);

        $removeRes->assertStatus(200);

        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'connection_state' => 'removed',
            'is_mic_allowed' => false,
            'is_camera_allowed' => false,
        ]);

        $this->assertDatabaseHas('classroom_moderation_events', [
            'class_session_id' => $this->session->id,
            'actor_id' => $this->assignedTutor->id,
            'target_user_id' => $this->enrolledStudent->id,
            'action' => 'remove_participant',
        ]);
    }

    /** 15. Class Expiration */
    public function test_15_expired_class_rejects_student_join(): void
    {
        $this->session->update([
            'scheduled_date' => Carbon::now('Asia/Kolkata')->subDays(2)->toDateString(),
            'status' => 'completed',
        ]);

        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        $response->assertStatus(400);
    }

    /** 16. Attendance and Duration */
    public function test_16_attendance_and_duration_recorded_on_leave(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        // Student joins and generates token
        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/class-sessions/{$this->session->id}/livekit-token");

        // Manually adjust joined_at to simulate 30 minutes in class
        ClassSessionAttendance::where('class_session_id', $this->session->id)
            ->where('user_id', $this->enrolledStudent->id)
            ->update(['joined_at' => now()->subMinutes(30)]);

        // Student leaves
        $leaveRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/class-sessions/{$this->session->id}/leave");
        $leaveRes->assertStatus(200);

        $attendance = ClassSessionAttendance::where('class_session_id', $this->session->id)
            ->where('user_id', $this->enrolledStudent->id)
            ->first();

        $this->assertNotNull($attendance->left_at);
        $this->assertGreaterThanOrEqual(1800, $attendance->duration_seconds);
    }

    /** 17. Class History Preservation */
    public function test_17_concluded_class_preserved_in_history_with_attendance(): void
    {
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        $endRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/end");
        $endRes->assertStatus(200);

        $this->assertDatabaseHas('class_sessions', [
            'id' => $this->session->id,
            'status' => 'completed',
            'livekit_status' => 'ended',
        ]);

        $this->assertDatabaseHas('classroom_moderation_events', [
            'class_session_id' => $this->session->id,
            'actor_id' => $this->assignedTutor->id,
            'action' => 'end_classroom',
        ]);
    }

    /** 18. Unauthorized Room Access */
    public function test_18_unauthorized_room_tampering_is_prevented(): void
    {
        $unauthStudentToken = $this->unauthorizedStudent->startNewActiveSession()->plainTextToken;

        // Non-enrolled student trying to access classroom state
        $stateRes = $this->withHeader('Authorization', 'Bearer ' . $unauthStudentToken)
            ->getJson("/api/classrooms/{$this->session->id}/state");
        $stateRes->assertStatus(403);

        $this->resetSanctumGuard();

        // Non-enrolled student trying to send chat
        $chatRes = $this->withHeader('Authorization', 'Bearer ' . $unauthStudentToken)
            ->postJson("/api/classrooms/{$this->session->id}/messages", [
                'message' => 'Hacking into room...',
            ]);
        $chatRes->assertStatus(403);
    }

    /** 19. Admin Creates Internal LiveKit Class (No Zoom/Teams URLs) */
    public function test_19_admin_can_create_internal_livekit_class_without_external_meeting_urls(): void
    {
        $adminToken = $this->admin->startNewActiveSession()->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/class-sessions', [
                'course_id' => $this->course->id,
                'tutor_id' => $this->assignedTutor->id,
                'title' => 'Masterclass on LiveKit SFU Architecture',
                'description' => 'Realtime WebRTC scaling and token authentication',
                'scheduled_date' => Carbon::now('Asia/Kolkata')->toDateString(),
                'start_time' => '10:00',
                'end_time' => '11:30',
                'admin_notes' => 'Faculty tutor assigned; WebRTC auto-room generated.',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'session' => [
                    'id',
                    'title',
                    'platform',
                    'livekit_room_name',
                    'livekit_status',
                ],
            ]);

        $createdSession = $response->json('session');
        $this->assertEquals('livekit', $createdSession['platform']);
        $this->assertEquals("masterintech-class-{$createdSession['id']}", $createdSession['livekit_room_name']);
        $this->assertEquals('idle', $createdSession['livekit_status']);
    }
}
