<?php

namespace App\Services\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when an administrator-initiated outbound refund cannot be processed.
 *
 * Carries a machine-readable reason code (safe to expose to the admin client)
 * plus the internal payment linkage (server-side diagnostics only). Messages
 * never include provider credentials, raw gateway payloads, or traces.
 */
class PaymentRefundException extends RuntimeException
{
    private string $reasonCode;

    private string $paymentId;

    public function __construct(string $reasonCode, string $paymentId = '')
    {
        $this->reasonCode = $reasonCode;
        $this->paymentId = $paymentId;

        parent::__construct("Refund failed: {$reasonCode}");
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function paymentId(): string
    {
        return $this->paymentId;
    }
}
