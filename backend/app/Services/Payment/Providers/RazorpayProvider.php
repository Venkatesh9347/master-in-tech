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

        try {
            $order = $this->api()->order->create($attributes);
        } catch (PaymentNotConfiguredException $e) {
            // Missing credentials must surface as a configuration error, not be
            // swallowed into a generic provider error.
            throw $e;
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

        // Razorpay signs webhooks with HMAC-SHA256 over the raw request body.
        // We validate directly (rather than via the SDK's non-static Utility
        // wrapper) using a constant-time comparison.
        $expected = hash_hmac('sha256', $payload, $secret);
        $actual = trim($signature);

        return hash_equals($expected, $actual);
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
