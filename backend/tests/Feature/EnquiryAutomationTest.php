<?php

namespace Tests\Feature;

use App\Automation\AutomationExecution;
use App\Automation\CrmAutomation;
use App\DomainEvents\DomainEvent;
use App\DomainEvents\DomainEventBus;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CrmActivity;
use App\Models\CrmFollowUp;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9-D1: new-enquiry first follow-up automation.
 *
 * Portable across SQLite/MySQL/PostgreSQL: no PRAGMA, no engine-specific
 * error text, no hardcoded driver names.
 */
class EnquiryAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function publicEnquiryPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Prospective Student',
            'email' => 'prospect' . Str::random(6) . '@example.com',
            'phone' => '+910000000003',
        ], $overrides);
    }

    private function firstFollowUpFor(int $enquiryId): ?CrmFollowUp
    {
        return CrmFollowUp::where('enquiry_id', $enquiryId)->first();
    }

    private function automationActivities(int $enquiryId): int
    {
        return CrmActivity::where('enquiry_id', $enquiryId)
            ->where('activity_type', 'follow_up')
            ->where('title', 'like', 'First follow-up%')
            ->count();
    }

    public function test_public_creation_emits_event_and_creates_first_followup(): void
    {
        $response = $this->postJson('/api/enquiries', $this->publicEnquiryPayload());
        $response->assertStatus(201);

        $enquiryId = (int) $response->json('enquiry.id');

        $followUp = $this->firstFollowUpFor($enquiryId);
        $this->assertNotNull($followUp);
        $this->assertSame(1, CrmFollowUp::where('enquiry_id', $enquiryId)->count());
        $this->assertSame(1, AutomationExecution::where('handler', 'crm.first-followup')->count());
    }

    public function test_first_followup_is_scheduled_t_plus_one_day(): void
    {
        $enquiryId = (int) $this->postJson('/api/enquiries', $this->publicEnquiryPayload())
            ->assertStatus(201)->json('enquiry.id');

        $enquiry = Enquiry::find($enquiryId);
        $followUp = $this->firstFollowUpFor($enquiryId);

        $this->assertSame(
            $enquiry->created_at->copy()->addDay()->format('Y-m-d H:i:s'),
            $followUp->scheduled_at->format('Y-m-d H:i:s')
        );
        $this->assertSame('pending', $followUp->status);
    }

    public function test_public_followup_is_unassigned_and_enquiry_untouched(): void
    {
        $enquiryId = (int) $this->postJson('/api/enquiries', $this->publicEnquiryPayload())
            ->assertStatus(201)->json('enquiry.id');

        $followUp = $this->firstFollowUpFor($enquiryId);
        $this->assertNull($followUp->assigned_to);

        $enquiry = Enquiry::find($enquiryId);
        $this->assertSame('new', $enquiry->status);
        $this->assertNull($enquiry->assigned_counsellor_id);
    }

    public function test_admin_creation_copies_owner_and_creates_followup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $counsellor = User::factory()->create(['role' => 'counsellor']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/crm/leads', [
            'name' => 'Admin Lead',
            'email' => 'adminlead' . Str::random(6) . '@example.com',
            'phone' => '+910000000004',
            'assigned_counsellor_id' => $counsellor->id,
        ]);
        $response->assertStatus(201);

        $enquiryId = (int) $response->json('lead.id');

        $followUp = $this->firstFollowUpFor($enquiryId);
        $this->assertNotNull($followUp);
        $this->assertSame($counsellor->id, (int) $followUp->assigned_to);

        $enquiry = Enquiry::find($enquiryId);
        $this->assertSame($counsellor->id, (int) $enquiry->assigned_counsellor_id);
    }

    public function test_admin_supplied_initial_followup_prevents_automation_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/crm/leads', [
            'name' => 'Manual Followup Lead',
            'email' => 'manualfu' . Str::random(6) . '@example.com',
            'phone' => '+910000000005',
            'next_follow_up_date' => now()->addDays(3)->format('Y-m-d'),
        ]);
        $response->assertStatus(201);

        $enquiryId = (int) $response->json('lead.id');

        // Exactly one follow-up total: the manually scheduled initial one.
        $this->assertSame(1, CrmFollowUp::where('enquiry_id', $enquiryId)->count());
        $this->assertSame(0, $this->automationActivities($enquiryId));
    }

    public function test_duplicate_public_submission_does_not_duplicate_followup(): void
    {
        $payload = $this->publicEnquiryPayload();

        $this->postJson('/api/enquiries', $payload)->assertStatus(201);
        $second = $this->postJson('/api/enquiries', $payload);
        $second->assertOk()->assertJsonPath('already_exists', true);

        $enquiryId = (int) $second->json('enquiry.id');
        $this->assertSame(1, CrmFollowUp::where('enquiry_id', $enquiryId)->count());
    }

    public function test_automation_activity_and_audit(): void
    {
        $enquiryId = (int) $this->postJson('/api/enquiries', $this->publicEnquiryPayload())
            ->assertStatus(201)->json('enquiry.id');

        $this->assertSame(1, $this->automationActivities($enquiryId));

        $activity = CrmActivity::where('enquiry_id', $enquiryId)->first();
        $followUp = $this->firstFollowUpFor($enquiryId);
        $this->assertSame($followUp->id, (int) $activity->metadata['follow_up_id']);
        $this->assertNotEmpty($activity->metadata['trigger_event_id']);

        $audit = AuditLog::where('action', 'automation_first_followup')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Automation', $audit->user_name);
        $this->assertNull($audit->user_id);
    }

    public function test_replay_same_event_does_not_duplicate(): void
    {
        $enquiryId = (int) $this->postJson('/api/enquiries', $this->publicEnquiryPayload())
            ->assertStatus(201)->json('enquiry.id');

        $enquiry = Enquiry::find($enquiryId);
        $event = new DomainEvent('enquiry.created', ['enquiry_id' => $enquiryId]);

        app(DomainEventBus::class)->dispatch($event);
        app(DomainEventBus::class)->dispatch($event);

        $this->assertSame(1, CrmFollowUp::where('enquiry_id', $enquiryId)->count());
        $this->assertSame(1, $this->automationActivities($enquiryId));
    }

    public function test_missing_enquiry_is_safe_noop(): void
    {
        DomainEventBus::record('enquiry.created', ['enquiry_id' => 99999999]);

        $this->assertSame(0, CrmFollowUp::count());
        $this->assertSame(0, AutomationExecution::where('handler', 'crm.first-followup')->count());
    }

    public function test_terminal_enquiry_gets_no_followup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/crm/leads', [
            'name' => 'Closed Lead',
            'email' => 'closedlead' . Str::random(6) . '@example.com',
            'phone' => '+910000000006',
            'status' => 'closed',
        ]);
        $response->assertStatus(201);

        $enquiryId = (int) $response->json('lead.id');
        $this->assertSame('closed', Enquiry::find($enquiryId)->status);
        $this->assertSame(0, CrmFollowUp::where('enquiry_id', $enquiryId)->count());
    }

    public function test_rollback_leaves_no_automation_side_effects(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        try {
            DB::transaction(function () use ($student): void {
                $enquiry = Enquiry::create([
                    'user_id' => $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                    'phone' => '+910000000007',
                    'status' => 'new',
                ]);

                DomainEventBus::record(CrmAutomation::EVENT_ENQUIRY_CREATED, [
                    'enquiry_id' => $enquiry->id,
                ]);

                throw new \RuntimeException('Simulated business failure.');
            });

            $this->fail('The simulated failure should have rolled back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated business failure.', $e->getMessage());
        }

        $this->assertSame(0, Enquiry::count());
        $this->assertSame(0, CrmFollowUp::count());
        $this->assertSame(0, AutomationExecution::where('handler', 'crm.first-followup')->count());
    }

    public function test_course_interest_is_preserved_without_prerequisite_semantics(): void
    {
        $title = 'Interest Course ' . Str::random(6);
        $course = Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Interest fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);

        $enquiryId = (int) $this->postJson('/api/enquiries', $this->publicEnquiryPayload([
            'course_id' => $course->id,
        ]))->assertStatus(201)->json('enquiry.id');

        $enquiry = Enquiry::find($enquiryId);
        $this->assertSame($course->id, (int) $enquiry->course_id);
        $this->assertNotNull($this->firstFollowUpFor($enquiryId));
    }
}
