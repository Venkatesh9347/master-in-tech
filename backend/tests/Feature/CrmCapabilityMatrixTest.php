<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CrmCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C4 — CRM role capability matrix.
 *
 * These tests document the boundary the controllers ACTUALLY enforce today
 * rather than inventing new restrictions. Evidence for each row is recorded in
 * CrmCapabilities' class docblock with controller line references.
 *
 * The three frontline roles (counsellor, telecaller, course_advisor) currently
 * share one scoped tier. That is asserted explicitly so that if someone later
 * differentiates them, this test fails and forces a deliberate decision.
 */
class CrmCapabilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Matrix shape
    // ---------------------------------------------------------------------

    public function test_matrix_covers_every_crm_reachable_role(): void
    {
        $this->assertSame(
            ['super_admin', 'admin', 'counsellor', 'telecaller', 'course_advisor'],
            array_keys(CrmCapabilities::matrix())
        );
    }

    public function test_admin_and_super_admin_receive_the_full_matrix(): void
    {
        $full = CrmCapabilities::adminCapabilities();

        foreach (['admin', 'super_admin'] as $role) {
            $this->assertSame(
                $full,
                CrmCapabilities::matrix()[$role],
                "{$role} must retain the complete CRM matrix"
            );
        }
    }

    public function test_frontline_roles_share_one_scoped_tier(): void
    {
        $scoped = CrmCapabilities::scopedCapabilities();

        foreach (['counsellor', 'telecaller', 'course_advisor'] as $role) {
            $this->assertSame(
                $scoped,
                CrmCapabilities::matrix()[$role],
                "{$role} currently shares the scoped tier"
            );
        }
    }

    public function test_scoped_tier_never_exceeds_the_administrative_tier(): void
    {
        $extra = array_diff(
            CrmCapabilities::scopedCapabilities(),
            CrmCapabilities::adminCapabilities()
        );

        $this->assertSame(
            [],
            array_values($extra),
            'A scoped role must never hold a capability an admin lacks'
        );
    }

    // ---------------------------------------------------------------------
    // Capability resolution
    // ---------------------------------------------------------------------

    public function test_admins_hold_every_capability(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (CrmCapabilities::adminCapabilities() as $capability) {
            $this->assertTrue(
                CrmCapabilities::allows($admin, $capability),
                "admin must hold {$capability}"
            );
        }
    }

    public function test_scoped_roles_hold_operational_capabilities(): void
    {
        foreach (['counsellor', 'telecaller', 'course_advisor'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ([
                CrmCapabilities::READ_LEADS,
                CrmCapabilities::WRITE_LEADS,
                CrmCapabilities::CLAIM_LEADS,
                CrmCapabilities::CONVERT_LEADS,
                CrmCapabilities::READ_FOLLOW_UPS,
                CrmCapabilities::WRITE_OWN_FOLLOW_UPS,
                CrmCapabilities::READ_CALL_RECORDINGS,
                CrmCapabilities::WRITE_CALL_RECORDINGS,
            ] as $capability) {
                $this->assertTrue(
                    CrmCapabilities::allows($user, $capability),
                    "{$role} must hold {$capability}"
                );
            }
        }
    }

    public function test_scoped_roles_are_denied_privileged_capabilities(): void
    {
        $restricted = [
            CrmCapabilities::DELETE_LEADS,
            CrmCapabilities::REASSIGN_LEADS,
            CrmCapabilities::WRITE_ANY_FOLLOW_UP,
            CrmCapabilities::DELETE_CALL_RECORDINGS,
            CrmCapabilities::WRITE_FINANCE,
            CrmCapabilities::CONFIRM_ADMISSION,
        ];

        foreach (['counsellor', 'telecaller', 'course_advisor'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($restricted as $capability) {
                $this->assertFalse(
                    CrmCapabilities::allows($user, $capability),
                    "{$role} must NOT hold {$capability}"
                );
            }
        }
    }

    public function test_non_crm_roles_hold_no_capabilities(): void
    {
        foreach (['student', 'tutor', 'faculty', 'placement_advisor', 'company', 'recruiter'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->assertSame(
                [],
                CrmCapabilities::capabilitiesFor($user),
                "{$role} must hold no CRM capability"
            );
        }
    }

    public function test_guest_holds_no_capabilities(): void
    {
        $this->assertSame([], CrmCapabilities::capabilitiesFor(null));
        $this->assertFalse(CrmCapabilities::allows(null, CrmCapabilities::READ_LEADS));
    }

    public function test_placement_advisor_is_absent_from_the_crm_matrix(): void
    {
        $this->assertArrayNotHasKey('placement_advisor', CrmCapabilities::matrix());

        $advisor = User::factory()->create(['role' => 'placement_advisor']);

        $this->assertFalse($advisor->canAccessCrm());
        $this->assertSame([], CrmCapabilities::capabilitiesFor($advisor));
    }

    // ---------------------------------------------------------------------
    // Backend remains authoritative
    // ---------------------------------------------------------------------

    public function test_frontline_roles_retain_crm_endpoint_access(): void
    {
        foreach (['counsellor', 'telecaller', 'course_advisor'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            // Reaches the controller (not a 403 from the guard). An empty CRM
            // returns a successful empty payload.
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/admin/crm/leads')
                ->assertOk();
        }
    }

    public function test_admin_still_reaches_the_full_crm_surface(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/crm/leads')
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/crm/stats')
            ->assertOk();
    }

    public function test_placement_advisor_cannot_reach_crm_endpoints(): void
    {
        $advisor = User::factory()->create(['role' => 'placement_advisor']);

        $this->actingAs($advisor, 'sanctum')
            ->getJson('/api/admin/crm/leads')
            ->assertForbidden();
    }

    public function test_student_cannot_reach_crm_endpoints(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/crm/leads')
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Reporting surface
    // ---------------------------------------------------------------------

    public function test_report_produces_a_boolean_matrix_for_documentation(): void
    {
        $report = CrmCapabilities::report();

        $this->assertArrayHasKey('admin', $report);
        $this->assertArrayHasKey('telecaller', $report);

        $this->assertTrue($report['admin'][CrmCapabilities::WRITE_FINANCE]);
        $this->assertFalse($report['telecaller'][CrmCapabilities::WRITE_FINANCE]);
    }
}