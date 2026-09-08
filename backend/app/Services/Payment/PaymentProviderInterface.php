<?php

namespace App\Services\Payment;

use App\Services\Payment\Data\PaymentOrder;

/**
 * Contract for a real payment gateway provider.
 *
 * Adapters must be thin over the provider SDK; all idempotency and
 * orchestration lives in PaymentService so switching gateways does not change
 * the rest of the application.
 */
interface PaymentProviderInterface
{
    /**
     * Create (or reuse by idempotency) an order/intent for the given amount.
     *
     * @param  int  $amountPaise  Amount in minor units (paise/cents).
     * @param  array{idempotency_key?: string, description?: string, customer?: array, notes?: array}  $options
     */
    public function createOrder(int $amountPaise, array $options = []): PaymentOrder;

    /**
     * Verify a provider webhook signature against the raw request body.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Verify a payment callback signature (order id + payment id) returned to
     * the browser after checkout. The browser alone must never declare success;
     * confirmations carrying a bad signature must be rejected.
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool;

    /**
     * Fetch the authoritative state of a payment by its provider payment id.
     */
    public function fetchPayment(string $paymentId): ?array;
}
