<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlacementOpportunity;
use App\Models\User;
use App\Services\PlacementSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1 — placement_advisor authorization boundary, the administrative role
 * assignment policy, and the CRM capability matrix.
 *
 * Conventions follow the existing suite: `actingAs($user, 'sanctum')`,
 * RefreshDatabase, and project-standard 401/403 responses.
 */
class PlacementAdvisorAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Students must reach the placement portal for the publish-visibility
        // assertions. Matches CompanyPortalTest's own setUp.
        PlacementSettingService::updateSettings([
            'mock_interview_required' => false,
            'placement_enabled' => true,
        ]);
    }

    /**
     * No CompanyFactory exists in this project; CompanyPortalTest builds rows
     * directly. Emails are unique per company.
     */
    private function makeCompany(array $attributes = []): Company
    {
        return Company::create(array_merge([
            'name' => 'CloudTech Systems Inc',
            'email' => 'recruiter-'.Str::random(8).'@cloudtech.test',
            'phone' => '+91 9876543210',
            'industry' => 'Cloud Computing & AI',
            'company_size' => '51-200',
            'location' => 'Hyderabad, India',
            'hr_name' => 'Sarah Jenkins',
            'status' => Company::STATUS_APPROVED,
        ], $attributes));
    }

    private function advisor(): User
    {
        return User::factory()->create(['role' => 'placement_advisor']);
    }

    private function companyUser(?Company $company = null): User
    {
        $company ??= $this->makeCompany(['status' => Company::STATUS_APPROVED]);

        return User::factory()->create([
            'role' => 'company',
            'company_id' => $company->id,
            'status' => 'active',
            'current_session_id' => 'valid_session_'.Str::random(10),
        ]);
    }

    private function pendingJob(?Company $company = null): PlacementOpportunity
    {
        $company ??= $this->makeCompany(['status' => Company::STATUS_APPROVED]);

        return PlacementOpportunity::create([
            'company_id' => $company->id,
            'company_name' => $company->name,
            'title' => 'Backend Engineer',
            'description' => 'Role description',
            'skills_required' => ['php'],
            'employment_type' => 'Full-time',
            'location' => 'Remote',
            'status' => PlacementOpportunity::STATUS_PENDING_APPROVAL,
        ]);
    }

    // ---------------------------------------------------------------------
    // 1. placement_advisor can access authorized placement operations
    // ---------------------------------------------------------------------

    public function test_placement_advisor_can_read_pending_vacancies(): void
    {
        $this->pendingJob();

        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/placements/jobs/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_placement_advisor_can_read_placement_stats(): void
    {
        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/placements/stats')
            ->assertOk();
    }

    public function test_placement_advisor_can_read_corporate_partners(): void
    {
        $this->makeCompany(['status' => Company::STATUS_PENDING]);

        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/placements/partners')
            ->assertOk();
    }

    public function test_placement_advisor_can_read_placement_opportunities_and_applications(): void
    {
        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/placements/opportunities')
            ->assertOk();

        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/placements/applications')
            ->assertOk();
    }

    public function test_placement_advisor_can_manage_placement_settings(): void
    {
        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/placements/settings')
            ->assertOk();

        $this->actingAs($this->advisor(), 'sanctum')
            ->putJson('/api/admin/placements/settings', ['placement_enabled' => true])
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // The C1 decision: approval publishes. No new 'approved' state.
    // ---------------------------------------------------------------------

    public function test_placement_advisor_approval_publishes_the_vacancy(): void
    {
        $job = $this->pendingJob();

        $this->assertSame(
            PlacementOpportunity::STATUS_PENDING_APPROVAL,
            $job->status
        );

        $this->actingAs($this->advisor(), 'sanctum')
            ->postJson("/api/admin/placements/jobs/{$job->id}/approve")
            ->assertOk();

        // The existing behaviour is preserved exactly: approve => published.
        $this->assertSame(
            PlacementOpportunity::STATUS_PUBLISHED,
            $job->fresh()->status
        );
    }

    public function test_approval_audit_event_remains_intact(): void
    {
        $job = $this->pendingJob();

        $this->actingAs($this->advisor(), 'sanctum')
            ->postJson("/api/admin/placements/jobs/{$job->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'approved_company_job',
            'auditable_type' => PlacementOpportunity::class,
            'auditable_id' => $job->id,
        ]);
    }

    public function test_placement_advisor_rejection_keeps_the_vacancy_rejected(): void
    {
        $job = $this->pendingJob();

        $this->actingAs($this->advisor(), 'sanctum')
            ->postJson("/api/admin/placements/jobs/{$job->id}/reject", ['reason' => 'No salary band'])
            ->assertOk();

        $this->assertSame(
            PlacementOpportunity::STATUS_REJECTED,
            $job->fresh()->status
        );
    }

    public function test_rejected_vacancy_is_not_visible_to_students(): void
    {
        $job = $this->pendingJob();

        $this->actingAs($this->advisor(), 'sanctum')
            ->postJson("/api/admin/placements/jobs/{$job->id}/reject", ['reason' => 'No'])
            ->assertOk();

        $student = User::factory()->create(['role' => 'student']);

        $listed = $this->actingAs($student, 'sanctum')
            ->getJson('/api/placements/opportunities')
            ->assertOk();

        $ids = collect($listed->json('data') ?? [])->pluck('id')->all();
        $this->assertNotContains($job->id, $ids);

        // And the detail route refuses it too. It answers 404 rather than 403
        // so a non-published vacancy's existence is not confirmed to students.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/placements/opportunities/{$job->id}")
            ->assertNotFound();
    }

    public function test_approved_vacancy_becomes_visible_to_students(): void
    {
        $job = $this->pendingJob();

        $this->actingAs($this->advisor(), 'sanctum')
            ->postJson("/api/admin/placements/jobs/{$job->id}/approve")
            ->assertOk();

        $student = User::factory()->create(['role' => 'student']);

        $listed = $this->actingAs($student, 'sanctum')
            ->getJson('/api/placements/opportunities')
            ->assertOk();

        $ids = collect($listed->json('data') ?? [])->pluck('id')->all();
        $this->assertContains($job->id, $ids);
    }

    public function test_placement_advisor_can_approve_or_reject_a_corporate_partner(): void
    {
        $company = $this->makeCompany(['status' => Company::STATUS_PENDING]);

        $this->actingAs($this->advisor(), 'sanctum')
            ->postJson("/api/admin/placements/partners/{$company->id}/approve")
            ->assertOk();

        $this->assertSame(Company::STATUS_APPROVED, $company->fresh()->status);

        $this->actingAs($this->advisor(), 'sanctum')
            ->postJson("/api/admin/placements/partners/{$company->id}/suspend")
            ->assertOk();

        $this->assertSame(Company::STATUS_SUSPENDED, $company->fresh()->status);

        $this->actingAs($this->advisor(), 'sanctum')
            ->postJson("/api/admin/placements/partners/{$company->id}/reactivate")
            ->assertOk();

        $this->assertSame(Company::STATUS_APPROVED, $company->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 2/3. placement_advisor is NOT an administrator; students are not placement ops
    // ---------------------------------------------------------------------

    public function test_placement_advisor_cannot_access_user_administration(): void
    {
        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_placement_advisor_cannot_assign_roles(): void
    {
        $target = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->advisor(), 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('student', $target->fresh()->role);
    }

    public function test_placement_advisor_cannot_access_unrelated_admin_endpoints(): void
    {
        $advisor = $this->advisor();

        // CMS / course administration / payments / certificates.
        foreach ([
            '/api/admin/events',
            '/api/admin/categories',
            '/api/admin/payments',
            '/api/admin/certificates',
            '/api/admin/enrollments/stats',
        ] as $uri) {
            $this->actingAs($advisor, 'sanctum')
                ->getJson($uri)
                ->assertForbidden();
        }
    }

    public function test_placement_advisor_cannot_access_mock_interview_administration(): void
    {
        // Mock-interview administration is not in the Phase 1 capability list
        // and stays admin-only.
        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/placements/mock-interviews/stats')
            ->assertForbidden();
    }

    public function test_placement_advisor_has_no_unrestricted_crm_access(): void
    {
        $this->actingAs($this->advisor(), 'sanctum')
            ->getJson('/api/admin/crm/leads')
            ->assertForbidden();

        $this->assertFalse($this->advisor()->canAccessCrm());
    }

    public function test_student_cannot_access_placement_administration(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        foreach ([
            '/api/admin/placements/stats',
            '/api/admin/placements/jobs/pending',
            '/api/admin/placements/partners',
            '/api/admin/placements/applications',
            '/api/admin/placements/opportunities',
        ] as $uri) {
            $this->actingAs($student, 'sanctum')
                ->getJson($uri)
                ->assertForbidden();
        }
    }

    public function test_placement_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/placements/stats')->assertUnauthorized();
        $this->getJson('/api/admin/placements/jobs/pending')->assertUnauthorized();
    }

    // ---------------------------------------------------------------------
    // 4/5/6. Company boundary: cannot self-approve, self-publish or cross over
    // ---------------------------------------------------------------------

    public function test_company_cannot_publish_its_own_vacancy(): void
    {
        $user = $this->companyUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/company/jobs', [
                'title' => 'Backend Engineer',
                'description' => 'Role description',
                'skills_required' => ['php'],
                'employment_type' => 'Full-time',
                'location' => 'Remote',
                'status' => 'published',
            ]);

        // `published` is not an accepted value, so the request is rejected.
        $response->assertStatus(422);
        $this->assertDatabaseMissing('placement_opportunities', ['status' => 'published']);
    }

    public function test_company_cannot_approve_its_own_vacancy(): void
    {
        $user = $this->companyUser();
        $job = $this->pendingJob($user->company);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/placements/jobs/{$job->id}/approve")
            ->assertForbidden();

        $this->assertSame(
            PlacementOpportunity::STATUS_PENDING_APPROVAL,
            $job->fresh()->status
        );
    }

    public function test_company_cannot_self_publish_via_update(): void
    {
        $user = $this->companyUser();
        $job = $this->pendingJob($user->company);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/company/jobs/{$job->id}", ['status' => 'published'])
            ->assertStatus(422);

        $this->assertSame(
            PlacementOpportunity::STATUS_PENDING_APPROVAL,
            $job->fresh()->status
        );
    }

    public function test_company_cannot_manipulate_another_companys_vacancy(): void
    {
        $companyA = $this->makeCompany(['status' => Company::STATUS_APPROVED]);
        $companyB = $this->makeCompany(['status' => Company::STATUS_APPROVED]);

        $userA = User::factory()->create(['role' => 'company', 'company_id' => $companyA->id]);
        $jobB = $this->pendingJob($companyB);

        // Read
        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/company/jobs/{$jobB->id}")
            ->assertForbidden();

        // Update
        $this->actingAs($userA, 'sanctum')
            ->putJson("/api/company/jobs/{$jobB->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        // Delete
        $this->actingAs($userA, 'sanctum')
            ->deleteJson("/api/company/jobs/{$jobB->id}")
            ->assertForbidden();

        $this->assertSame('Backend Engineer', $jobB->fresh()->title);
        $this->assertDatabaseHas('placement_opportunities', ['id' => $jobB->id]);
    }

    public function test_pending_and_suspended_companies_cannot_use_the_portal(): void
    {
        foreach ([Company::STATUS_PENDING, Company::STATUS_SUSPENDED] as $status) {
            $company = $this->makeCompany(['status' => $status]);
            $user = User::factory()->create(['role' => 'company', 'company_id' => $company->id]);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/company/dashboard')
                ->assertForbidden();
        }
    }

    public function test_approved_company_can_use_the_portal(): void
    {
        $user = $this->companyUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/company/dashboard')
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // 7. IDOR: placement operations are global by design, but other
    //    protected resources stay out of reach.
    // ---------------------------------------------------------------------

    public function test_placement_advisor_cannot_read_or_write_users_by_arbitrary_id(): void
    {
        $advisor = $this->advisor();
        $victim = User::factory()->create(['role' => 'student']);

        $this->actingAs($advisor, 'sanctum')
            ->getJson("/api/admin/users/{$victim->id}")
            ->assertForbidden();

        $this->actingAs($advisor, 'sanctum')
            ->putJson("/api/admin/users/{$victim->id}", ['name' => 'Compromised'])
            ->assertForbidden();

        $this->actingAs($advisor, 'sanctum')
            ->deleteJson("/api/admin/users/{$victim->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => $victim->name]);
    }

    public function test_placement_advisor_cannot_act_on_another_companys_portal(): void
    {
        $advisor = $this->advisor();
        $other = $this->companyUser();

        $this->actingAs($advisor, 'sanctum')
            ->getJson('/api/company/dashboard')
            ->assertForbidden();

        $this->actingAs($advisor, 'sanctum')
            ->getJson('/api/company/jobs')
            ->assertForbidden();

        $this->assertNotNull($other->company_id);
    }

    // ---------------------------------------------------------------------
    // 11/12/13. Administrative role assignment policy
    // ---------------------------------------------------------------------

    public function test_ordinary_user_cannot_change_their_own_role(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        // There is no self-service role endpoint, and the admin path rejects
        // it even for an administrator acting on themselves.
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$admin->id}/role", ['role' => 'student'])
            ->assertForbidden();

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('student', $student->fresh()->role);
    }

    public function test_non_admin_cannot_change_any_role(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        $target = User::factory()->create(['role' => 'student']);

        $this->actingAs($tutor, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('student', $target->fresh()->role);
    }

    public function test_admin_cannot_grant_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'super_admin'])
            ->assertForbidden();

        $this->assertSame('student', $target->fresh()->role);
    }

    public function test_super_admin_can_grant_super_admin(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'student']);

        $this->actingAs($super, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'super_admin'])
            ->assertOk();

        $this->assertSame('super_admin', $target->fresh()->role);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'wizard'])
            ->assertStatus(422);

        $this->assertSame('student', $target->fresh()->role);
    }

    public function test_placement_advisor_is_assignable_by_an_administrator(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'placement_advisor'])
            ->assertOk();

        $this->assertSame('placement_advisor', $target->fresh()->role);
        $this->assertTrue($target->fresh()->isPlacementAdvisor());
        $this->assertTrue($target->fresh()->canOperatePlacement());
    }

    public function test_every_supported_role_is_assignable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (User::ROLES as $role) {
            if ($role === 'super_admin') {
                // Covered separately: requires a super_admin actor.
                continue;
            }

            $target = User::factory()->create(['role' => 'student']);

            $payload = ['role' => $role];

            // Corporate roles additionally require a linked profile, otherwise
            // EnsureUserIsCompany would reject the account at login.
            if (in_array($role, User::CORPORATE_ROLES, true)) {
                $payload['company_id'] = $this->makeCompany(['status' => Company::STATUS_APPROVED])->id;
            }

            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/users/{$target->id}/role", $payload)
                ->assertOk();

            $this->assertSame($role, $target->fresh()->role, "role {$role} must be assignable");
        }
    }

    public function test_corporate_role_requires_a_linked_company_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'company'])
            ->assertStatus(422);

        $this->assertSame('student', $target->fresh()->role);

        // With a profile it succeeds and the link is persisted.
        $company = $this->makeCompany(['status' => Company::STATUS_APPROVED]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", [
                'role' => 'company',
                'company_id' => $company->id,
            ])
            ->assertOk();

        $this->assertSame('company', $target->fresh()->role);
        $this->assertSame($company->id, $target->fresh()->company_id);
    }

    public function test_role_change_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'placement_advisor'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updated_user_role',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_last_administrator_cannot_be_demoted(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'student']);

        // Promote, leaving `super` as the only privileged account afterwards.
        $this->actingAs($super, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])
            ->assertOk();

        // Now demote the remaining admin while no super_admin exists.
        $admin = $target->fresh();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$admin->id}/role", ['role' => 'student'])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // 13. Existing admin/super_admin behaviour remains intact
    // ---------------------------------------------------------------------

    public function test_admin_and_super_admin_retain_full_access(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/admin/placements/stats')
                ->assertOk();

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/admin/placements/jobs/pending')
                ->assertOk();

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/admin/placements/mock-interviews/stats')
                ->assertOk();

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/admin/users')
                ->assertOk();
        }
    }

    // ---------------------------------------------------------------------
    // C5 — vocabulary and migration integrity
    // ---------------------------------------------------------------------

    public function test_placement_advisor_exists_in_the_roles_lookup_table(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(
            DB::table('roles')->where('name', 'placement_advisor')->exists()
        );
    }

    public function test_database_vocabulary_matches_the_model_vocabulary(): void
    {
        $stored = DB::table('roles')->orderBy('name')->pluck('name')->all();
        $expected = User::ROLES;
        sort($expected);

        $this->assertSame($expected, $stored);
    }

    public function test_placement_advisor_is_accepted_by_the_users_role_foreign_key(): void
    {
        // The FK, not just application validation.
        $user = User::factory()->create(['role' => 'placement_advisor']);

        $this->assertSame('placement_advisor', $user->fresh()->role);
    }

    public function test_role_vocabulary_contains_all_twelve_roles(): void
    {
        $this->assertCount(12, User::ROLES);
        $this->assertContains('placement_advisor', User::ROLES);
    }
}