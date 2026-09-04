<?php

namespace App\Services\Payment\Data;

/**
 * Normalised representation of a created payment order/intent, independent of
 * the underlying gateway (Razorpay/Stub/etc.).
 */
final class PaymentOrder
{
    public function __construct(
        public readonly string $provider,
        public readonly string $orderId,
        public readonly string $paymentId,
        public readonly string $idempotencyKey,
        public readonly int $amountPaise,
        public readonly string $currency,
        public readonly string $status,
        public readonly array $metadata = []
    ) {
    }

    /**
     * Whether this order was already created on a previous identical call.
     */
    public function wasReused(): bool
    {
        return ($this->metadata['reused'] ?? false) === true;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'order_id' => $this->orderId,
            'payment_id' => $this->paymentId,
            'idempotency_key' => $this->idempotencyKey,
            'amount' => $this->amountPaise,
            'currency' => $this->currency,
            'status' => $this->status,
            'reused' => $this->wasReused(),
        ];
    }
}
