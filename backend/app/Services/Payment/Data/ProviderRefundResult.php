<?php

namespace App\Services\Payment\Data;

/**
 * Normalised representation of a provider-side refund, independent of the
 * underlying gateway (Razorpay/Stub/etc.).
 *
 * Only the minimum safe fields are carried: never provider credentials, raw
 * authorization material, or full gateway payloads.
 */
final class ProviderRefundResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $refundId,
        public readonly string $paymentId,
        public readonly int $amountPaise,
        public readonly string $currency,
        public readonly string $status,
        public readonly array $metadata = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'refund_id' => $this->refundId,
            'payment_id' => $this->paymentId,
            'amount' => $this->amountPaise,
            'currency' => $this->currency,
            'status' => $this->status,
        ];
    }
}
