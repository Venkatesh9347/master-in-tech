<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Company;
use App\Models\PlacementApplication;
use App\Models\PlacementInterview;
use App\Models\PlacementOpportunity;
use App\Models\StudentPlacementEligibility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 4 — the two remaining placement-advisor capabilities.
 *
 *   D3  #17  read-only placement interview pipeline
 *   D4  #20  read-only placement-scoped audit events
 *
 * These tests are authorization tests. Every one of them asserts a *denial* for
 * the roles that must not reach the endpoint, because that is what proves the
 * placement boundary is real rather than merely documented.
 */
class PlacementAdvisorRemainingCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private const PIPELINE = '/api/admin/placements/interviews';

    private const AUDIT = '/api/admin/placements/audit-events';

    protected function setUp(): void
    {
        parent::setUp();

        \App\Services\PlacementSettingService::updateSettings([
            'mock_interview_required' => false,
            'placement_enabled' => true,
        ]);
    }

    private function makeCompany(array $attributes = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Phase4 Corp',
            'email' => 'hr-'.Str::random(8).'@phase4.test',
            'phone' => '+91 9000000000',
            'industry' => 'Verification',
            'company_size' => '10-50',
            'location' => 'Hyderabad, India',
            'hr_name' => 'Phase4 Verifier',
            'status' => Company::STATUS_APPROVED,
        ], $attributes));
    }

    private function makeOpportunity(Company $company): PlacementOpportunity
    {
        return PlacementOpportunity::create([
            'company_id' => $company->id,
            'company_name' => $company->name,
            'title' => 'Security Analyst',
            'description' => 'Role description',
            'skills_required' => ['security'],
            'employment_type' => 'Full-time',
            'location' => 'Remote',
            'status' => PlacementOpportunity::STATUS_PUBLISHED,
        ]);
    }

    private function makeBatch(): Batch
    {
        $course = Course::create([
            'title' => 'Phase4 Course '.Str::random(5),
            'slug' => Str::slug('phase4-course-'.Str::random(6)),
            'code' => strtoupper(Str::random(3)),
            'description' => 'Course used by the Phase 4 placement verification fixtures.',
            'category' => 'Engineering',
            'instructor' => 'Phase4 Fixture Instructor',
            'price' => 0,
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
            'status' => 'published',
        ]);

        return Batch::create([
            'name' => 'Phase4 Cohort',
            'code' => strtoupper(Str::random(8)),
            'course_id' => $course->id,
            'start_date' => now()->subMonth()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function makeInterview(Company $company, User $candidate): PlacementInterview
    {
        $opportunity = $this->makeOpportunity($company);
        $batch = $this->makeBatch();

        $application = PlacementApplication::create([
            'placement_opportunity_id' => $opportunity->id,
            'user_id' => $candidate->id,
            'batch_id' => $batch->id,
            'batch_code' => $batch->code,
            'student_name' => $candidate->name,
            'email' => $candidate->email,
            'phone' => $candidate->phone ?? '+91 9000000000',
            'resume_url' => 'https://example.test/resume-'.Str::random(8).'.pdf',
            'status' => PlacementApplication::STATUS_INTERVIEW_SCHEDULED,
            'applied_at' => now(),
        ]);

        return PlacementInterview::create([
            'placement_application_id' => $application->id,
            'placement_opportunity_id' => $opportunity->id,
            'company_id' => $company->id,
            'candidate_id' => $candidate->id,
            'interview_date' => now()->addDays(3),
            'interview_type' => PlacementInterview::TYPE_ONLINE,
            'status' => PlacementInterview::STATUS_SCHEDULED,
            'technical_score' => 8,
            'communication_score' => 7,
            'overall_score' => 7,
            'recommendation' => PlacementInterview::RECOMMENDATION_SELECT,
            'feedback' => 'Strong on fundamentals.',
            // Deliberately populated to prove these are never serialised out.
            'meeting_link' => 'https://meet.example.com/join?pwd=SUPERSECRET123',
            'admin_notes' => 'INTERNAL ADMIN NOTE',
            'interviewer_notes' => 'COMPANY INTERNAL NOTE',
            'instructions' => 'INTERNAL INSTRUCTIONS',
        ]);
    }

    // =====================================================================
    // D3 — interview pipeline: allowed
    // =====================================================================

    public function test_placement_advisor_can_read_the_interview_pipeline(): void
    {
        $company = $this->makeCompany();
        $candidate = User::factory()->create(['role' => 'student']);
        $this->makeInterview($company, $candidate);

        $response = $this->actingAs($this->advisor(), 'sanctum')->getJson(self::PIPELINE);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $row = $response->json('data.0');

        // Placement-relevant pipeline facts are present.
        $this->assertSame('Security Analyst', $row['opportunity']['title']);
        $this->assertSame('Phase4 Corp', $row['company']['name']);
        $this->assertSame('scheduled', $row['status']);
        $this->assertSame(7, (int) $row['overall_score']);
        $this->assertSame('select', $row['recommendation']);
    }

    public function test_admin_retains_existing_access_to_the_interview_pipeline(): void
    {
        $company = $this->makeCompany();
        $candidate = User::factory()->create(['role' => 'student']);
        $this->makeInterview($company, $candidate);

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->getJson(self::PIPELINE)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // =====================================================================
    // D3 — data scope: only placement-relevant, sensitive fields withheld
    // =====================================================================

    public function test_pipeline_withholds_credential_bearing_and_internal_fields(): void
    {
        $company = $this->makeCompany();
        $candidate = User::factory()->create(['role' => 'student', 'email' => 'cand@example.test']);
        $this->makeInterview($company, $candidate);

        $row = $this->actingAs($this->advisor(), 'sanctum')
            ->getJson(self::PIPELINE)
            ->assertOk()
            ->json('data.0');

        foreach ([
            'meeting_link', 'admin_notes', 'interviewer_notes',
            'instructions', 'ip_address', 'user_agent',
        ] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $row,
                "pipeline must not expose [{$forbidden}]"
            );
        }

        // The raw body must not contain any of the seeded sensitive values.
        $body = json_encode($row);
        foreach (['SUPERSECRET123', 'INTERNAL ADMIN NOTE', 'COMPANY INTERNAL NOTE', 'INTERNAL INSTRUCTIONS'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body);
        }

        // Candidate identity is placement-relevant, but contact details are not
        // needed for pipeline oversight.
        $this->assertArrayNotHasKey('email', $row['candidate']);
        $this->assertArrayNotHasKey('phone', $row['candidate']);
    }

    // =====================================================================
    // D3 — pipeline is read-only
    // =====================================================================

    public function test_pipeline_exposes_no_mutation_path(): void
    {
        // The registered path is GET-only, so any other verb on it is 405.
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $response = $this->actingAs($this->advisor(), 'sanctum')->{$method.'Json'}(self::PIPELINE);
            $this->assertSame(
                405,
                $response->getStatusCode(),
                "{$method} on the registered path must be 405 (Method Not Allowed)"
            );
        }

        // Sub-paths do not exist at all, so they are 404 rather than 405.
        foreach ([
            ['post', self::PIPELINE.'/1'],
            ['put', self::PIPELINE.'/1'],
            ['delete', self::PIPELINE.'/1'],
            ['post', self::PIPELINE.'/1/reschedule'],
            ['post', self::PIPELINE.'/1/cancel'],
        ] as [$method, $uri]) {
            $response = $this->actingAs($this->advisor(), 'sanctum')->{$method.'Json'}($uri);
            $this->assertSame(
                404,
                $response->getStatusCode(),
                "{$method} {$uri} must not be routable (expected 404)"
            );
        }
    }

    // =====================================================================
    // D4 — audit events: allowed and filtered
    // =====================================================================

    public function test_placement_advisor_can_read_placement_audit_events(): void
    {
        $company = $this->makeCompany();
        $job = PlacementOpportunity::create([
            'company_id' => $company->id,
            'company_name' => $company->name,
            'title' => 'Audited Role',
            'description' => 'Audited role description',
            'status' => PlacementOpportunity::STATUS_PENDING_APPROVAL,
        ]);

        AuditLog::log('approved_company_job', $job, null, ['job_title' => 'Audited Role']);

        // Filtered by action so the assertion is about this capability, not about the
        // 'updated_placement_settings' event that setUp() legitimately writes.
$response = $this->actingAs($this->advisor(), 'sanctum')
            ->getJson(self::AUDIT.'?action=approved_company_job');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('approved_company_job', $response->json('data.0.action'));
    }

    public function test_placement_settings_audit_event_is_visible_with_a_null_model(): void
    {
        // setUp() calls PlacementSettingService::updateSettings(), which audits
        // 'updated_placement_settings' against a null model. It must still be
        // visible to a placement advisor because placement configuration is a
        // placement operation.
        $actions = collect(
            $this->actingAs($this->advisor(), 'sanctum')
                ->getJson(self::AUDIT)
                ->assertOk()
                ->json('data')
        )->pluck('action');

        $this->assertContains('updated_placement_settings', $actions->all());
    }

    public function test_audit_stream_returns_only_placement_related_events(): void
    {
        $company = $this->makeCompany();
        $job = PlacementOpportunity::create([
            'company_id' => $company->id,
            'company_name' => $company->name,
            'title' => 'Scoped Role',
            'description' => 'Scoped role description',
            'status' => PlacementOpportunity::STATUS_PENDING_APPROVAL,
        ]);

        // Placement events — must appear.
        AuditLog::log('approved_company_job', $job, null, ['job_title' => 'Scoped Role']);
        AuditLog::log('company_partnership_requested', $company, null, ['company_name' => $company->name]);
        AuditLog::log('approved_corporate_partner', $company, null, []);

        // Non-placement events — must NOT appear.
        AuditLog::log('created_user', User::factory()->create(['role' => 'student']), null, []);
        AuditLog::log('updated_user_role', User::factory()->create(['role' => 'student']), null, []);
        AuditLog::log('updated_tutor_permissions', User::factory()->create(['role' => 'tutor']), null, []);
        AuditLog::log('created_crm_lead', $company, null, []);
        AuditLog::log('recorded_lead_payment', $company, null, []);
        AuditLog::log('created_mock_interviewer', $company, null, []);
        AuditLog::log('booked_mock_interview', $company, null, []);
        AuditLog::log('submitted_mock_interview_evaluation', $company, null, []);
        AuditLog::log('payment_refund_initiated', $company, null, []);
        AuditLog::log('generated_certificate', $company, null, []);

        $actions = collect(
            $this->actingAs($this->advisor(), 'sanctum')->getJson(self::AUDIT)->assertOk()->json('data')
        )->pluck('action');

        // Every allowlisted placement event is present.
        foreach ([
            'approved_company_job', 'company_partnership_requested', 'approved_corporate_partner',
        ] as $allowed) {
            $this->assertContains($allowed, $actions->all(), "[{$allowed}] must be visible");
        }

        // Every non-placement event is absent.
        foreach ([
            'created_user', 'updated_user_role', 'updated_tutor_permissions',
            'created_crm_lead', 'recorded_lead_payment',
            'created_mock_interviewer', 'booked_mock_interview',
            'submitted_mock_interview_evaluation',
            'payment_refund_initiated', 'generated_certificate',
        ] as $excluded) {
            $this->assertNotContains(
                $excluded,
                $actions->all(),
                "[{$excluded}] must NOT be visible to a placement advisor"
            );
        }
    }

    public function test_audit_stream_never_exposes_credentials(): void
    {
        $company = $this->makeCompany();

        // A placement event that (hypothetically) logs credential-shaped keys.
        // The redactor must strip them regardless of payload content.
        AuditLog::log('approved_company_job', $company, [
            'password' => 'LEAKED_PASSWORD',
            'api_key' => 'LEAKED_API_KEY',
            'access_token' => 'LEAKED_TOKEN',
            'authorization' => 'Bearer LEAKED',
        ], [
            'job_title' => 'Redaction Probe',
            'secret' => 'LEAKED_SECRET',
            'session_id' => 'LEAKED_SESSION',
        ]);

        $row = $this->actingAs($this->advisor(), 'sanctum')
            ->getJson(self::AUDIT)
            ->assertOk()
            ->json('data.0');

        $body = json_encode($row);

        foreach ([
            'LEAKED_PASSWORD', 'LEAKED_API_KEY', 'LEAKED_TOKEN',
            'LEAKED_SECRET', 'LEAKED_SESSION', 'Bearer LEAKED',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body, "must not expose {$secret}");
        }

        // Non-credential context survives redaction.
        $this->assertSame('Redaction Probe', $row['new_values']['job_title'] ?? null);
    }

    public function test_audit_stream_withholds_network_and_agent_metadata(): void
    {
        $company = $this->makeCompany();
        AuditLog::log('approved_corporate_partner', $company, null, []);

        $row = $this->actingAs($this->advisor(), 'sanctum')
            ->getJson(self::AUDIT)
            ->assertOk()
            ->json('data.0');

        foreach (['ip_address', 'user_agent'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }

    public function test_audit_stream_rejects_an_action_outside_the_allowlist(): void
    {
        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson(self::AUDIT.'?action=created_user')
            ->assertStatus(422);
    }

    public function test_audit_stream_is_read_only(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $response = $this->actingAs($this->advisor(), 'sanctum')->{$method.'Json'}(self::AUDIT);
            $this->assertSame(405, $response->getStatusCode());
        }
    }

    // =====================================================================
    // Shared denial matrix — the core of the authorization proof
    // =====================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function deniedRoles(): array
    {
        return [
            'student' => ['student'],
            'company' => ['company'],
            'recruiter' => ['recruiter'],
            'tutor' => ['tutor'],
            'faculty' => ['faculty'],
            'instructor' => ['instructor'],
            'telecaller' => ['telecaller'],
            'counsellor' => ['counsellor'],
            'course_advisor' => ['course_advisor'],
        ];
    }


    #[DataProvider('deniedRoles')]
    public function test_role_cannot_read_the_interview_pipeline(string $role): void
    {
        $user = $this->userForRole($role);

        $this->actingAs($user, 'sanctum')
            ->getJson(self::PIPELINE)
            ->assertForbidden();
    }

    #[DataProvider('deniedRoles')]
    public function test_role_cannot_read_placement_audit_events(string $role): void
    {
        $user = $this->userForRole($role);

        $this->actingAs($user, 'sanctum')
            ->getJson(self::AUDIT)
            ->assertForbidden();
    }

    public function test_unauthenticated_cannot_read_either_endpoint(): void
    {
        $this->getJson(self::PIPELINE)->assertUnauthorized();
        $this->getJson(self::AUDIT)->assertUnauthorized();
    }

    public function test_company_cannot_reach_the_placement_endpoints(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create([
            'role' => 'company',
            'company_id' => $company->id,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')->getJson(self::PIPELINE)->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson(self::AUDIT)->assertForbidden();
    }

    public function test_placement_advisor_still_cannot_reach_admin_only_surfaces(): void
    {
        $advisor = $this->advisor();

        // The new read-only capability must not have widened anything.
        $this->actingAs($advisor, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($advisor, 'sanctum')->getJson('/api/admin/crm/leads')->assertForbidden();
        $this->actingAs($advisor, 'sanctum')->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->actingAs($advisor, 'sanctum')->getJson('/api/admin/settings')->assertForbidden();
        $this->actingAs($advisor, 'sanctum')
            ->getJson('/api/admin/placements/mock-interviews/stats')
            ->assertForbidden();
    }

    public function test_existing_admin_audit_surface_is_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // /admin/audit-logs still reads LearningActivityLog and still works.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/audit-logs')
            ->assertOk();
    }

    public function test_placement_advisor_cannot_mutate_interviews_through_the_new_routes(): void
    {
        $advisor = $this->advisor();
        $company = $this->makeCompany();
        $interview = $this->makeInterview($company, User::factory()->create(['role' => 'student']));

        foreach ([
            ['post', self::PIPELINE, ['candidate_id' => 1]],
            ['put', self::PIPELINE.'/'.$interview->id, ['status' => 'cancelled']],
            ['patch', self::PIPELINE.'/'.$interview->id, ['status' => 'cancelled']],
            ['delete', self::PIPELINE.'/'.$interview->id, []],
            ['post', self::PIPELINE.'/'.$interview->id.'/reschedule', []],
            ['post', self::PIPELINE.'/'.$interview->id.'/cancel', []],
        ] as [$method, $uri, $payload]) {
            $response = $this->actingAs($advisor, 'sanctum')->{$method.'Json'}($uri, $payload);
            $this->assertContains(
                $response->getStatusCode(),
                [404, 405],
                "{$method} {$uri} must be unroutable (got {$response->getStatusCode()})"
            );
        }

        // The record is untouched.
        $this->assertSame(
            PlacementInterview::STATUS_SCHEDULED,
            $interview->fresh()->status
        );
    }

    // =====================================================================

    private function advisor(): User
    {
        return User::factory()->create(['role' => 'placement_advisor']);
    }

    private function userForRole(string $role): User
    {
        $attributes = ['role' => $role];

        if (in_array($role, User::CORPORATE_ROLES, true)) {
            $attributes['company_id'] = $this->makeCompany()->id;
            $attributes['status'] = 'active';
        }

        return User::factory()->create($attributes);
    }
}
