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
        return $this->withHeaders(['X-Razorpay-Signature' => $signature])
            ->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $rawBody);
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
                'payment' => ['entity' => ['id' => 'pay_test_1']],
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
}
