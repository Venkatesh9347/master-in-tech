<?php

namespace App\Services\Payment;

use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Data\ProviderRefundResult;

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

    /**
     * Issue an outbound refund against a captured provider payment.
     *
     * NOTE: neither Razorpay's refund API nor this SDK surface enforces
     * provider-side idempotency for this operation (no Idempotency-Key
     * header; `receipt`/`notes` are correlation aids only). Callers MUST NOT
     * assume a retried call is deduplicated by the gateway — reconcile via
     * fetchRefunds() before re-issuing after any ambiguous outcome.
     *
     * @param  string  $paymentId  Provider-side payment id (e.g. pay_...).
     * @param  int|null  $amountPaise  Amount in minor units, or null for a
     *                                full refund of the remaining balance.
     * @param  array{idempotency_key?: string, receipt?: string, notes?: array}  $options
     */
    public function refundPayment(string $paymentId, ?int $amountPaise = null, array $options = []): ProviderRefundResult;

    /**
     * Authoritative provider-side refund listing for a payment.
     *
     * Used to reconcile ambiguous outcomes (gateway accepted the refund but
     * the response was lost): every entry carries the stable merchant
     * identity (`receipt` + `notes.idempotency_key`) so orphaned provider
     * refunds can be re-attached to their logical request instead of being
     * refunded a second time.
     *
     * Returns null when provider state is unreachable — callers must fail
     * closed (no blind re-issue) rather than assume nothing was refunded.
     *
     * @return array<int, array{id: string, amount: int, currency: string, status: string, receipt: ?string, idempotency_key: ?string}>|null
     */
    public function fetchRefunds(string $paymentId): ?array;
}
