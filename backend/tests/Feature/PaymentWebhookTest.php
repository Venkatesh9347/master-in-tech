<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
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

    protected function postRaw(string $url, string $rawBody, string $signature)
    {
        // Note: raw call() only uses the explicit $server array (defaultHeaders
        // are not transformed for raw call()), so pass the signature header as a
        // server variable.
        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $rawBody);
    }

    public function test_valid_webhook_signature_marks_payment_paid(): void
    {
        $tx = PaymentTransaction::create([
            'provider' => 'razorpay',
            'order_id' => 'order_test_1',
            'payment_id' => 'pay_test_1',
            'idempotency_key' => 'webhook-key-1',
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        $rawPayload = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => ['entity' => [
                    'id' => 'pay_test_1',
                    'order_id' => 'order_test_1',
                    'amount' => 10000,
                    'currency' => 'INR',
                    'status' => 'captured',
                ]],
            ],
        ]);

        $response = $this->postRaw('/api/payments/razorpay/webhook', $rawPayload, $this->sign($rawPayload));

        $response->assertStatus(200);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $tx->id,
            'status' => 'paid',
        ]);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        PaymentTransaction::create([
            'provider' => 'razorpay',
            'order_id' => 'order_test_2',
            'payment_id' => 'pay_test_2',
            'idempotency_key' => 'webhook-key-2',
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        $rawPayload = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_test_2']]],
        ]);

        $response = $this->postRaw('/api/payments/razorpay/webhook', $rawPayload, 'forged-signature');

        $response->assertStatus(400);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => 'pay_test_2',
            'status' => 'created',
        ]);
    }

    public function test_webhook_rejects_unknown_payment_without_error(): void
    {
        $rawPayload = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_unknown_1']]],
        ]);

        $response = $this->postRaw('/api/payments/razorpay/webhook', $rawPayload, $this->sign($rawPayload));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);
    }

    public function test_webhook_does_not_mark_paid_on_payment_id_alone_without_verification(): void
    {
        // A captured event that references a known payment id but NO order_id
        // must NOT mark the transaction paid (order/amount/currency verification
        // is mandatory for the paid transition).
        PaymentTransaction::create([
            'provider' => 'razorpay',
            'order_id' => 'order_test_3',
            'payment_id' => 'pay_test_3',
            'idempotency_key' => 'webhook-key-3',
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        $rawPayload = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => ['entity' => ['id' => 'pay_test_3']],
            ],
        ]);

        $response = $this->postRaw('/api/payments/razorpay/webhook', $rawPayload, $this->sign($rawPayload));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => 'pay_test_3',
            'status' => 'created',
        ]);
    }

    public function test_webhook_ignores_captured_event_with_mismatched_order(): void
    {
        PaymentTransaction::create([
            'provider' => 'razorpay',
            'order_id' => 'order_test_4',
            'payment_id' => 'pay_test_4',
            'idempotency_key' => 'webhook-key-4',
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        $rawPayload = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => ['entity' => [
                    'id' => 'pay_test_4',
                    'order_id' => 'order_SOMEONE_ELSE',
                    'amount' => 10000,
                    'currency' => 'INR',
                ]],
            ],
        ]);

        $response = $this->postRaw('/api/payments/razorpay/webhook', $rawPayload, $this->sign($rawPayload));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => 'pay_test_4',
            'status' => 'created',
        ]);
    }

    public function test_webhook_ignores_captured_event_with_amount_mismatch(): void
    {
        PaymentTransaction::create([
            'provider' => 'razorpay',
            'order_id' => 'order_test_5',
            'payment_id' => 'pay_test_5',
            'idempotency_key' => 'webhook-key-5',
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        $rawPayload = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => ['entity' => [
                    'id' => 'pay_test_5',
                    'order_id' => 'order_test_5',
                    'amount' => 9999,
                    'currency' => 'INR',
                ]],
            ],
        ]);

        $response = $this->postRaw('/api/payments/razorpay/webhook', $rawPayload, $this->sign($rawPayload));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => 'pay_test_5',
            'status' => 'created',
        ]);
    }

    public function test_webhook_failed_event_without_order_info_still_applies_failure(): void
    {
        // Failure events are best-effort and never grant access, so they may be
        // applied even when the entity lacks full order/amount context.
        PaymentTransaction::create([
            'provider' => 'razorpay',
            'order_id' => 'order_test_6',
            'payment_id' => 'pay_test_6',
            'idempotency_key' => 'webhook-key-6',
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        $rawPayload = json_encode([
            'event' => 'payment.failed',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_test_6']]],
        ]);

        $response = $this->postRaw('/api/payments/razorpay/webhook', $rawPayload, $this->sign($rawPayload));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'failed']);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => 'pay_test_6',
            'status' => 'failed',
        ]);
    }
}
