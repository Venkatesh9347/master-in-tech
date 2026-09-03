<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\LiveClassMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveClassroomTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;
    private User $enrolledStudent;
    private User $unEnrolledStudent;
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->create([
            'name' => 'Tutor Dave',
            'email' => 'tutor.dave@example.com',
            'role' => 'tutor',
        ]);

        $this->course = Course::create([
            'title' => 'Full Stack Cloud Live Masterclass',
            'slug' => 'full-stack-cloud-live',
            'description' => 'Interactive live sessions',
            'instructor' => 'Tutor Dave',
            'instructor_id' => $this->tutor->id,
            'duration' => '8 Weeks',
            'difficulty' => 'Intermediate',
            'price' => 199.00,
            'is_published' => true,
        ]);

        $this->enrolledStudent = User::factory()->create([
            'name' => 'Enrolled Alice',
            'email' => 'alice@example.com',
            'role' => 'student',
        ]);

        CourseEnrollment::create([
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 10,
        ]);

        $this->unEnrolledStudent = User::factory()->create([
            'name' => 'Unenrolled Bob',
            'email' => 'bob@example.com',
            'role' => 'student',
        ]);
    }

    public function test_tutor_can_create_live_class_for_assigned_course(): void
    {
        $response = $this->actingAs($this->tutor, 'sanctum')->postJson("/api/tutor/courses/{$this->course->id}/live-classes", [
            'title' => 'Session 1: Real-Time Microservices Architecture',
            'description' => 'Live code-along and Q&A',
            'class_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:30',
            'duration_minutes' => 90,
            'provider' => 'zoom',
            'meeting_id' => '9876543210',
            'meeting_url' => 'https://zoom.us/j/9876543210',
            'passcode' => 'MIT2026',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'live_class' => ['id', 'title', 'provider', 'meeting_id', 'status'],
            ])
            ->assertJson([
                'live_class' => [
                    'title' => 'Session 1: Real-Time Microservices Architecture',
                    'provider' => 'zoom',
                    'meeting_id' => '9876543210',
                    'status' => 'scheduled',
                    'is_mic_allowed_by_default' => false,
                ],
            ]);

        $this->assertDatabaseHas('live_classes', [
            'course_id' => $this->course->id,
            'title' => 'Session 1: Real-Time Microservices Architecture',
            'provider' => 'zoom',
        ]);
    }

    public function test_non_enrolled_student_cannot_access_course_live_classes(): void
    {
        $liveClass = LiveClass::create([
            'course_id' => $this->course->id,
            'instructor_id' => $this->tutor->id,
            'title' => 'Private Masterclass',
            'class_date' => now()->toDateString(),
            'start_time' => '14:00',
            'duration_minutes' => 60,
            'status' => 'scheduled',
        ]);

        // Attempt listing course classes
        $resList = $this->actingAs($this->unEnrolledStudent, 'sanctum')
            ->getJson("/api/courses/{$this->course->id}/live-classes");
        $resList->assertStatus(403);

        // Attempt viewing specific class details
        $resShow = $this->actingAs($this->unEnrolledStudent, 'sanctum')
            ->getJson("/api/live-classes/{$liveClass->id}");
        $resShow->assertStatus(403);

        // Attempt joining class
        $resJoin = $this->actingAs($this->unEnrolledStudent, 'sanctum')
            ->postJson("/api/live-classes/{$liveClass->id}/join");
        $resJoin->assertStatus(403);
    }

    public function test_enrolled_student_can_view_and_join_live_class_and_attendance_is_logged(): void
    {
        $liveClass = LiveClass::create([
            'course_id' => $this->course->id,
            'instructor_id' => $this->tutor->id,
            'title' => 'Live Debugging Session',
            'class_date' => now()->toDateString(),
            'start_time' => '15:00',
            'duration_minutes' => 60,
            'status' => 'live',
            'provider' => 'google_meet',
            'meeting_url' => 'https://meet.google.com/mit-live-class',
            'is_mic_allowed_by_default' => false,
        ]);

        // 1. Enrolled student views class details
        $resShow = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson("/api/live-classes/{$liveClass->id}");
        $resShow->assertStatus(200)
            ->assertJson([
                'live_class' => ['id' => $liveClass->id, 'title' => 'Live Debugging Session'],
                'launch' => [
                    'provider' => 'google_meet',
                    'meeting_url' => 'https://meet.google.com/mit-live-class',
                    'is_host' => false,
                    'is_mic_allowed_by_default' => false,
                ],
                'is_host' => false,
            ]);

        // 2. Enrolled student joins class
        $resJoin = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/live-classes/{$liveClass->id}/join");
        $resJoin->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'attendance' => ['id', 'user_id', 'status', 'is_mic_allowed', 'is_hand_raised'],
            ])
            ->assertJson([
                'attendance' => [
                    'user_id' => $this->enrolledStudent->id,
                    'status' => 'present',
                    'is_mic_allowed' => false, // Mic permission NOT automatically granted
                    'is_hand_raised' => false,
                ],
            ]);

        $this->assertDatabaseHas('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'user_id' => $this->enrolledStudent->id,
            'status' => 'present',
            'is_mic_allowed' => false,
        ]);
    }

    public function test_student_raise_hand_and_tutor_grant_mic_permission(): void
    {
        $liveClass = LiveClass::create([
            'course_id' => $this->course->id,
            'instructor_id' => $this->tutor->id,
            'title' => 'Architecture Q&A',
            'class_date' => now()->toDateString(),
            'start_time' => '16:00',
            'status' => 'live',
        ]);

        // Student joins
        $this->actingAs($this->enrolledStudent, 'sanctum')->postJson("/api/live-classes/{$liveClass->id}/join");

        // Student raises hand
        $resRaise = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/live-classes/{$liveClass->id}/raise-hand");
        $resRaise->assertStatus(200)
            ->assertJson([
                'attendance' => [
                    'is_hand_raised' => true,
                ],
            ]);

        $this->assertDatabaseHas('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'user_id' => $this->enrolledStudent->id,
            'is_hand_raised' => true,
        ]);

        // Tutor grants microphone permission
        $resAllowMic = $this->actingAs($this->tutor, 'sanctum')
            ->postJson("/api/tutor/live-classes/{$liveClass->id}/participants/{$this->enrolledStudent->id}/allow-mic");
        $resAllowMic->assertStatus(200)
            ->assertJson([
                'attendance' => [
                    'is_mic_allowed' => true,
                    'is_muted' => false,
                    'is_hand_raised' => false, // Lowered automatically when granted
                ],
            ]);

        $this->assertDatabaseHas('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'user_id' => $this->enrolledStudent->id,
            'is_mic_allowed' => true,
            'is_hand_raised' => false,
        ]);
    }

    public function test_tutor_controls_chat_and_enforces_permissions(): void
    {
        $liveClass = LiveClass::create([
            'course_id' => $this->course->id,
            'instructor_id' => $this->tutor->id,
            'title' => 'Chat Control Test',
            'class_date' => now()->toDateString(),
            'start_time' => '17:00',
            'status' => 'live',
            'is_chat_enabled' => true,
        ]);

        $this->actingAs($this->enrolledStudent, 'sanctum')->postJson("/api/live-classes/{$liveClass->id}/join");

        // 1. Student sends message when chat is enabled
        $resMsg1 = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/live-classes/{$liveClass->id}/messages", [
                'message' => 'Hello everyone!',
            ]);
        $resMsg1->assertStatus(201)
            ->assertJson([
                'message' => 'Hello everyone!',
                'user_id' => $this->enrolledStudent->id,
            ]);

        // 2. Tutor disables chat
        $resToggle = $this->actingAs($this->tutor, 'sanctum')
            ->postJson("/api/tutor/live-classes/{$liveClass->id}/toggle-chat");
        $resToggle->assertStatus(200)
            ->assertJson(['is_chat_enabled' => false]);

        // 3. Student attempt to message while disabled fails
        $resMsg2 = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/live-classes/{$liveClass->id}/messages", [
                'message' => 'Can I ask a question?',
            ]);
        $resMsg2->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_student_leave_and_tutor_end_class_records_attendance(): void
    {
        $liveClass = LiveClass::create([
            'course_id' => $this->course->id,
            'instructor_id' => $this->tutor->id,
            'title' => 'Final Wrap-Up Session',
            'class_date' => now()->toDateString(),
            'start_time' => '18:00',
            'status' => 'live',
        ]);

        $this->actingAs($this->enrolledStudent, 'sanctum')->postJson("/api/live-classes/{$liveClass->id}/join");

        // Fast-forward 20 minutes
        $this->travel(20)->minutes();

        // Student leaves
        $resLeave = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/live-classes/{$liveClass->id}/leave");
        $resLeave->assertStatus(200);

        $att = LiveClassAttendance::where('live_class_id', $liveClass->id)->where('user_id', $this->enrolledStudent->id)->first();
        $this->assertEquals('left', $att->status);
        $this->assertGreaterThanOrEqual(1200, $att->duration_seconds);

        // Tutor ends class
        $resEnd = $this->actingAs($this->tutor, 'sanctum')
            ->postJson("/api/tutor/live-classes/{$liveClass->id}/end");
        $resEnd->assertStatus(200)
            ->assertJson(['live_class' => ['status' => 'completed']]);

        // Tutor checks attendance summary
        $resAttendance = $this->actingAs($this->tutor, 'sanctum')
            ->getJson("/api/tutor/live-classes/{$liveClass->id}/attendance");
        $resAttendance->assertStatus(200)
            ->assertJsonStructure([
                'total_attendees',
                'total_minutes_attended',
                'attendances',
            ])
            ->assertJson([
                'total_attendees' => 1,
            ]);
    }
}
