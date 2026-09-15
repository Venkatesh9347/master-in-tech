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
     * Build a genuine-shaped LiveKit webhook JWT: header {alg, kid} and
     * payload {iss, iat, nbf, exp, sha256-of-raw-body}, mirroring what
     * livekit-server mints per delivery (exp = +300s). The HTTP request body
     * stays the actual event JSON — the event body is NEVER placed into the
     * JWT payload. Claim overrides replace values; $omitClaims removes them.
     */
    private function signedToken(string $rawBody, array $claimOverrides = [], array $omitClaims = []): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => 'devkey',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 300,
            'sha256' => base64_encode(hash('sha256', $rawBody, true)),
        ], $claimOverrides);
        foreach ($omitClaims as $omit) {
            unset($claims[$omit]);
        }

        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'devkey']);
        $b64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $b64Payload = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');
        $signingInput = $b64Header . '.' . $b64Payload;
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, 'secret', true)), '+/', '-_'), '=');
        return $b64Header . '.' . $b64Payload . '.' . $signature;
    }

    private function postWebhook(array $event, array $claimOverrides = [], array $omitClaims = []): \Illuminate\Testing\TestResponse
    {
        // Hash and send the EXACT raw bytes, mirroring a real delivery.
        $rawBody = (string) json_encode($event);
        $server = $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer ' . $this->signedToken($rawBody, $claimOverrides, $omitClaims),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        return $this->call('POST', '/api/livekit/webhook', [], [], [], $server, $rawBody);
    }

    private function postRawBody(string $rawBody, string $token): \Illuminate\Testing\TestResponse
    {
        $server = $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        return $this->call('POST', '/api/livekit/webhook', [], [], [], $server, $rawBody);
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

    public function test_expired_token_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], ['exp' => time() - 300])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_token_within_expiry_leeway_is_accepted(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], ['exp' => time() - 30])->assertOk();

        $this->assertDatabaseHas('class_session_attendances', [
            'class_session_id' => $this->session->id,
            'user_id' => $this->student->id,
        ]);
    }

    public function test_missing_exp_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], [], ['exp'])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_malformed_exp_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], ['exp' => 'never'])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_future_nbf_beyond_leeway_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], ['nbf' => time() + 300])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_malformed_nbf_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], ['nbf' => 'soon'])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], ['iss' => 'other-key'])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_missing_issuer_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], [], ['iss'])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_forged_body_with_valid_token_signature_is_rejected(): void
    {
        $eventA = [
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ];
        $rawA = (string) json_encode($eventA);

        // Token minted for body A (valid signature, correct claims for A)...
        $tokenForA = $this->signedToken($rawA);

        // ...must NOT authenticate a different body B (one byte changed).
        $eventB = $eventA;
        $eventB['participant']['identity'] = 'user-' . $this->tutor->id;
        $rawB = (string) json_encode($eventB);
        $this->assertNotSame($rawA, $rawB);

        $this->postRawBody($rawB, $tokenForA)->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_missing_sha256_claim_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], [], ['sha256'])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_incorrect_sha256_claim_is_rejected(): void
    {
        $this->postWebhook([
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ], ['sha256' => base64_encode(hash('sha256', 'something-else', true))])->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_wrong_kid_is_rejected(): void
    {
        $event = [
            'event' => 'participant_joined',
            'room' => ['name' => 'masterintech-session-1'],
            'participant' => ['identity' => 'user-' . $this->student->id],
        ];
        $rawBody = (string) json_encode($event);

        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'other-key']);
        $b64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $claims = [
            'iss' => 'devkey',
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 300,
            'sha256' => base64_encode(hash('sha256', $rawBody, true)),
        ];
        $b64Payload = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');
        $signingInput = $b64Header . '.' . $b64Payload;
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, 'secret', true)), '+/', '-_'), '=');

        $this->postRawBody($rawBody, $b64Header . '.' . $b64Payload . '.' . $signature)
            ->assertStatus(401);

        $this->assertDatabaseCount('class_session_attendances', 0);
    }

    public function test_replayed_room_started_with_expired_token_is_rejected(): void
    {
        // Drive the session to completed/ended through valid deliveries.
        $this->postWebhook([
            'event' => 'room_finished',
            'room' => ['name' => 'masterintech-session-1'],
        ])->assertOk();

        $session = $this->session->fresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame('ended', $session->livekit_status);

        // Replay a previously valid room_started delivery whose token has
        // since expired (properly signed, correct body hash, stale exp).
        $this->postWebhook([
            'event' => 'room_started',
            'room' => ['name' => 'masterintech-session-1'],
        ], ['exp' => time() - 300])->assertStatus(401);

        // The completed session must NOT be reopened.
        $session = $this->session->fresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame('ended', $session->livekit_status);
    }
}
