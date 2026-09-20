<?php

namespace Tests\Feature;

use App\DomainEvents\DomainEvent;
use App\DomainEvents\DomainEventBus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\WebhookDispatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9-B: in-process domain-event bus between business seams and the
 * outbound webhook consumer.
 *
 * Portable across SQLite/MySQL/PostgreSQL: no PRAGMA, no engine-specific
 * error text, no hardcoded driver names.
 */
class DomainEventBusTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_event_preserves_name_and_payload(): void
    {
        $event = new DomainEvent('payment.paid', ['payment_id' => 'pay_1']);

        $this->assertSame('payment.paid', $event->name);
        $this->assertSame(['payment_id' => 'pay_1'], $event->payload);
        $this->assertNotEmpty($event->id);
        $this->assertNotEmpty($event->occurredAt);
    }

    public function test_bus_invokes_registered_consumers(): void
    {
        $seen = [];

        app(DomainEventBus::class)->listen('test.probe', function (DomainEvent $event) use (&$seen): void {
            $seen[] = $event;
        });

        DomainEventBus::record('test.probe', ['n' => 1]);

        $this->assertCount(1, $seen);
        $this->assertSame('test.probe', $seen[0]->name);
        $this->assertSame(['n' => 1], $seen[0]->payload);

        // Unknown events have no consumers and are ignored without throwing.
        DomainEventBus::record('test.unregistered', []);
        $this->assertCount(1, $seen);
    }

    public function test_webhook_consumer_is_registered_for_all_supported_events(): void
    {
        $registered = app(DomainEventBus::class)->registeredEvents();

        foreach (WebhookDispatcherService::SUPPORTED_EVENTS as $event) {
            $this->assertContains($event, $registered);
        }
    }

    public function test_paid_transition_emits_exactly_one_bus_event_and_delivery(): void
    {
        WebhookSubscription::create([
            'target_url' => 'https://hooks.example.com/deliveries',
            'secret' => str_repeat('s', 32),
            'events' => [WebhookDispatcherService::EVENT_PAYMENT_PAID],
            'is_active' => true,
        ]);

        $counted = 0;
        app(DomainEventBus::class)->listen(
            WebhookDispatcherService::EVENT_PAYMENT_PAID,
            function () use (&$counted): void {
                $counted++;
            }
        );

        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse();

        $tx = \App\Models\PaymentTransaction::create([
            'provider' => 'stub',
            'order_id' => 'order_bus_' . Str::random(8),
            'payment_id' => 'pay_bus_' . Str::random(8),
            'idempotency_key' => 'bus-key-' . Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        app(\App\Services\Payment\PaymentService::class)
            ->applyPaymentEvent($tx->payment_id, 'captured', []);

        // Exactly one bus emission, exactly one webhook delivery: the old
        // direct dispatch path must not still be active alongside the bus.
        $this->assertSame(1, $counted);
        $this->assertSame(1, WebhookDelivery::where('event', 'payment.paid')->count());
    }

    public function test_rolled_back_enrollment_emits_no_committed_delivery(): void
    {
        WebhookSubscription::create([
            'target_url' => 'https://hooks.example.com/deliveries',
            'secret' => str_repeat('s', 32),
            'events' => [WebhookDispatcherService::EVENT_ENROLLMENT_CREATED],
            'is_active' => true,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('RollbackBus');

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

        $this->assertSame(0, CourseEnrollment::count());
        $this->assertSame(0, WebhookDelivery::where('event', 'enrollment.created')->count());
    }

    private function makeCourse(string $prefix = 'Course'): Course
    {
        $title = $prefix . ' ' . Str::random(6);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Domain bus fixture course.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 1000.00,
        ]);
    }
}
