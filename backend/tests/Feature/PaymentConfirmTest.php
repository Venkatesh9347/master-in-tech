<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Exceptions\PaymentVerificationException;
use App\Services\Payment\PaymentProviderInterface;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentConfirmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub provider = deterministic, network-free, for local + tests.
        config(['payment.default_provider' => 'stub']);
    }

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);

        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => $attributes['code'] ?? 'FSD',
            'description' => 'Comprehensive technical training course description.',
            'category' => 'Engineering',
            'instructor' => 'Senior Specialist',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
            'price' => 250.00,
        ], $attributes));
    }

    /* ---------------- HTTP: POST /api/payments/order ---------------- */

    public function test_create_order_requires_a_course_id(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/order', ['amount' => 250.00])
            ->assertStatus(422);
    }

    public function test_create_order_rejects_unknown_or_unpublished_course(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $unpublished = $this->createCourse(['is_published' => false, 'price' => 250.00]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/order', ['course_id' => 999999])
            ->assertStatus(422);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/order', ['course_id' => $unpublished->id])
            ->assertStatus(404);
    }

    public function test_create_order_derives_amount_from_course_price_only(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 2499.99]);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/order', ['course_id' => $course->id]);

        $response->assertStatus(201);
        $response->assertJson([
            'provider' => 'stub',
            'course' => ['id' => $course->id, 'title' => $course->title],
        ]);

        $order = $response->json('order');
        $this->assertSame(249999, $order['amount']);
        $this->assertSame('INR', $order['currency']);

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order['order_id'],
            'course_id' => $course->id,
            'user_id' => $student->id,
            'amount_paise' => 249999,
            'description' => 'Enrolment fee for ' . $course->title,
        ]);
    }

    public function test_same_course_reuses_the_same_order_for_the_same_user(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 500.00]);

        $first = $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/order', ['course_id' => $course->id])
            ->assertStatus(201)
            ->json('order');

        $second = $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/order', ['course_id' => $course->id])
            ->assertStatus(201)
            ->json('order');

        $this->assertSame($first['order_id'], $second['order_id']);
        $this->assertTrue($second['reused']);
        $this->assertSame(1, PaymentTransaction::where('course_id', $course->id)->count());
    }

    /* ---------------- HTTP: POST /api/payments/confirm ---------------- */

    public function test_confirm_endpoint_marks_order_paid_from_stub_provider(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 250.00]);

        $orderRes = $this->actingAs($student, 'sanctum')->postJson('/api/payments/order', [
            'course_id' => $course->id,
        ]);

        $orderRes->assertStatus(201);
        $order = $orderRes->json('order');

        $response = $this->actingAs($student, 'sanctum')->postJson('/api/payments/confirm', [
            'order_id' => $order['order_id'],
            'payment_id' => $order['payment_id'],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'order' => [
                'order_id' => $order['order_id'],
                'status' => 'paid',
                'course_id' => $course->id,
            ],
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order['order_id'],
            'status' => 'paid',
        ]);

        // Verified payment activates the student's enrollment exactly once.
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $this->assertSame(1, CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    public function test_confirm_endpoint_rejects_order_owned_by_another_user(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 100.00]);

        $orderRes = $this->actingAs($owner, 'sanctum')->postJson('/api/payments/order', [
            'course_id' => $course->id,
        ]);

        $order = $orderRes->json('order');

        $response = $this->actingAs($other, 'sanctum')->postJson('/api/payments/confirm', [
            'order_id' => $order['order_id'],
            'payment_id' => $order['payment_id'],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order['order_id'],
            'status' => 'stub_created',
        ]);
        $this->assertDatabaseMissing('course_enrollments', ['course_id' => $course->id]);
    }

    public function test_confirm_endpoint_returns_404_for_unknown_order(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student, 'sanctum')->postJson('/api/payments/confirm', [
            'order_id' => 'order_does_not_exist_1',
            'payment_id' => 'pay_does_not_exist_1',
        ]);

        $response->assertStatus(404);
    }

    public function test_confirm_endpoint_rejects_when_provider_entity_does_not_match(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 100.00]);

        $orderRes = $this->actingAs($student, 'sanctum')->postJson('/api/payments/order', [
            'course_id' => $course->id,
        ]);

        $order = $orderRes->json('order');

        // Tamper with the payment id so the stub token no longer matches the
        // recorded order/amount — verification must fail server-side.
        $response = $this->actingAs($student, 'sanctum')->postJson('/api/payments/confirm', [
            'order_id' => $order['order_id'],
            'payment_id' => 'stub_pay_'.base64_encode('garbage-foreign-token'),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order['order_id'],
            'status' => 'stub_created',
        ]);
        $this->assertDatabaseMissing('course_enrollments', ['course_id' => $course->id]);
    }

    public function test_razorpay_confirm_requires_a_valid_payment_signature(): void
    {
        config(['payment.default_provider' => 'razorpay']);
        config(['services.razorpay.key_secret' => 'sk_test_secret_123']);

        $fake = new FakePaymentProvider([
            'id' => 'pay_rp_happy',
            'status' => 'captured',
            'order_id' => '', // filled from the real created order below
            'amount' => 10000,
            'currency' => 'INR',
        ], 'sk_test_secret_123', 'razorpay');

        $this->app->instance(PaymentService::class, new PaymentService($fake));

        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 100.00]);

        $orderRes = $this->actingAs($student, 'sanctum')->postJson('/api/payments/order', [
            'course_id' => $course->id,
        ]);
        $orderRes->assertStatus(201);
        $order = $orderRes->json('order');

        $fake->paymentEntity['order_id'] = $order['order_id'];

        $confirmPayload = ['order_id' => $order['order_id'], 'payment_id' => 'pay_rp_happy'];

        // Missing signature -> rejected before any provider fetch.
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/confirm', $confirmPayload)
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_signature']);

        // Forged signature -> rejected.
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/confirm', $confirmPayload + ['signature' => 'forged-signature'])
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_signature']);

        // Valid HMAC(order_id|payment_id, key_secret) -> paid + enrollment.
        $signature = hash_hmac('sha256', $order['order_id'] . '|pay_rp_happy', 'sk_test_secret_123');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/confirm', $confirmPayload + ['signature' => $signature])
            ->assertStatus(200)
            ->assertJson(['order' => ['status' => 'paid']]);

        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    /* ---------------- Service-level verification paths ---------------- */

    public function test_authoritative_confirm_verifies_amount_and_currency(): void
    {
        $service = new PaymentService(new FakePaymentProvider([
            'id' => 'pay_fake_a',
            'status' => 'captured',
            'order_id' => 'order_fake_a',
            'amount' => 10000,
            'currency' => 'INR',
        ]));

        $user = User::factory()->create(['role' => 'student']);

        $this->createTransaction('stub', 'order_fake_a', $user->id, 10000, 'INR', paymentId: 'pay_fake_a');

        $tx = $service->authoritativeConfirm('order_fake_a', 'pay_fake_a', (int) $user->id);

        $this->assertSame('paid', $tx->status);
        $this->assertSame('pay_fake_a', (string) $tx->payment_id);
    }

    public function test_authoritative_confirm_rejects_amount_mismatch(): void
    {
        $service = new PaymentService(new FakePaymentProvider([
            'id' => 'pay_fake_b',
            'status' => 'captured',
            'order_id' => 'order_fake_b',
            'amount' => 9000,
            'currency' => 'INR',
        ]));

        $user = User::factory()->create(['role' => 'student']);

        $this->createTransaction('stub', 'order_fake_b', $user->id, 10000, 'INR');

        $this->expectException(PaymentVerificationException::class);
        $this->expectExceptionMessage('amount_mismatch');

        $service->authoritativeConfirm('order_fake_b', 'pay_fake_b', (int) $user->id);
    }

    public function test_authoritative_confirm_rejects_order_mismatch(): void
    {
        $service = new PaymentService(new FakePaymentProvider([
            'id' => 'pay_fake_c',
            'status' => 'captured',
            'order_id' => 'order_FOREIGN',
            'amount' => 10000,
            'currency' => 'INR',
        ]));

        $user = User::factory()->create(['role' => 'student']);

        $this->createTransaction('stub', 'order_fake_c', $user->id, 10000, 'INR');

        $this->expectException(PaymentVerificationException::class);
        $this->expectExceptionMessage('order_mismatch');

        $service->authoritativeConfirm('order_fake_c', 'pay_fake_c', (int) $user->id);
    }

    public function test_authoritative_confirm_rejects_currency_mismatch(): void
    {
        $service = new PaymentService(new FakePaymentProvider([
            'id' => 'pay_fake_d',
            'status' => 'captured',
            'order_id' => 'order_fake_d',
            'amount' => 10000,
            'currency' => 'USD',
        ]));

        $user = User::factory()->create(['role' => 'student']);

        $this->createTransaction('stub', 'order_fake_d', $user->id, 10000, 'INR');

        $this->expectException(PaymentVerificationException::class);
        $this->expectExceptionMessage('currency_mismatch');

        $service->authoritativeConfirm('order_fake_d', 'pay_fake_d', (int) $user->id);
    }

    public function test_authoritative_confirm_rejects_unconfirmed_payment_status(): void
    {
        $service = new PaymentService(new FakePaymentProvider([
            'id' => 'pay_fake_e',
            'status' => 'authorized',
            'order_id' => 'order_fake_e',
            'amount' => 10000,
            'currency' => 'INR',
        ]));

        $user = User::factory()->create(['role' => 'student']);

        $this->createTransaction('stub', 'order_fake_e', $user->id, 10000, 'INR');

        $this->expectException(PaymentVerificationException::class);
        $this->expectExceptionMessage('payment_not_captured');

        $service->authoritativeConfirm('order_fake_e', 'pay_fake_e', (int) $user->id);
    }

    public function test_authoritative_confirm_associates_payment_id_when_order_was_created_without_one(): void
    {
        // Mirrors real Razorpay: createOrder returns an empty payment id and
        // the payment id is only known later. Confirm must associate + mark paid.
        $service = new PaymentService(new FakePaymentProvider([
            'id' => 'pay_fake_f',
            'status' => 'captured',
            'order_id' => 'order_fake_f',
            'amount' => 10000,
            'currency' => 'INR',
        ]));

        $user = User::factory()->create(['role' => 'student']);

        $this->createTransaction('stub', 'order_fake_f', $user->id, 10000, 'INR', paymentId: '');

        $tx = $service->authoritativeConfirm('order_fake_f', 'pay_fake_f', (int) $user->id);

        $this->assertSame('paid', $tx->status);
        $this->assertSame('pay_fake_f', (string) $tx->payment_id);
    }

    /* ---------------- helpers ---------------- */

    private function createTransaction(
        string $provider,
        string $orderId,
        int $userId,
        int $amountPaise,
        string $currency,
        string $paymentId = 'pay_fake_x'
    ): PaymentTransaction {
        return PaymentTransaction::create([
            'provider' => $provider,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'idempotency_key' => 'key_' . $orderId,
            'user_id' => $userId,
            'amount_paise' => $amountPaise,
            'currency' => $currency,
            'status' => 'created',
        ]);
    }
}

class FakePaymentProvider implements PaymentProviderInterface
{
    public int $fetchCalls = 0;

    public array $paymentEntity;

    public function __construct(
        array $paymentEntity,
        private string $secret = '',
        private string $orderProvider = 'stub'
    ) {
        $this->paymentEntity = $paymentEntity;
    }

    public function createOrder(int $amountPaise, array $options = []): PaymentOrder
    {
        return new PaymentOrder(
            provider: $this->orderProvider,
            orderId: 'fake_ord_' . substr((string) ($options['idempotency_key'] ?? ''), 0, 12),
            paymentId: '',
            idempotencyKey: (string) ($options['idempotency_key'] ?? 'fake_key'),
            amountPaise: $amountPaise,
            currency: (string) ($options['currency'] ?? 'INR'),
            status: 'created',
        );
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $signature !== '';
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        if ($this->secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $this->secret), $signature);
    }

    public function fetchPayment(string $paymentId): ?array
    {
        $this->fetchCalls++;

        return $this->paymentEntity;
    }

    public function refundPayment(string $paymentId, ?int $amountPaise = null, array $options = []): \App\Services\Payment\Data\ProviderRefundResult
    {
        return new \App\Services\Payment\Data\ProviderRefundResult(
            provider: $this->orderProvider,
            refundId: 'fake_rfnd_test',
            paymentId: $paymentId,
            amountPaise: (int) ($amountPaise ?? 0),
            currency: 'INR',
            status: 'processed',
        );
    }

    public function fetchRefunds(string $paymentId): ?array
    {
        return [];
    }
}