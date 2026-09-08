<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Exceptions\PaymentNotConfiguredException;
use App\Services\Payment\Exceptions\PaymentProviderException;
use App\Services\Payment\PaymentProviderInterface;
use Razorpay\Api\Api;

/**
 * Razorpay gateway adapter (real SDK).
 *
 * Credentials are read from config (env). Creating an order with missing keys
 * raises PaymentNotConfiguredException rather than silently proceeding.
 */
class RazorpayProvider implements PaymentProviderInterface
{
    private ?Api $api;

    private function api(): Api
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $keyId = (string) config('services.razorpay.key_id');
        $keySecret = (string) config('services.razorpay.key_secret');

        if ($keyId === '' || $keySecret === '') {
            throw new PaymentNotConfiguredException(
                'Razorpay is not configured. Set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET before taking payments.'
            );
        }

        return $this->api = new Api($keyId, $keySecret);
    }

    public function createOrder(int $amountPaise, array $options = []): PaymentOrder
    {
        $attributes = [
            'amount' => $amountPaise,
            'currency' => (string) ($options['currency'] ?? config('payment.currency', 'INR')),
            'receipt' => (string) ($options['idempotency_key'] ?? 'ord_' . now()->timestamp),
            'notes' => $options['notes'] ?? [],
        ];

        if (($options['description'] ?? '') !== '') {
            $attributes['notes']['description'] = $options['description'];
        }

        // Resolve the SDK client first so a missing-credentials configuration
        // error surfaces as PaymentNotConfiguredException, not as a wrapped
        // gateway failure. Only genuine SDK/network failures below are wrapped.
        $api = $this->api();

        try {
            $order = $api->order->create($attributes);
        } catch (\Throwable $e) {
            throw new PaymentProviderException('Razorpay order creation failed: ' . $e->getMessage(), 0, $e);
        }

        return new PaymentOrder(
            provider: 'razorpay',
            orderId: (string) ($order['id'] ?? ''),
            paymentId: (string) ($order['payment_id'] ?? ''),
            idempotencyKey: (string) ($options['idempotency_key'] ?? ''),
            amountPaise: (int) ($order['amount'] ?? $amountPaise),
            currency: (string) ($order['currency'] ?? $attributes['currency']),
            status: (string) ($order['status'] ?? 'created'),
            metadata: $order->toArray(),
        );
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');

        if ($secret === '') {
            return false;
        }

        try {
            // The SDK's signature verifier is an instance method; calling it
            // statically throws on PHP 8 (fatal error caught below), which
            // would reject every valid webhook. Invoke it through an instance.
            $verifier = new \Razorpay\Api\Utility();
            $verifier->verifyWebhookSignature($payload, trim($signature), $secret);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function fetchPayment(string $paymentId): ?array
    {
        try {
            return $this->api()->payment->fetch($paymentId)->toArray();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
