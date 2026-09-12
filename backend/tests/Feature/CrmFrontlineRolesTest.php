<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRM frontline roles (telecaller ×4, course_advisor ×2 seeded):
 * route access, own+unassigned record isolation, reassignment rules,
 * payment-truth protection, and super_admin grant control.
 */
class CrmFrontlineRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $telecaller;
    private User $advisor;
    private Enquiry $ownLead;
    private Enquiry $otherLead;
    private Enquiry $openLead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telecaller = User::factory()->create(['role' => 'telecaller']);
        $this->advisor = User::factory()->create(['role' => 'course_advisor']);
        $other = User::factory()->create(['role' => 'telecaller']);

        $make = fn (string $email, ?int $owner) => Enquiry::create([
            'name' => 'Lead ' . $email,
            'email' => $email,
            'phone' => '9876543210',
            'course_name' => 'Full Stack',
            'status' => Enquiry::STATUS_NEW,
            'assigned_counsellor_id' => $owner,
        ]);

        $this->ownLead = $make('own-crm@example.com', $this->telecaller->id);
        $this->otherLead = $make('other-crm@example.com', $other->id);
        $this->openLead = $make('open-crm@example.com', null);
    }

    public function test_frontline_roles_reach_crm_but_not_admin_console(): void
    {
        foreach ([$this->telecaller, $this->advisor] as $staff) {
            Sanctum::actingAs($staff);
            $this->getJson('/api/admin/crm/leads')->assertOk();
            $this->getJson('/api/admin/enquiries')->assertOk();
            $this->getJson('/api/admin/users')->assertForbidden();
            $this->getJson('/api/admin/batches')->assertForbidden();
        }
    }

    public function test_scoped_listing_and_stats(): void
    {
        Sanctum::actingAs($this->telecaller);

        $emails = collect($this->getJson('/api/admin/crm/leads?per_page=50')->assertOk()->json('data'))
            ->pluck('email')->all();
        $this->assertContains('own-crm@example.com', $emails);
        $this->assertContains('open-crm@example.com', $emails);
        $this->assertNotContains('other-crm@example.com', $emails);

        $this->getJson('/api/admin/crm/stats')->assertOk()->assertJson(['total_leads' => 2]);
        $this->getJson('/api/admin/enquiries/stats')->assertOk()->assertJson(['total' => 2]);
    }

    public function test_cross_lead_access_blocked(): void
    {
        Sanctum::actingAs($this->telecaller);

        $this->getJson("/api/admin/crm/leads/{$this->otherLead->id}")->assertForbidden();
        $this->putJson("/api/admin/crm/leads/{$this->otherLead->id}", ['city' => 'X'])->assertForbidden();
        $this->deleteJson("/api/admin/crm/leads/{$this->otherLead->id}")->assertForbidden();
        $this->getJson("/api/admin/enquiries/{$this->otherLead->id}")->assertForbidden();
        $this->postJson("/api/admin/crm/leads/{$this->otherLead->id}/follow-ups", [
            'scheduled_at' => now()->addDay()->toISOString(), 'title' => 'x',
        ])->assertForbidden();

        $this->getJson("/api/admin/crm/leads/{$this->ownLead->id}")->assertOk();
    }

    public function test_reassignment_rules(): void
    {
        Sanctum::actingAs($this->telecaller);

        // Cannot hand own lead to a colleague.
        $this->putJson("/api/admin/crm/leads/{$this->ownLead->id}", [
            'assigned_counsellor_id' => $this->advisor->id,
        ])->assertForbidden();

        // Can claim an unassigned lead.
        $this->putJson("/api/admin/crm/leads/{$this->openLead->id}", [
            'assigned_counsellor_id' => $this->telecaller->id,
        ])->assertOk();
        $this->assertSame($this->telecaller->id, $this->openLead->fresh()->assigned_counsellor_id);

        // Cannot create a lead assigned to someone else.
        $this->postJson('/api/admin/crm/leads', [
            'name' => 'Sneaky', 'email' => 'sneaky-crm@example.com', 'phone' => '9000000001',
            'assigned_counsellor_id' => $this->advisor->id,
        ])->assertForbidden();
    }

    public function test_payment_truth_locked_to_admins(): void
    {
        Sanctum::actingAs($this->telecaller);

        // Direct financial write.
        $this->putJson("/api/admin/crm/leads/{$this->ownLead->id}", [
            'amount_paid' => 5000, 'payment_status' => 'paid',
        ])->assertForbidden();
        $this->assertSame(0.0, (float) $this->ownLead->fresh()->amount_paid);

        // Forged payment_event via metadata.
        $this->postJson("/api/admin/crm/leads/{$this->ownLead->id}/activities", [
            'activity_type' => 'payment_event',
            'title' => 'Fake UPI',
            'metadata' => ['amount' => 9999],
        ])->assertForbidden();
        $this->assertSame(0.0, (float) $this->ownLead->fresh()->amount_paid);

        // Plain note activity still works.
        $this->postJson("/api/admin/crm/leads/{$this->ownLead->id}/activities", [
            'activity_type' => 'note', 'title' => 'Called, no answer',
        ])->assertCreated();

        // Admin path unaffected.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/admin/crm/leads/{$this->ownLead->id}", [
            'amount_paid' => 5000,
        ])->assertOk();
        $this->assertSame(5000.0, (float) $this->ownLead->fresh()->amount_paid);
    }

    public function test_convert_with_amount_blocked_for_scoped_staff(): void
    {
        $course = Course::create([
            'title' => 'Convert Guard', 'slug' => 'convert-guard-' . uniqid(),
            'description' => 'x', 'instructor' => 'x',
            'duration' => '1 Week', 'difficulty' => 'Beginner', 'is_published' => true,
        ]);

        Sanctum::actingAs($this->advisor);
        $this->postJson("/api/admin/crm/leads/{$this->openLead->id}/convert", [
            'course_id' => $course->id,
            'amount_paid' => 10000,
        ])->assertForbidden();

        // Conversion without money still works for advisors.
        $this->postJson("/api/admin/crm/leads/{$this->openLead->id}/convert", [
            'course_id' => $course->id,
        ])->assertOk();
        $this->assertSame(0.0, (float) $this->openLead->fresh()->amount_paid);
    }

    public function test_role_provisioning_and_super_admin_guard(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/admin/users', [
            'name' => 'New Telecaller', 'email' => 'new-tc@example.com', 'role' => 'telecaller',
        ])->assertCreated();

        // Admins cannot mint super_admin.
        $this->postJson('/api/admin/users', [
            'name' => 'Sneaky Root', 'email' => 'sneaky-root@example.com', 'role' => 'super_admin',
        ])->assertForbidden();

        $victim = User::factory()->create(['role' => 'student']);
        $this->putJson("/api/admin/users/{$victim->id}/role", ['role' => 'super_admin'])
            ->assertForbidden();

        // Super_admin can.
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->putJson("/api/admin/users/{$victim->id}/role", ['role' => 'super_admin'])
            ->assertOk();
        $this->assertSame('super_admin', $victim->fresh()->role);
    }

    public function test_duplicate_probe_hides_others_lead(): void
    {
        Sanctum::actingAs($this->telecaller);

        $res = $this->postJson('/api/admin/crm/leads', [
            'name' => 'Probe', 'email' => 'other-crm@example.com', 'phone' => '9000000002',
        ])->assertStatus(422);

        $this->assertArrayNotHasKey('lead', $res->json());
    }
}
