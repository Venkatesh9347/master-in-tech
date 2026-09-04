<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\PaymentProviderInterface;
use Illuminate\Support\Str;

/**
 * Deterministic, network-free provider used for local development and tests.
 * It mirrors the Razorpay flow (returns an "order" with ids and a stable
 * status) without contacting any gateway, so the full checkout + idempotency
 * path is exercisable without credentials.
 */
class StubPaymentProvider implements PaymentProviderInterface
{
    public function createOrder(int $amountPaise, array $options = []): PaymentOrder
    {
        $idempotencyKey = (string) ($options['idempotency_key'] ?? 'stub_' . Str::random(24));

        return new PaymentOrder(
            provider: 'stub',
            orderId: 'stub_ord_' . Str::random(16),
            paymentId: 'stub_pay_' . Str::random(16),
            idempotencyKey: $idempotencyKey,
            amountPaise: $amountPaise,
            currency: (string) ($options['currency'] ?? config('payment.currency', 'INR')),
            status: 'stub_created',
            metadata: ['stub' => true],
        );
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        // In stub mode any non-empty signature passes; set PAYMENT_PROVIDER=razorpay
        // with RAZORPAY_WEBHOOK_SECRET for real HMAC verification.
        return $signature !== '';
    }

    public function fetchPayment(string $paymentId): ?array
    {
        return [
            'id' => $paymentId,
            'status' => 'stub',
            'amount' => 0,
            'currency' => config('payment.currency', 'INR'),
        ];
    }
}
