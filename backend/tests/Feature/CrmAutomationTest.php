<?php

namespace Tests\Feature;

use App\Automation\AutomationExecution;
use App\Automation\AutomationRunner;
use App\DomainEvents\DomainEvent;
use App\DomainEvents\DomainEventBus;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CrmActivity;
use App\Models\Enquiry;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Code-defined CRM automation over the domain-event bus.
 *
 * Portable across SQLite/MySQL/PostgreSQL: no PRAGMA, no engine-specific
 * error text, no hardcoded driver names.
 */
class CrmAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(string $prefix = 'Course'): Course
    {
        $title = $prefix . ' ' . Str::random(6);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Automation fixture course.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 1000.00,
        ]);
    }

    private function makeEnquiry(User $student, Course $course, string $status = 'interested'): Enquiry
    {
        return Enquiry::create([
            'user_id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => '+910000000001',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => $status,
        ]);
    }

    public function test_enrollment_created_syncs_open_enquiry(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('Sync');
        $enquiry = $this->makeEnquiry($student, $course);

        $enrollment = CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->assertSame('enrolled', $enquiry->fresh()->status);
        $this->assertSame(1, CrmActivity::where('enquiry_id', $enquiry->id)
            ->where('activity_type', 'status_change')->count());
        $this->assertSame(1, AutomationExecution::where('handler', 'crm.enquiry-sync')->count());

        $audit = AuditLog::where('action', 'automation_enquiry_enrolled')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Automation', $audit->user_name);
        $this->assertNull($audit->user_id);

        // Re-evaluating for the same enrollment must not duplicate the action
        // (terminal guard; same-event redelivery is covered by claim rows).
        DomainEventBus::record('enrollment.created', ['enrollment_id' => $enrollment->id]);

        $this->assertSame(1, CrmActivity::where('enquiry_id', $enquiry->id)
            ->where('activity_type', 'status_change')->count());
    }

    public function test_enrollment_without_enquiry_is_a_safe_noop(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('NoLead');

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->assertSame(1, AutomationExecution::where('handler', 'crm.enquiry-sync')->count());
        $this->assertSame(0, CrmActivity::count());
    }

    public function test_terminal_enquiry_is_never_reopened(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('Closed');
        $enquiry = $this->makeEnquiry($student, $course, 'closed');

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->assertSame('closed', $enquiry->fresh()->status);
        $this->assertSame(0, CrmActivity::count());
    }

    public function test_payment_refunded_appends_enquiry_timeline(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('Refund');
        $enquiry = $this->makeEnquiry($student, $course, 'interested');

        $tx = PaymentTransaction::create([
            'provider' => 'stub',
            'order_id' => 'order_auto_' . Str::random(8),
            'payment_id' => 'pay_auto_' . Str::random(8),
            'idempotency_key' => 'auto-key-' . Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => 50000,
            'currency' => 'INR',
            'status' => 'refunded',
        ]);

        DomainEventBus::record('payment.refunded', [
            'payment_transaction_id' => $tx->id,
            'amount_paise' => 50000,
            'currency' => 'INR',
        ]);

        // Enquiry admission state is untouched; only a timeline entry lands.
        $this->assertSame('interested', $enquiry->fresh()->status);
        $this->assertSame(1, CrmActivity::where('enquiry_id', $enquiry->id)
            ->where('activity_type', 'payment_event')->count());
        $this->assertSame(1, AutomationExecution::where('handler', 'crm.refund-note')->count());
    }

    public function test_rolled_back_enrollment_leaves_no_execution(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('RbAuto');
        $enquiry = $this->makeEnquiry($student, $course);

        try {
            DB::transaction(function () use ($student, $course): void {
                CourseEnrollment::create([
                    'user_id' => $student->id,
                    'course_id' => $course->id,
                    'status' => 'active',
                ]);

                throw new \RuntimeException('Simulated business failure.');
            });

            $this->fail('The simulated failure should have rolled back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated business failure.', $e->getMessage());
        }

        $this->assertSame(0, AutomationExecution::count());
        $this->assertSame('interested', $enquiry->fresh()->status);
        $this->assertSame(0, CrmActivity::count());
    }

    public function test_failing_handler_is_recorded_without_breaking_the_bus(): void
    {
        $event = new DomainEvent('test.automation', []);

        AutomationRunner::run('test.failing', $event, null, null, function (): void {
            throw new \RuntimeException('Boom.');
        });

        // No exception escapes into the bus loop.
        $execution = AutomationExecution::where('handler', 'test.failing')->first();
        $this->assertNotNull($execution);
        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('Boom.', (string) $execution->error);
    }

    public function test_failed_execution_is_retryable_then_succeeds(): void
    {
        $event = new DomainEvent('test.retryable', []);

        AutomationRunner::run('test.retry', $event, null, null, function (): void {
            throw new \RuntimeException('First attempt fails.');
        });

        $this->assertSame('failed', AutomationExecution::where('handler', 'test.retry')->first()->status);

        // Redelivery of the same event reclaims the failed row and retries.
        $calls = 0;
        AutomationRunner::run('test.retry', $event, null, null, function () use (&$calls): void {
            $calls++;
        });

        $this->assertSame(1, $calls);

        $execution = AutomationExecution::where('handler', 'test.retry')->first();
        $this->assertSame('success', $execution->status);
        $this->assertNull($execution->error);

        // Exactly one ledger row across both evaluations.
        $this->assertSame(1, AutomationExecution::where('handler', 'test.retry')->count());
    }

    public function test_duplicate_evaluation_inside_transaction_completes_without_throwing(): void
    {
        // Regression for the PostgreSQL-abort hazard: contention must be
        // resolved without ever raising a unique violation, so the
        // surrounding transaction stays usable on every engine.
        $event = new DomainEvent('test.txn-collision', []);
        $calls = 0;
        $work = function () use (&$calls): void {
            $calls++;
        };

        DB::transaction(function () use ($event, $work): void {
            AutomationRunner::run('test.txn', $event, null, null, $work);
            AutomationRunner::run('test.txn', $event, null, null, $work);
        });

        $this->assertSame(1, $calls);
        $this->assertSame(1, AutomationExecution::where('handler', 'test.txn')->count());
        $this->assertSame('success', AutomationExecution::where('handler', 'test.txn')->first()->status);
    }

    public function test_successful_execution_skips_duplicates(): void
    {
        $event = new DomainEvent('test.idempotent', []);
        $calls = 0;
        $work = function () use (&$calls): void {
            $calls++;
        };

        AutomationRunner::run('test.once', $event, null, null, $work);
        AutomationRunner::run('test.once', $event, null, null, $work);

        $this->assertSame(1, $calls);
        $this->assertSame(1, AutomationExecution::where('handler', 'test.once')->count());
        $this->assertSame('success', AutomationExecution::where('handler', 'test.once')->first()->status);
    }
}
