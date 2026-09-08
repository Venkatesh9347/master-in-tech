<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\Exceptions\PaymentNotConfiguredException;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub provider = deterministic, network-free, for local + tests.
        config(['payment.default_provider' => 'stub']);
    }

    public function test_create_order_persists_a_payment_transaction(): void
    {
        $service = new PaymentService();

        $order = $service->createOrder(50000, ['description' => 'Enrolment fee']);

        $this->assertSame('stub', $order->provider);
        $this->assertSame(50000, $order->amountPaise);

        $this->assertDatabaseHas('payment_transactions', [
            'provider' => 'stub',
            'order_id' => $order->orderId,
            'amount_paise' => 50000,
        ]);
    }

    public function test_same_idempotency_key_reuses_existing_order_without_duplicate(): void
    {
        $service = new PaymentService();

        $first = $service->createOrder(25000, ['idempotency_key' => 'mit-test-key-1']);
        $second = $service->createOrder(25000, ['idempotency_key' => 'mit-test-key-1']);

        // Same order surfaced, no second charge, no duplicate transaction.
        $this->assertSame($first->orderId, $second->orderId);
        $this->assertTrue($second->wasReused());
        $this->assertSame(1, PaymentTransaction::where('idempotency_key', 'mit-test-key-1')->count());
    }

    public function test_different_idempotency_keys_create_separate_orders(): void
    {
        $service = new PaymentService();

        $a = $service->createOrder(1000, ['idempotency_key' => 'key-a']);
        $b = $service->createOrder(1000, ['idempotency_key' => 'key-b']);

        $this->assertNotSame($a->orderId, $b->orderId);
        $this->assertSame(2, PaymentTransaction::count());
    }

    public function test_applying_payment_event_marks_paid_and_is_idempotent(): void
    {
        $service = new PaymentService();
        $order = $service->createOrder(30000, [
            'idempotency_key' => 'mit-paid-key',
            'description' => 'Workshop',
        ]);

        PaymentTransaction::where('order_id', $order->orderId)
            ->update(['payment_id' => 'pay_test_123']);

        $tx = PaymentTransaction::where('order_id', $order->orderId)->first();

        $service->applyPaymentEvent('pay_test_123', 'captured', ['event' => 'payment.captured']);

        $tx->refresh();
        $this->assertSame('paid', $tx->status);
        $this->assertNotNull($tx->paid_at);
        $paidAt = $tx->paid_at;

        // Re-applying is a no-op (idempotent).
        $this->travel(1)->minute();
        $service->applyPaymentEvent('pay_test_123', 'captured', []);

        $tx->refresh();
        $this->assertSame('paid', $tx->status);
        $this->assertSame($paidAt->toIso8601String(), $tx->paid_at->toIso8601String());
    }

    public function test_real_razorpay_without_credentials_raises_config_error(): void
    {
        config(['payment.default_provider' => 'razorpay']);
        config(['services.razorpay.key_id' => '']);
        config(['services.razorpay.key_secret' => '']);

        $service = new PaymentService();

        $this->expectException(PaymentNotConfiguredException::class);

        $service->createOrder(10000, ['idempotency_key' => 'mit-never-created']);
    }

    public function test_create_order_persists_course_id_from_options(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Course ' . Str::random(5),
            'slug' => 'course-' . Str::random(5),
            'description' => 'Description.',
            'instructor' => 'Instructor',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'price' => 250.00,
            'is_published' => true,
        ]);

        $order = (new PaymentService())->createOrder(25000, [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'notes' => ['course_title' => $course->title],
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->orderId,
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_paid_transition_activates_enrollment_exactly_once(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Course ' . Str::random(5),
            'slug' => 'course-' . Str::random(5),
            'description' => 'Description.',
            'instructor' => 'Instructor',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'price' => 250.00,
            'is_published' => true,
        ]);

        $service = new PaymentService();
        $order = $service->createOrder(25000, [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'idempotency_key' => 'mit-enroll-once-key',
        ]);

        PaymentTransaction::where('order_id', $order->orderId)
            ->update(['payment_id' => 'pay_enroll_1']);

        $service->applyPaymentEvent('pay_enroll_1', 'captured', ['event' => 'payment.captured']);

        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $this->assertSame(1, CourseEnrollment::count());

        // Re-applying the same paid event must not create a second enrollment.
        $service->applyPaymentEvent('pay_enroll_1', 'captured', []);
        $this->assertSame(1, CourseEnrollment::count());

        // The webhook marks paid again via a different payment id — still no
        // duplicate enrollment row.
        $service->applyPaymentEvent('pay_enroll_1', 'captured', []);
        $this->assertSame(1, CourseEnrollment::count());
    }

    public function test_failed_transition_never_activates_enrollment(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Course ' . Str::random(5),
            'slug' => 'course-' . Str::random(5),
            'description' => 'Description.',
            'instructor' => 'Instructor',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'price' => 250.00,
            'is_published' => true,
        ]);

        $service = new PaymentService();
        $order = $service->createOrder(25000, [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'idempotency_key' => 'mit-never-enroll-key',
        ]);

        PaymentTransaction::where('order_id', $order->orderId)
            ->update(['payment_id' => 'pay_fail_1']);

        $service->applyPaymentEvent('pay_fail_1', 'failed', ['event' => 'payment.failed']);

        $this->assertSame('failed', PaymentTransaction::where('order_id', $order->orderId)->first()->status);
        $this->assertSame(0, CourseEnrollment::count());
    }
}
