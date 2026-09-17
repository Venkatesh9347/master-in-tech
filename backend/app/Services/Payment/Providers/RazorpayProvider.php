<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Data\ProviderRefundResult;
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

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('services.razorpay.key_secret');

        if ($secret === '' || $signature === '' || $orderId === '' || $paymentId === '') {
            return false;
        }

        // Razorpay signs browser-side payment confirmations with HMAC-SHA256 over
        // "{order_id}|{payment_id}" using the API key secret. Verified directly
        // with a constant-time comparison (same approach as webhooks).
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);

        return hash_equals($expected, trim($signature));
    }

    public function fetchPayment(string $paymentId): ?array
    {
        try {
            return $this->api()->payment->fetch($paymentId)->toArray();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Issue a Razorpay refund via POST /v1/payments/:id/refund (SDK
     * Payment::refund). A null amount refunds the full remaining balance;
     * otherwise the amount is sent in currency subunits for a partial refund.
     *
     * Credentials are never embedded in exceptions or logs; failures surface
     * as a generic PaymentProviderException with server-side diagnostics.
     */
    public function refundPayment(string $paymentId, ?int $amountPaise = null, array $options = []): ProviderRefundResult
    {
        if ($paymentId === '') {
            throw new PaymentProviderException('Razorpay refund failed.');
        }

        $attributes = [];

        if ($amountPaise !== null) {
            $attributes['amount'] = $amountPaise;
        }

        // Stable merchant identity for correlation + later reconciliation.
        // Razorpay does NOT enforce idempotency on receipt/notes: a retried
        // call with the same receipt still creates a second refund. Unknown-
        // outcome safety therefore comes from fetchRefunds() reconciliation
        // in PaymentService, never from these fields.
        $key = (string) ($options['idempotency_key'] ?? '');

        if (isset($options['receipt']) && is_string($options['receipt']) && $options['receipt'] !== '') {
            $attributes['receipt'] = substr($options['receipt'], 0, 40);
        } elseif ($key !== '') {
            $attributes['receipt'] = substr($key, 0, 40);
        }

        $notes = isset($options['notes']) && is_array($options['notes']) ? $options['notes'] : [];

        if ($key !== '') {
            $notes['idempotency_key'] = substr($key, 0, 256);
        }

        if ($notes !== []) {
            $attributes['notes'] = $notes;
        }

        try {
            $refund = $this->api()->payment->fetch($paymentId)->refund($attributes);
        } catch (PaymentNotConfiguredException $e) {
            // Missing credentials must surface as a configuration error, not
            // be swallowed into a generic provider error.
            throw $e;
        } catch (\Throwable $e) {
            // Server-side diagnostics only: the message returned to callers
            // stays generic so gateway internals are never disclosed.
            \Illuminate\Support\Facades\Log::warning('payment.refund.provider_failed', [
                'provider' => 'razorpay',
                'payment_id' => $paymentId,
                'amount_paise' => $amountPaise,
                'error' => $e->getMessage(),
            ]);

            throw new PaymentProviderException('Razorpay refund failed.', 0, $e);
        }

        $data = $refund->toArray();
        $refundId = (string) ($data['id'] ?? '');

        if ($refundId === '') {
            // Never fabricate a provider refund id: without one the refund
            // cannot be reconciled against later webhooks.
            \Illuminate\Support\Facades\Log::warning('payment.refund.missing_refund_id', [
                'provider' => 'razorpay',
                'payment_id' => $paymentId,
                'amount_paise' => $amountPaise,
            ]);

            throw new PaymentProviderException('Razorpay refund failed.');
        }

        return new ProviderRefundResult(
            provider: 'razorpay',
            refundId: $refundId,
            paymentId: $paymentId,
            amountPaise: (int) ($data['amount'] ?? ($amountPaise ?? 0)),
            currency: strtoupper((string) ($data['currency'] ?? config('payment.currency', 'INR'))),
            status: strtolower((string) ($data['status'] ?? 'processed')),
            metadata: [],
        );
    }

    /**
     * Authoritative refund listing via GET /v1/payments/:id/refunds.
     *
     * Returns null when the gateway is unreachable so the caller fails closed
     * instead of issuing a refund blind. Each item carries the merchant
     * identity (`receipt`, `notes.idempotency_key`) for orphan re-attachment.
     */
    public function fetchRefunds(string $paymentId): ?array
    {
        if ($paymentId === '') {
            return null;
        }

        try {
            $collection = $this->api()->payment->fetch($paymentId)->fetchMultipleRefund(['count' => 100]);
        } catch (PaymentNotConfiguredException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return null;
        }

        $data = $collection->toArray();
        $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];
        $refunds = [];

        foreach ($items as $item) {
            if (! is_array($item) || (string) ($item['id'] ?? '') === '') {
                continue;
            }

            $notes = (isset($item['notes']) && is_array($item['notes'])) ? $item['notes'] : [];

            $refunds[] = [
                'id' => (string) $item['id'],
                'amount' => (int) ($item['amount'] ?? 0),
                'currency' => strtoupper((string) ($item['currency'] ?? '')),
                'status' => strtolower((string) ($item['status'] ?? '')),
                'receipt' => isset($item['receipt']) && $item['receipt'] !== null ? (string) $item['receipt'] : null,
                'idempotency_key' => isset($notes['idempotency_key']) && is_string($notes['idempotency_key']) && $notes['idempotency_key'] !== ''
                    ? $notes['idempotency_key']
                    : null,
            ];
        }

        return $refunds;
    }
}
