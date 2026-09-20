<?php

namespace Tests\Feature;

use App\Automation\AutomationExecution;
use App\Automation\CrmAutomation;
use App\Console\Commands\ProcessDueCrmFollowUps;
use App\DomainEvents\DomainEvent;
use App\DomainEvents\DomainEventBus;
use App\Models\CrmActivity;
use App\Models\CrmFollowUp;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 9-C2: scheduler-detected due follow-ups emit stable followup.due
 * domain events consumed by code-defined CRM automation.
 *
 * Portable across SQLite/MySQL/PostgreSQL: no PRAGMA, no engine-specific
 * error text, no hardcoded driver names.
 */
class CrmFollowUpSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function makeEnquiry(string $status = 'interested'): Enquiry
    {
        $student = User::factory()->create(['role' => 'student']);

        return Enquiry::create([
            'user_id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => '+910000000002',
            'status' => $status,
        ]);
    }

    private function makeFollowUp(Enquiry $enquiry, string $scheduledAt, string $status = 'pending'): CrmFollowUp
    {
        return CrmFollowUp::create([
            'enquiry_id' => $enquiry->id,
            'scheduled_at' => $scheduledAt,
            'status' => $status,
            'title' => 'Call back about fees',
        ]);
    }

    private function runCommand(): void
    {
        $this->artisan('mit:process-due-crm-followups')->assertExitCode(0);
    }

    private function dueActivities(int $enquiryId): int
    {
        return CrmActivity::where('enquiry_id', $enquiryId)
            ->where('activity_type', 'follow_up')
            ->where('title', 'like', 'Follow-up due:%')
            ->count();
    }

    public function test_future_follow_up_is_ignored(): void
    {
        $enquiry = $this->makeEnquiry();
        $this->makeFollowUp($enquiry, now()->addDays(2)->format('Y-m-d H:i:s'));

        $this->runCommand();

        $this->assertSame(0, $this->dueActivities($enquiry->id));
        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_due_follow_up_produces_exactly_one_activity(): void
    {
        $enquiry = $this->makeEnquiry();
        $followUp = $this->makeFollowUp($enquiry, now()->subHour()->format('Y-m-d H:i:s'));

        $this->runCommand();

        $this->assertSame(1, $this->dueActivities($enquiry->id));

        $activity = CrmActivity::where('enquiry_id', $enquiry->id)->first();
        $this->assertSame($followUp->id, (int) $activity->metadata['follow_up_id']);
        $this->assertNotEmpty($activity->metadata['trigger_event_id']);

        // Follow-up and enquiry state are untouched (informational only).
        $this->assertSame('pending', $followUp->fresh()->status);
        $this->assertSame('interested', $enquiry->fresh()->status);
    }

    public function test_repeated_runs_converge_on_stable_event_id(): void
    {
        $enquiry = $this->makeEnquiry();
        $this->makeFollowUp($enquiry, now()->subHour()->format('Y-m-d H:i:s'));

        $this->runCommand();
        $firstId = AutomationExecution::first()->event_id;

        $this->runCommand();

        $this->assertSame(1, $this->dueActivities($enquiry->id));
        $this->assertSame(1, AutomationExecution::count());
        $this->assertSame($firstId, AutomationExecution::first()->event_id);
        $this->assertSame('success', AutomationExecution::first()->status);
    }

    public function test_rescheduled_follow_up_is_a_new_occurrence(): void
    {
        $enquiry = $this->makeEnquiry();
        $followUp = $this->makeFollowUp($enquiry, now()->subHours(3)->format('Y-m-d H:i:s'));

        $this->runCommand();
        $firstId = AutomationExecution::first()->event_id;

        $followUp->update(['scheduled_at' => now()->subHour()->format('Y-m-d H:i:s')]);

        $this->runCommand();

        $this->assertSame(2, $this->dueActivities($enquiry->id));
        $this->assertSame(2, AutomationExecution::count());
        $this->assertNotSame($firstId, AutomationExecution::orderBy('id', 'desc')->first()->event_id);
    }

    public function test_completed_and_cancelled_follow_ups_are_ignored(): void
    {
        $enquiry = $this->makeEnquiry();
        $this->makeFollowUp($enquiry, now()->subHour()->format('Y-m-d H:i:s'), 'completed');
        $this->makeFollowUp($enquiry, now()->subHours(2)->format('Y-m-d H:i:s'), 'cancelled');

        $this->runCommand();

        $this->assertSame(0, $this->dueActivities($enquiry->id));
        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_completed_before_automation_safely_noops(): void
    {
        $enquiry = $this->makeEnquiry();
        $followUp = $this->makeFollowUp($enquiry, now()->subHour()->format('Y-m-d H:i:s'));

        // Simulate a stale scheduler selection: the follow-up completed
        // after selection but before the handler executes.
        $followUp->update(['status' => 'completed', 'completed_at' => now()]);

        DomainEventBus::record('followup.due', [
            'follow_up_id' => $followUp->id,
            'enquiry_id' => $enquiry->id,
            'assigned_to' => null,
            'scheduled_at' => $followUp->scheduled_at?->toISOString(),
        ], ProcessDueCrmFollowUps::eventIdFor($followUp->fresh()));

        $this->assertSame(0, $this->dueActivities($enquiry->id));
        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_multiple_due_follow_ups_are_processed_independently(): void
    {
        $enquiry = $this->makeEnquiry();
        $this->makeFollowUp($enquiry, now()->subHours(1)->format('Y-m-d H:i:s'));
        $this->makeFollowUp($enquiry, now()->subHours(2)->format('Y-m-d H:i:s'));

        $this->runCommand();

        $this->assertSame(2, $this->dueActivities($enquiry->id));
        $this->assertSame(2, AutomationExecution::count());
        $this->assertSame(2, AutomationExecution::where('status', 'success')->count());
    }

    public function test_retry_after_partial_failure_does_not_duplicate_activity(): void
    {
        $enquiry = $this->makeEnquiry();
        $followUp = $this->makeFollowUp($enquiry, now()->subHour()->format('Y-m-d H:i:s'));
        $stableId = ProcessDueCrmFollowUps::eventIdFor($followUp->fresh());

        // Simulate the partial-failure window: the timeline activity was
        // committed, but the automation execution never reached success.
        $activity = CrmActivity::create([
            'enquiry_id' => $enquiry->id,
            'user_id' => null,
            'activity_type' => 'follow_up',
            'title' => 'Follow-up due: Call back about fees',
            'description' => "Follow-up #{$followUp->id} became due and requires attention.",
            'metadata' => [
                'follow_up_id' => $followUp->id,
                'trigger_event_id' => $stableId,
                'scheduled_at' => $followUp->scheduled_at?->toISOString(),
            ],
        ]);
        AutomationExecution::create([
            'event_id' => $stableId,
            'handler' => CrmAutomation::HANDLER_FOLLOWUP_DUE,
            'entity_type' => CrmFollowUp::class,
            'entity_id' => $followUp->id,
            'status' => 'failed',
            'error' => 'Simulated audit failure.',
        ]);

        // Next scheduler tick re-presents the same stable event: reclaim,
        // guard on trigger_event_id, no duplicate activity.
        DomainEventBus::record('followup.due', [
            'follow_up_id' => $followUp->id,
            'enquiry_id' => $enquiry->id,
            'assigned_to' => null,
            'scheduled_at' => $followUp->scheduled_at?->toISOString(),
        ], $stableId);

        $this->assertSame(1, $this->dueActivities($enquiry->id));

        $remaining = CrmActivity::where('enquiry_id', $enquiry->id)
            ->where('activity_type', 'follow_up')
            ->whereJsonContains('metadata->trigger_event_id', $stableId)
            ->get();
        $this->assertCount(1, $remaining);
        $this->assertSame($activity->id, $remaining->first()->id);

        $execution = AutomationExecution::where('event_id', $stableId)->first();
        $this->assertSame('success', $execution->status);
    }

    public function test_duplicate_evaluation_inside_transaction_does_not_throw(): void
    {
        // Regression for the PostgreSQL-abort hazard: contention must be
        // resolved without ever raising a unique violation, so the
        // surrounding transaction stays usable on every engine.
        $enquiry = $this->makeEnquiry();
        $followUp = $this->makeFollowUp($enquiry, now()->subHour()->format('Y-m-d H:i:s'));

        $payload = [
            'follow_up_id' => $followUp->id,
            'enquiry_id' => $enquiry->id,
            'assigned_to' => null,
            'scheduled_at' => $followUp->scheduled_at?->toISOString(),
        ];
        $stableId = ProcessDueCrmFollowUps::eventIdFor($followUp->fresh());

        DB::transaction(function () use ($payload, $stableId): void {
            DomainEventBus::record('followup.due', $payload, $stableId);
            DomainEventBus::record('followup.due', $payload, $stableId);
        });

        $this->assertSame(1, $this->dueActivities($enquiry->id));
        $this->assertSame(1, AutomationExecution::count());
    }

    public function test_stable_event_id_format_and_reschedule_difference(): void
    {
        $enquiry = $this->makeEnquiry();
        $followUp = $this->makeFollowUp($enquiry, now()->subHour()->format('Y-m-d H:i:s'));

        $first = ProcessDueCrmFollowUps::eventIdFor($followUp->fresh());
        $again = ProcessDueCrmFollowUps::eventIdFor($followUp->fresh());

        $this->assertSame(64, strlen($first));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame($first, $again);

        $followUp->update(['scheduled_at' => now()->subMinutes(30)->format('Y-m-d H:i:s')]);

        $this->assertNotSame(
            $first,
            ProcessDueCrmFollowUps::eventIdFor($followUp->fresh())
        );
    }

    public function test_domain_event_accepts_explicit_id_and_defaults_to_uuid(): void
    {
        $explicit = new DomainEvent('followup.due', [], 'stable-id-123');
        $this->assertSame('stable-id-123', $explicit->id);

        $default = new DomainEvent('followup.due', []);
        $this->assertNotEmpty($default->id);
        $this->assertNotSame($explicit->id, $default->id);
        $this->assertNotEmpty($default->occurredAt);
    }
}
