<?php

namespace App\Services\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when a payment verification check fails during confirm or webhook
 * processing — e.g. order not found, amount/order/currency mismatch, or
 * payment not in a confirmable state.
 */
class PaymentVerificationException extends RuntimeException
{
    private string $reasonCode;
    private string $orderId;
    private string $paymentId;

    public function __construct(string $reasonCode, string $orderId = '', string $paymentId = '')
    {
        $this->reasonCode = $reasonCode;
        $this->orderId = $orderId;
        $this->paymentId = $paymentId;

        parent::__construct("Payment verification failed: {$reasonCode}");
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function paymentId(): string
    {
        return $this->paymentId;
    }
}
