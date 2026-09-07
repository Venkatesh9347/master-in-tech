<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Exceptions\PaymentVerificationException;
use App\Services\Payment\PaymentProviderInterface;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /* ---------------- HTTP: POST /api/payments/confirm ---------------- */

    public function test_confirm_endpoint_marks_order_paid_from_stub_provider(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $orderRes = $this->actingAs($student, 'sanctum')->postJson('/api/payments/order', [
            'amount' => 250.00,
            'idempotency_key' => 'confirm-happy-1',
            'description' => 'Batch fee',
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
            ],
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order['order_id'],
            'status' => 'paid',
        ]);
    }

    public function test_confirm_endpoint_rejects_order_owned_by_another_user(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);

        $orderRes = $this->actingAs($owner, 'sanctum')->postJson('/api/payments/order', [
            'amount' => 100.00,
            'idempotency_key' => 'confirm-owner-1',
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

        $orderRes = $this->actingAs($student, 'sanctum')->postJson('/api/payments/order', [
            'amount' => 100.00,
            'idempotency_key' => 'confirm-mismatch-1',
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

    public function __construct(private array $paymentEntity)
    {
    }

    public function createOrder(int $amountPaise, array $options = []): PaymentOrder
    {
        return new PaymentOrder(
            provider: 'stub',
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

    public function fetchPayment(string $paymentId): ?array
    {
        $this->fetchCalls++;

        return $this->paymentEntity;
    }
}