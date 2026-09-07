<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\ClassSession;
use App\Models\ClassSessionAttendance;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LiveKitWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tutor;
    private User $student;
    private Course $course;
    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->tutor = User::factory()->create(['role' => 'tutor', 'status' => 'active']);
        $this->student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->course = Course::create([
            'title' => 'Webhook Course',
            'slug' => 'webhook-course-' . uniqid(),
            'description' => 'desc',
            'instructor' => $this->tutor->name,
            'instructor_id' => $this->tutor->id,
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'price' => 99,
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'active',
        ]);

        $batch = Batch::create([
            'name' => 'Webhook Batch',
            'code' => 'WH-' . uniqid(),
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'start_date' => now()->toDateString(),
            'status' => 'ongoing',
        ]);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $this->student->id,
            'status' => 'active',
        ]);

        $this->session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Webhook Session',
            'description' => 'desc',
            'platform' => 'livekit',
            'livekit_room_name' => 'masterintech-session-1',
            'livekit_status' => 'idle',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Build a signed LiveKit webhook JWT (kid=devkey, HS256 with "secret").
     */
    private function signedToken(string $payload): string
    {
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'devkey']);
        $b64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $b64Payload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signingInput = $b64Header . '.' . $b64Payload;
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, 'secret', true)), '+/', '-_'), '=');
        return $b64Header . '.' . $b64Payload . '.' . $signature;
    }

    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->signedToken(json_encode($payload)),
            'Accept' => 'application/json',
        ];
        return $this->postJson('/api/livekit/webhook', $payload, $headers);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = ['event' => 'participant_joined'];

        $res = $this->postJson('/api/livekit/webhook', $payload, [
            'Authorization' => 'Bearer invalid.token.value',
            'Accept' => 'application/json',
        ]);

        $res->assertStatus(401);
    }

    public function test_participant_joined_records_attendance(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ])->assertOk();

        $this->assertDatabaseHas('class_session_attendances', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->student->id,
            'status' => 'present',
        ]);
    }

    public function test_participant_left_updates_duration(): void
    {
        // Simulate a join first.
        ClassSessionAttendance::create([
            'class_session_id' => $this->session->id,
            'user_id' => $this->student->id,
            'status' => 'present',
            'joined_at' => now()->subMinutes(10),
            'duration_seconds' => 0,
        ]);

        $this->postWebhook([
            'event' => 'participant_left',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ])->assertOk();

        $attendance = ClassSessionAttendance::where('class_session_id', $this->session->id)
            ->where('user_id', $this->student->id)
            ->firstOrFail();

        $this->assertNotNull($attendance->left_at);
        $this->assertGreaterThanOrEqual(590, $attendance->duration_seconds);
    }

    public function test_host_join_activates_session(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->tutor->id],
        ])->assertOk();

        $this->assertSame('active', $this->session->fresh()->livekit_status);
    }

    public function test_room_started_marks_session_live(): void
    {
        $this->postWebhook([
            'event' => 'room_started',
            'room' => ['name' => 'masterintech-session-1'],
        ])->assertOk();

        $session = $this->session->fresh();
        $this->assertSame('live', $session->status);
        $this->assertSame('active', $session->livekit_status);
    }

    public function test_room_finished_finalizes_attendance_and_ends_session(): void
    {
        ClassSessionAttendance::create([
            'class_session_id' => $this->session->id,
            'user_id' => $this->student->id,
            'status' => 'present',
            'joined_at' => now()->subMinutes(5),
            'duration_seconds' => 0,
        ]);

        $this->postWebhook([
            'event' => 'room_finished',
            'room' => ['name' => 'masterintech-session-1'],
        ])->assertOk();

        $session = $this->session->fresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame('ended', $session->livekit_status);

        $attendance = ClassSessionAttendance::where('class_session_id', $this->session->id)->firstOrFail();
        $this->assertNotNull($attendance->left_at);
        $this->assertGreaterThanOrEqual(290, $attendance->duration_seconds);
    }

    public function test_unknown_room_is_acknowledged_without_error(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'some-other-room'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ])->assertOk();

        $this->assertDatabaseCount('class_session_attendances', 0);
    }
}
