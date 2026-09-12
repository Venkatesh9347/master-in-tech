<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Webhook event ledger, refund state, atomic paid transition, and
 * mass-assignment hardening for payment transactions.
 */
class PaymentLedgerRefundTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_secret_123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.default_provider' => 'razorpay',
            'services.razorpay.webhook_secret' => $this->secret,
        ]);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    private function postRaw(string $url, string $rawBody, ?string $signature = null)
    {
        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? $this->sign($rawBody),
        ], $rawBody);
    }

    private function makeCourse(float $price = 250.00): Course
    {
        $title = 'Course ' . Str::random(6);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'Ledger fixture course.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => $price,
        ]);
    }

    private function makePaidSetup(): array
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse();
        $tx = PaymentTransaction::create([
            'provider' => 'razorpay',
            'order_id' => 'order_' . Str::random(8),
            'payment_id' => 'pay_' . Str::random(8),
            'idempotency_key' => 'ledger-' . Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => 25000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        return [$student, $course, $tx];
    }

    private function capturedPayload(string $eventId, string $paymentId, string $orderId): string
    {
        return json_encode([
            'id' => $eventId,
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => $paymentId,
                'order_id' => $orderId,
                'amount' => 25000,
                'currency' => 'INR',
                'status' => 'captured',
            ]]],
        ]);
    }

    public function test_duplicate_delivery_acknowledged_once_via_ledger(): void
    {
        [$student, $course, $tx] = $this->makePaidSetup();
        $body = $this->capturedPayload('evt_dup_1', $tx->payment_id, $tx->order_id);

        $this->postRaw('/api/payments/razorpay/webhook', $body)->assertOk()->assertJson(['status' => 'paid']);
        $this->postRaw('/api/payments/razorpay/webhook', $body)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, PaymentWebhookEvent::where('provider', 'razorpay')
            ->where('provider_event_id', 'evt_dup_1')->count());
        $this->assertSame(1, CourseEnrollment::where('user_id', $student->id)
            ->where('course_id', $course->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_paid']);
    }

    public function test_refund_marks_refunded_and_keeps_enrollment(): void
    {
        [$student, $course, $tx] = $this->makePaidSetup();
        $this->postRaw('/api/payments/razorpay/webhook', $this->capturedPayload('evt_pay_1', $tx->payment_id, $tx->order_id))
            ->assertOk();
        $this->assertSame('paid', $tx->fresh()->status);

        $refundBody = json_encode([
            'id' => 'evt_ref_1',
            'event' => 'refund.processed',
            'payload' => ['refund' => ['entity' => [
                'id' => 'rfnd_1',
                'payment_id' => $tx->payment_id,
                'amount' => 25000,
            ]]],
        ]);
        $this->postRaw('/api/payments/razorpay/webhook', $refundBody)
            ->assertOk()->assertJson(['status' => 'refunded']);

        $this->assertSame('refunded', $tx->fresh()->status);
        // Access decisions stay with admins: enrollment retained.
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_refunded']);
    }

    public function test_refunded_never_downgrades_or_resurrects(): void
    {
        [$student, $course, $tx] = $this->makePaidSetup();
        $service = app(\App\Services\Payment\PaymentService::class);

        $service->applyPaymentEvent($tx->payment_id, 'captured', []);
        $this->assertSame('paid', $tx->fresh()->status);

        $service->applyPaymentEvent($tx->payment_id, 'refunded', []);
        $this->assertSame('refunded', $tx->fresh()->status);

        // A replayed capture cannot resurrect a refunded row.
        $service->applyPaymentEvent($tx->payment_id, 'captured', []);
        $this->assertSame('refunded', $tx->fresh()->status);

        // A refund cannot downgrade a paid row via failed either.
        $tx2 = PaymentTransaction::create([
            'provider' => 'razorpay', 'order_id' => 'order_x1', 'payment_id' => 'pay_x1',
            'idempotency_key' => 'ledger-x1', 'amount_paise' => 100, 'currency' => 'INR', 'status' => 'paid',
        ]);
        $service->applyPaymentEvent('pay_x1', 'failed', []);
        $this->assertSame('paid', $tx2->fresh()->status);
    }

    public function test_transaction_mass_assignment_locked_down(): void
    {
        $tx = new PaymentTransaction([
            'id' => 99999,
            'provider' => 'razorpay',
            'order_id' => 'order_ma',
            'payment_id' => 'pay_ma',
            'idempotency_key' => 'ma-1',
            'amount_paise' => 100,
            'status' => 'paid',
            'created_at' => now()->subYear(),
        ]);

        $this->assertNull($tx->id);
        $this->assertNull($tx->created_at);
        $this->assertContains('amount_paise', $tx->getFillable());
        $this->assertNotContains('id', $tx->getFillable());
    }
}
