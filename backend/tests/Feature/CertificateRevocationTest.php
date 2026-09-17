<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-C certificate revocation: explicit, append-only active -> revoked
 * transition, admin-only, audited, concurrency-safe.
 */
class CertificateRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeCourse(): Course
    {
        $title = 'Course '.Str::random(6);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'description' => 'Revocation fixture course.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);
    }

    private function makeCertificate(): array
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse();

        $certificate = Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'certificate_code' => 'MIT-2026-'.strtoupper(Str::random(10)),
            'issued_at' => now(),
        ]);

        return [$student, $course, $certificate];
    }

    private function revokeUrl(Certificate $certificate): string
    {
        return '/api/admin/certificates/'.$certificate->id.'/revoke';
    }

    private function reason(): string
    {
        return 'Academic integrity violation confirmed by the review board.';
    }

    /* 1. admin can revoke active certificate */

    public function test_admin_can_revoke_active_certificate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, , $certificate] = $this->makeCertificate();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()]);

        $response->assertOk()
            ->assertJsonPath('certificate.status', 'revoked')
            ->assertJsonPath('certificate.certificate_code', $certificate->certificate_code);

        $this->assertSame('revoked', $certificate->fresh()->status);
    }

    /* 2. super_admin can revoke active certificate */

    public function test_super_admin_can_revoke_active_certificate(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        [, , $certificate] = $this->makeCertificate();

        $this->actingAs($super, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        $this->assertSame('revoked', $certificate->fresh()->status);
        $this->assertSame($super->id, $certificate->fresh()->revoked_by);
    }

    /* 3-6. non-admin roles receive 403 */

    public function test_non_admin_roles_receive_403(): void
    {
        [$student, , $certificate] = $this->makeCertificate();

        $actors = [
            $student,
            User::factory()->create(['role' => 'tutor']),
            User::factory()->create(['role' => 'telecaller']),
            User::factory()->create(['role' => 'counsellor']),
        ];

        foreach ($actors as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
                ->assertStatus(403);
        }

        $this->assertSame('active', $certificate->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'certificate_revoked')->count());
    }

    /* 7. unauthenticated request receives 401 */

    public function test_unauthenticated_request_receives_401(): void
    {
        [, , $certificate] = $this->makeCertificate();

        $this->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertStatus(401);

        $this->assertSame('active', $certificate->fresh()->status);
    }

    /* 8. nonexistent certificate handled without sensitive disclosure */

    public function test_nonexistent_certificate_handled_correctly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/certificates/999999/revoke', ['reason' => $this->reason()]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Not found.']);
    }

    /* 9. invalid reason rejected */

    public function test_invalid_reason_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, , $certificate] = $this->makeCertificate();

        // Missing reason.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Too short to be a substantive reason.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => 'bad'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Whitespace-only.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => '          '])
            ->assertStatus(422);

        // Over the limit.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => str_repeat('x', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('active', $certificate->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'certificate_revoked')->count());
    }

    /* 10. revoked_at is populated */

    public function test_revoked_at_is_populated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, , $certificate] = $this->makeCertificate();

        $this->assertNull($certificate->revoked_at);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        $revokedAt = $certificate->fresh()->revoked_at;
        $this->assertNotNull($revokedAt);
        $this->assertTrue($revokedAt->isAfter(now()->subMinute()));
    }

    /* 11. revoked_by is server-derived; ownership/identity immutable */

    public function test_revoked_by_is_server_derived(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        [$student, $course, $certificate] = $this->makeCertificate();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), [
                'reason' => $this->reason(),
                // Client-controlled attribution/identity must be ignored.
                'revoked_by' => $other->id,
                'user_id' => $other->id,
                'status' => 'active',
            ]);

        $response->assertOk();

        $fresh = $certificate->fresh();
        $this->assertSame($admin->id, $fresh->revoked_by);
        $this->assertSame('revoked', $fresh->status);
        // Ownership and identity untouched.
        $this->assertSame($student->id, $fresh->user_id);
        $this->assertSame($course->id, $fresh->course_id);
        $this->assertSame($certificate->certificate_code, $fresh->certificate_code);
    }

    /* 12. status changes active -> revoked */

    public function test_status_changes_active_to_revoked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, , $certificate] = $this->makeCertificate();

        $this->assertSame('active', $certificate->fresh()->status);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'status' => 'revoked',
        ]);
    }

    /* 13. second revoke is deterministic */

    public function test_second_revoke_is_deterministic(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, , $certificate] = $this->makeCertificate();

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk()
            ->json('certificate');

        $second = $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => 'A different later reason text here.'])
            ->assertOk()
            ->assertJsonPath('certificate.status', 'revoked')
            ->json('certificate');

        // Original revocation wins: timestamp, actor, and reason preserved.
        $this->assertSame($first['revoked_at'], $second['revoked_at']);
        $this->assertSame($this->reason(), $second['revocation_reason']);
        $this->assertSame('revoked', $certificate->fresh()->status);
    }

    /* 14. public verification does not report revoked certificate as valid */

    public function test_public_verification_does_not_report_revoked_as_valid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$student, $course, $certificate] = $this->makeCertificate();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        $response = $this->getJson("/api/verify-certificate/{$certificate->certificate_code}");

        $response->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('revoked', true);

        $body = $response->getContent();
        $this->assertStringNotContainsString('"valid":true', $body);
        // No admin ids, audit metadata, or reasons in the public payload.
        $response->assertJsonMissingPath('revoked_by');
        $response->assertJsonMissingPath('revocation_reason');
        $this->assertStringNotContainsString('audit', strtolower($body));
        // Public identity fields still present.
        $response->assertJsonPath('recipient_name', $student->name);
        $response->assertJsonPath('course_title', $course->title);
    }

    /* 15. certificate access behavior for revoked records */

    public function test_revoked_certificate_download_is_blocked_but_record_preserved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$student, , $certificate] = $this->makeCertificate();
        $other = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        // Owner no longer receives a valid artifact.
        $this->actingAs($student, 'sanctum')
            ->get("/api/student/certificates/{$certificate->certificate_code}/download")
            ->assertStatus(410);

        // Admin likewise: no valid artifact is represented.
        $this->actingAs($admin, 'sanctum')
            ->get("/api/student/certificates/{$certificate->certificate_code}/download")
            ->assertStatus(410);

        // Unrelated users still get the ownership rejection (no new signal).
        $this->actingAs($other, 'sanctum')
            ->get("/api/student/certificates/{$certificate->certificate_code}/download")
            ->assertStatus(403);

        // Historical record preserved, never reactivated.
        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'status' => 'revoked',
            'certificate_code' => $certificate->certificate_code,
        ]);
    }

    public function test_active_certificate_download_still_works(): void
    {
        [$student, , $certificate] = $this->makeCertificate();

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/student/certificates/{$certificate->certificate_code}/download");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generate_after_revocation_does_not_represent_valid_certificate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$student, $course, $certificate] = $this->makeCertificate();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        // The unique (user, course) record cannot be re-minted, and the
        // revoked state is surfaced instead of a valid-looking certificate.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate")
            ->assertStatus(422)
            ->assertJsonPath('status', 'revoked');

        $this->assertSame(1, Certificate::where('user_id', $student->id)
            ->where('course_id', $course->id)->count());
        $this->assertSame('revoked', $certificate->fresh()->status);
    }

    /* 16. successful revocation creates exactly one audit event */

    public function test_successful_revocation_creates_exactly_one_audit_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, , $certificate] = $this->makeCertificate();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        // Replay must not append a second audit entry.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'certificate_revoked')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'certificate_revoked',
            'user_id' => $admin->id,
            'auditable_type' => Certificate::class,
            'auditable_id' => $certificate->id,
        ]);
    }

    /* 17. audit event contains safe fields only */

    public function test_audit_event_contains_safe_fields_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, , $certificate] = $this->makeCertificate();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        $log = AuditLog::where('action', 'certificate_revoked')->firstOrFail();
        $new = $log->new_values;

        $this->assertSame($certificate->id, $new['certificate_id']);
        $this->assertSame($certificate->certificate_code, $new['certificate_code']);
        $this->assertSame('active', $new['previous_status']);
        $this->assertSame('revoked', $new['new_status']);
        $this->assertSame($admin->id, $new['revoked_by']);
        $this->assertSame($this->reason(), $new['revocation_reason']);
        $this->assertNotEmpty($new['revoked_at']);

        $blob = json_encode($log->old_values).json_encode($new);
        foreach (['password', 'otp', 'secret', 'token', 'authorization', 'credential', 'payload'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $blob);
        }
    }

    /* 18. no secret/credential/authorization header leakage */

    public function test_no_secret_or_implementation_leakage(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Sentinel Admin']);
        [, , $certificate] = $this->makeCertificate();

        $responses = [
            // Validation failure.
            $this->actingAs($admin, 'sanctum')->postJson($this->revokeUrl($certificate), []),
            // Unknown record.
            $this->actingAs($admin, 'sanctum')->postJson('/api/admin/certificates/999999/revoke', ['reason' => $this->reason()]),
            // Forbidden role.
            $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
                ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()]),
            // Success (admin audience only).
            $this->actingAs($admin, 'sanctum')->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()]),
            // Public verification of the revoked certificate.
            $this->getJson("/api/verify-certificate/{$certificate->certificate_code}"),
        ];

        foreach ($responses as $response) {
            $body = $response->getContent() ?: '';
            $this->assertStringNotContainsStringIgnoringCase('stack trace', $body);
            $this->assertStringNotContainsStringIgnoringCase('exception', $body);
            $this->assertStringNotContainsString('storage_path', strtolower($body));
            $this->assertStringNotContainsString('.php', $body);
        }
    }

    /* 19. concurrent/double revocation cannot reactivate or corrupt state.
     *
     * What was tested and why: true OS-level parallelism is not practical
     * with this suite's single-process in-memory SQLite database. Safety
     * rests on lockForUpdate inside a transaction plus the replay guard, so
     * sequential double revocation with competing reasons is exercised: the
     * first revocation wins every field and the row stays revoked.
     */

    public function test_double_revocation_cannot_reactivate_or_corrupt_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $second = User::factory()->create(['role' => 'admin']);
        [, , $certificate] = $this->makeCertificate();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => $this->reason()])
            ->assertOk();

        $this->actingAs($second, 'sanctum')
            ->postJson($this->revokeUrl($certificate), ['reason' => 'Competing second revocation reason here.'])
            ->assertOk();

        $fresh = $certificate->fresh();
        $this->assertSame('revoked', $fresh->status);
        $this->assertSame($admin->id, $fresh->revoked_by);
        $this->assertSame($this->reason(), $fresh->revocation_reason);
        $this->assertNotNull($fresh->revoked_at);
        $this->assertSame(1, AuditLog::where('action', 'certificate_revoked')->count());
    }
}
