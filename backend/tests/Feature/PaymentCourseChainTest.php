<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end payment chain with the stub provider (no network, no real money):
 *   course -> checkout (server-side price) -> order -> webhook -> enrollment
 */
class PaymentCourseChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payment.default_provider' => 'stub']);
    }

    private function makeStudent(): User
    {
        return User::factory()->create(['role' => 'student', 'status' => 'active']);
    }

    private function makeCourse(array $overrides = []): Course
    {
        return Course::create(array_merge([
            'title' => 'Paid Masterclass',
            'slug' => 'paid-masterclass-' . uniqid(),
            'description' => 'Payable fixture course.',
            'category' => 'Full Stack',
            'instructor' => 'Fixture Instructor',
            'duration' => '4 Weeks',
            'difficulty' => 'Beginner',
            'price' => 19999,
            'is_published' => true,
        ], $overrides));
    }

    private function studentToken(User $student): string
    {
        return $student->startNewActiveSession('test_token')->plainTextToken;
    }

    public function test_order_derives_amount_server_side_and_ignores_client_amount(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(['price' => 19999]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->studentToken($student))
            ->postJson('/api/payments/order', [
                'course_id' => $course->id,
                // A tampered client amount must have zero effect.
                'amount' => 1,
            ]);

        $res->assertStatus(201);
        $this->assertSame(1999900, $res->json('order.amount'));

        $this->assertDatabaseHas('payment_transactions', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => 1999900,
        ]);

        // Stub mode exposes no gateway key: the client must not render payment UI.
        $this->assertNull($res->json('key_id'));
    }

    public function test_order_response_exposes_gateway_key_when_configured(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_test_key_123',
        ]);

        // Fake gateway labelled as razorpay: no network, but the controller
        // takes the razorpay branch and exposes the public key.
        $fake = new class implements \App\Services\Payment\PaymentProviderInterface {
            public function createOrder(int $amountPaise, array $options = []): \App\Services\Payment\Data\PaymentOrder
            {
                return new \App\Services\Payment\Data\PaymentOrder(
                    provider: 'razorpay',
                    orderId: 'order_test_keyed',
                    paymentId: 'pay_test_keyed',
                    idempotencyKey: (string) ($options['idempotency_key'] ?? 'keyed-1'),
                    amountPaise: $amountPaise,
                    currency: 'INR',
                    status: 'created',
                );
            }

            public function verifyWebhookSignature(string $payload, string $signature): bool
            {
                return true;
            }

            public function fetchPayment(string $paymentId): ?array
            {
                return null;
            }
        };
        $this->app->bind(\App\Services\Payment\PaymentService::class,
            fn () => new \App\Services\Payment\PaymentService($fake));

        $student = $this->makeStudent();
        $course = $this->makeCourse(['slug' => 'keyed-' . uniqid()]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->studentToken($student))
            ->postJson('/api/payments/order', ['course_id' => $course->id])
            ->assertStatus(201);

        $this->assertSame('rzp_test_key_123', $res->json('key_id'));
    }

    public function test_order_reuses_open_order_for_same_course(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();

        $headers = ['Authorization' => 'Bearer ' . $this->studentToken($student)];
        $first = $this->withHeaders($headers)->postJson('/api/payments/order', [
            'course_id' => $course->id,
        ])->assertStatus(201);
        $second = $this->withHeaders($headers)->postJson('/api/payments/order', [
            'course_id' => $course->id,
        ])->assertStatus(201);

        $this->assertSame($first->json('order.order_id'), $second->json('order.order_id'));
        $this->assertTrue((bool) $second->json('order.reused'));
        $this->assertSame(1, PaymentTransaction::where('user_id', $student->id)
            ->where('course_id', $course->id)->count());
    }

    public function test_order_rejects_unknown_draft_free_and_enrolled(): void
    {
        $student = $this->makeStudent();
        $headers = ['Authorization' => 'Bearer ' . $this->studentToken($student)];

        // Unknown course.
        $this->withHeaders($headers)->postJson('/api/payments/order', [
            'course_id' => 999999,
        ])->assertStatus(422);

        // Draft course is not purchasable.
        $draft = $this->makeCourse(['is_published' => false, 'slug' => 'draft-' . uniqid()]);
        $this->withHeaders($headers)->postJson('/api/payments/order', [
            'course_id' => $draft->id,
        ])->assertStatus(422);

        // Free course needs no payment.
        $free = $this->makeCourse(['price' => 0, 'slug' => 'free-' . uniqid()]);
        $this->withHeaders($headers)->postJson('/api/payments/order', [
            'course_id' => $free->id,
        ])->assertStatus(422);

        // Already enrolled.
        $course = $this->makeCourse(['slug' => 'owned-' . uniqid()]);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        $this->withHeaders($headers)->postJson('/api/payments/order', [
            'course_id' => $course->id,
        ])->assertStatus(409);
    }

    public function test_paid_webhook_grants_enrollment_idempotently(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(['slug' => 'webhook-' . uniqid()]);

        $orderRes = $this->withHeader('Authorization', 'Bearer ' . $this->studentToken($student))
            ->postJson('/api/payments/order', ['course_id' => $course->id])
            ->assertStatus(201);

        $tx = PaymentTransaction::where('user_id', $student->id)
            ->where('course_id', $course->id)->firstOrFail();
        $this->assertNull($tx->enrollment_id);

        $payload = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => $tx->payment_id]]],
        ]);

        $webhook = fn () => $this->call('POST', '/api/payments/razorpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => 'stub-test-signature',
        ], $payload);

        $webhook()->assertOk()->assertJson(['status' => 'paid']);
        // Duplicate delivery must be idempotent.
        $webhook()->assertOk()->assertJson(['status' => 'paid']);

        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $this->assertSame(1, CourseEnrollment::where('user_id', $student->id)
            ->where('course_id', $course->id)->count());

        $tx->refresh();
        $this->assertNotNull($tx->enrollment_id);
        $this->assertSame('paid', $tx->status);
    }

    public function test_forged_webhook_is_rejected(): void
    {
        $payload = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_forged_1']]],
        ]);

        $this->call('POST', '/api/payments/razorpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(400);
    }

    public function test_missing_credentials_yield_503_not_500(): void
    {
        config(['payment.default_provider' => 'razorpay']);

        $student = $this->makeStudent();
        $course = $this->makeCourse(['slug' => 'creds-' . uniqid()]);

        $this->withHeader('Authorization', 'Bearer ' . $this->studentToken($student))
            ->postJson('/api/payments/order', ['course_id' => $course->id])
            ->assertStatus(503);
    }
}
