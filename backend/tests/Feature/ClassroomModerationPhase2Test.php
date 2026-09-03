<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\ClassroomMessage;
use App\Models\ClassroomParticipant;
use App\Models\ClassroomPermissionRequest;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClassroomModerationPhase2Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $assignedTutor;
    private User $enrolledStudent;
    private User $otherStudent;
    private Course $course;
    private Batch $batch;
    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Admin Host',
            'email' => 'admin.host@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->assignedTutor = User::factory()->create([
            'name' => 'Tutor Dave',
            'email' => 'tutor.dave@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->enrolledStudent = User::factory()->create([
            'name' => 'Student Charlie',
            'email' => 'student.charlie@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->otherStudent = User::factory()->create([
            'name' => 'Student Eve',
            'email' => 'student.eve@masterintech.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->course = Course::create([
            'title' => 'Advanced Realtime WebRTC Cloud',
            'slug' => 'adv-webrtc-cloud',
            'code' => 'RTC',
            'description' => 'Realtime Classroom systems',
            'instructor' => 'Tutor Dave',
            'instructor_id' => $this->assignedTutor->id,
            'duration' => '8 Weeks',
            'difficulty' => 'Advanced',
            'price' => 299.00,
            'is_published' => true,
        ]);

        $this->batch = Batch::create([
            'name' => 'Batch RTC-01',
            'code' => 'RIT(RTC)BC01',
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'start_date' => now()->toDateString(),
            'status' => 'ongoing',
        ]);

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

        $this->session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->assignedTutor->id,
            'title' => 'Phase 2 Interaction & Moderation',
            'description' => 'Live moderation masterclass',
            'platform' => 'livekit',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '14:00',
            'end_time' => '15:30',
            'status' => 'scheduled',
            'is_chat_enabled' => false,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function resetSanctumGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_admin_acts_as_initial_host_and_can_transfer_to_tutor(): void
    {
        $adminToken = $this->admin->startNewActiveSession()->plainTextToken;

        // Admin gets state
        $stateRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson("/api/classrooms/{$this->session->id}/state");

        $stateRes->assertStatus(200);
        $this->assertTrue($stateRes->json('is_host'));

        // Admin transfers host to Tutor Dave
        $transferRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/classrooms/{$this->session->id}/transfer-host", [
                'target_user_id' => $this->assignedTutor->id,
            ]);

        $transferRes->assertStatus(200);
        $this->assertEquals($this->assignedTutor->id, $this->session->fresh()->current_host_id);

        $this->assertDatabaseHas('classroom_moderation_events', [
            'class_session_id' => $this->session->id,
            'actor_id' => $this->admin->id,
            'target_user_id' => $this->assignedTutor->id,
            'action' => 'transfer_host',
        ]);
    }

    public function test_tutor_with_host_privileges_can_mute_and_disable_student_camera(): void
    {
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        // Create student participant
        $studentParticipant = ClassroomParticipant::create([
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'role' => 'participant',
            'is_mic_allowed' => true,
            'is_camera_allowed' => true,
        ]);

        // Tutor mutes student
        $muteRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/moderation/mic", [
                'target_user_id' => $this->enrolledStudent->id,
                'is_mic_allowed' => false,
            ]);

        $muteRes->assertStatus(200);
        $this->assertFalse($studentParticipant->fresh()->is_mic_allowed);

        // Tutor disables student camera
        $camRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/moderation/camera", [
                'target_user_id' => $this->enrolledStudent->id,
                'is_camera_allowed' => false,
            ]);

        $camRes->assertStatus(200);
        $this->assertFalse($studentParticipant->fresh()->is_camera_allowed);
    }

    public function test_unauthorized_student_cannot_perform_moderation_actions(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        // Student attempts to mute another user
        $res = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/moderation/mic", [
                'target_user_id' => $this->assignedTutor->id,
                'is_mic_allowed' => false,
            ]);

        $res->assertStatus(403);
    }

    public function test_student_can_raise_hand_and_duplicate_is_prevented(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        // First hand raise
        $raiseRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");

        $raiseRes->assertStatus(200)
            ->assertJsonStructure(['message', 'request' => ['id', 'status', 'type']]);

        $this->assertDatabaseHas('classroom_permission_requests', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'is_hand_raised' => true,
        ]);

        // Duplicate hand raise attempt
        $dupRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");

        $dupRes->assertStatus(400);
    }

    public function test_student_can_lower_hand(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");

        $lowerRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/lower-hand");

        $lowerRes->assertStatus(200);

        $this->assertDatabaseHas('classroom_permission_requests', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'is_hand_raised' => false,
        ]);
    }

    public function test_host_can_approve_hand_raise_granting_mic_permission(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        // Student raises hand
        $raiseRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");
        $requestId = $raiseRes->json('request.id');

        $this->resetSanctumGuard();

        // Tutor approves request
        $approveRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/requests/{$requestId}/resolve", [
                'action' => 'approve',
            ]);

        $approveRes->assertStatus(200);

        $this->assertDatabaseHas('classroom_permission_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'resolved_by' => $this->assignedTutor->id,
        ]);

        // Student now has mic enabled
        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'is_mic_allowed' => true,
            'is_hand_raised' => false,
        ]);
    }

    public function test_host_can_deny_hand_raise(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        $raiseRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");
        $requestId = $raiseRes->json('request.id');

        $this->resetSanctumGuard();

        // Tutor denies request
        $denyRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/requests/{$requestId}/resolve", [
                'action' => 'deny',
            ]);

        $denyRes->assertStatus(200);

        $this->assertDatabaseHas('classroom_permission_requests', [
            'id' => $requestId,
            'status' => 'denied',
            'resolved_by' => $this->assignedTutor->id,
        ]);

        // Student mic remains restricted
        $this->assertDatabaseHas('classroom_participants', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->enrolledStudent->id,
            'is_mic_allowed' => false,
            'is_hand_raised' => false,
        ]);
    }

    public function test_student_chat_enforcement_when_disabled_and_enabled(): void
    {
        $studentToken = $this->enrolledStudent->startNewActiveSession()->plainTextToken;
        $tutorToken = $this->assignedTutor->startNewActiveSession()->plainTextToken;

        // By default session is_chat_enabled = false
        $blockedRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/messages", [
                'message' => 'Hello everyone!',
            ]);

        $blockedRes->assertStatus(403);

        $this->resetSanctumGuard();

        // Tutor can always chat even when disabled for students
        $tutorChatRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/messages", [
                'message' => 'Welcome to class!',
            ]);
        $tutorChatRes->assertStatus(201);

        $this->resetSanctumGuard();

        // Tutor enables chat for students
        $enableRes = $this->withHeader('Authorization', 'Bearer ' . $tutorToken)
            ->postJson("/api/classrooms/{$this->session->id}/moderation/chat-toggle", [
                'is_chat_enabled' => true,
            ]);
        $enableRes->assertStatus(200);

        $this->resetSanctumGuard();

        // Student now succeeds
        $studentChatRes = $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/classrooms/{$this->session->id}/messages", [
                'message' => 'Thank you instructor!',
            ]);
        $studentChatRes->assertStatus(201);
    }

    public function test_cross_batch_student_rejected_from_moderation_and_chat(): void
    {
        $otherStudentToken = $this->otherStudent->startNewActiveSession()->plainTextToken;

        // Rejected from state
        $stateRes = $this->withHeader('Authorization', 'Bearer ' . $otherStudentToken)
            ->getJson("/api/classrooms/{$this->session->id}/state");
        $stateRes->assertStatus(403);

        // Rejected from raise hand
        $raiseRes = $this->withHeader('Authorization', 'Bearer ' . $otherStudentToken)
            ->postJson("/api/classrooms/{$this->session->id}/raise-hand");
        $raiseRes->assertStatus(403);

        // Rejected from chat
        $chatRes = $this->withHeader('Authorization', 'Bearer ' . $otherStudentToken)
            ->postJson("/api/classrooms/{$this->session->id}/messages", [
                'message' => 'Intruder message',
            ]);
        $chatRes->assertStatus(403);
    }
}
