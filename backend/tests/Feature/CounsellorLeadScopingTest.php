<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Record-level CRM isolation: counsellors see and mutate only their own
 * plus unassigned leads. Admins remain unrestricted.
 */
class CounsellorLeadScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $counsellorA;
    private User $counsellorB;
    private Enquiry $ownLead;
    private Enquiry $otherLead;
    private Enquiry $unassignedLead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->counsellorA = User::factory()->create(['role' => 'counsellor']);
        $this->counsellorB = User::factory()->create(['role' => 'counsellor']);

        $make = fn (string $email, ?int $owner) => Enquiry::create([
            'name' => 'Lead ' . $email,
            'email' => $email,
            'phone' => '9876543210',
            'course_name' => 'Full Stack',
            'status' => Enquiry::STATUS_NEW,
            'assigned_counsellor_id' => $owner,
        ]);

        $this->ownLead = $make('own@example.com', $this->counsellorA->id);
        $this->otherLead = $make('other@example.com', $this->counsellorB->id);
        $this->unassignedLead = $make('open@example.com', null);
    }

    private function actingAsCounsellorA(): void
    {
        Sanctum::actingAs($this->counsellorA);
    }

    public function test_counsellor_lists_only_own_and_unassigned_leads(): void
    {
        $this->actingAsCounsellorA();

        $crm = $this->getJson('/api/admin/crm/leads?per_page=50')->assertOk()->json('data');
        $emails = collect($crm)->pluck('email')->all();
        $this->assertContains('own@example.com', $emails);
        $this->assertContains('open@example.com', $emails);
        $this->assertNotContains('other@example.com', $emails);

        $pipe = $this->getJson('/api/admin/enquiries')->assertOk()->json();
        $pipeEmails = collect($pipe)->pluck('email')->all();
        $this->assertContains('own@example.com', $pipeEmails);
        $this->assertContains('open@example.com', $pipeEmails);
        $this->assertNotContains('other@example.com', $pipeEmails);
    }

    public function test_counsellor_stats_are_scoped(): void
    {
        $this->actingAsCounsellorA();

        $this->getJson('/api/admin/crm/stats')->assertOk()->assertJson(['total_leads' => 2]);
        $this->getJson('/api/admin/enquiries/stats')->assertOk()->assertJson(['total' => 2]);
    }

    public function test_counsellor_cannot_read_others_lead(): void
    {
        $this->actingAsCounsellorA();

        $this->getJson("/api/admin/crm/leads/{$this->otherLead->id}")->assertForbidden();
        $this->getJson("/api/admin/enquiries/{$this->otherLead->id}")->assertForbidden();

        $this->getJson("/api/admin/crm/leads/{$this->ownLead->id}")->assertOk();
        $this->getJson("/api/admin/crm/leads/{$this->unassignedLead->id}")->assertOk();
    }

    public function test_counsellor_cannot_mutate_others_lead(): void
    {
        $this->actingAsCounsellorA();
        $course = Course::create([
            'title' => 'Scope Fixture', 'slug' => 'scope-fixture-' . uniqid(),
            'description' => 'x', 'instructor' => 'x',
            'duration' => '1 Week', 'difficulty' => 'Beginner', 'is_published' => true,
        ]);

        $this->putJson("/api/admin/crm/leads/{$this->otherLead->id}", ['city' => 'Mumbai'])->assertForbidden();
        $this->deleteJson("/api/admin/crm/leads/{$this->otherLead->id}")->assertForbidden();
        $this->postJson("/api/admin/crm/leads/{$this->otherLead->id}/convert", ['course_id' => $course->id])->assertForbidden();
        $this->postJson("/api/admin/enquiries/{$this->otherLead->id}/enroll", ['course_id' => $course->id])->assertForbidden();
        $this->postJson("/api/admin/enquiries/{$this->otherLead->id}/notes", ['note' => 'hi'])->assertForbidden();
        $this->postJson("/api/admin/crm/leads/{$this->otherLead->id}/activities", [
            'activity_type' => 'note', 'title' => 'x',
        ])->assertForbidden();
        $this->postJson("/api/admin/crm/leads/{$this->otherLead->id}/follow-ups", [
            'scheduled_at' => now()->addDay()->toISOString(), 'title' => 'x',
        ])->assertForbidden();

        // Own lead remains fully workable.
        $this->putJson("/api/admin/crm/leads/{$this->ownLead->id}", ['city' => 'Mumbai'])->assertOk();
    }

    public function test_counsellor_reassignment_rules(): void
    {
        $this->actingAsCounsellorA();

        // Cannot hand own lead to another counsellor.
        $this->putJson("/api/admin/crm/leads/{$this->ownLead->id}", [
            'assigned_counsellor_id' => $this->counsellorB->id,
        ])->assertForbidden();

        // Can claim an unassigned lead.
        $this->putJson("/api/admin/crm/leads/{$this->unassignedLead->id}", [
            'assigned_counsellor_id' => $this->counsellorA->id,
        ])->assertOk();
        $this->assertSame($this->counsellorA->id, $this->unassignedLead->fresh()->assigned_counsellor_id);

        // Cannot create a lead assigned to someone else.
        $this->postJson('/api/admin/crm/leads', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'phone' => '9000000001',
            'assigned_counsellor_id' => $this->counsellorB->id,
        ])->assertForbidden();

        // Admins remain unrestricted.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/admin/crm/leads/{$this->ownLead->id}", [
            'assigned_counsellor_id' => $this->counsellorB->id,
        ])->assertOk();
    }

    public function test_duplicate_probe_does_not_leak_others_lead(): void
    {
        $this->actingAsCounsellorA();

        $res = $this->postJson('/api/admin/crm/leads', [
            'name' => 'Probe', 'email' => 'other@example.com', 'phone' => '9000000002',
        ])->assertStatus(422);

        $this->assertArrayNotHasKey('lead', $res->json());
    }

    public function test_follow_up_surfaces_are_scoped(): void
    {
        $otherFu = \App\Models\CrmFollowUp::create([
            'enquiry_id' => $this->otherLead->id,
            'assigned_to' => $this->counsellorB->id,
            'created_by' => $this->counsellorB->id,
            'scheduled_at' => now()->addDay(),
            'status' => \App\Models\CrmFollowUp::STATUS_PENDING,
            'title' => 'Other follow-up',
        ]);

        $this->actingAsCounsellorA();

        $list = $this->getJson('/api/admin/crm/follow-ups?filter=pending')->assertOk()->json();
        $this->assertNotContains($otherFu->id, collect($list)->pluck('id')->all());

        $this->putJson("/api/admin/crm/follow-ups/{$otherFu->id}", [
            'status' => 'completed',
        ])->assertForbidden();
    }

    public function test_admin_sees_everything(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/admin/crm/stats')->assertOk()->assertJson(['total_leads' => 3]);
        $this->getJson("/api/admin/crm/leads/{$this->otherLead->id}")->assertOk();
    }
}
