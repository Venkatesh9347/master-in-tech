<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\PaymentProviderInterface;
use Illuminate\Support\Str;

/**
 * Deterministic, network-free provider used for local development and tests.
 *
 * The generated payment id is a self-contained, MAC-protected token that
 * encodes the order id, amount (paise) and currency for the payment. This lets
 * fetchPayment() reconstruct the authoritative payment entity without any
 * gateway or shared state, so the confirm + webhook verification paths can be
 * exercised with the same order/amount/currency consistency rules as Razorpay.
 *
 * Token shape: stub_pay_<base64url(JSON)>.<hmac-sha256-12>
 */
class StubPaymentProvider implements PaymentProviderInterface
{
    public function createOrder(int $amountPaise, array $options = []): PaymentOrder
    {
        $idempotencyKey = (string) ($options['idempotency_key'] ?? 'stub_' . Str::random(24));
        $orderId = 'stub_ord_' . Str::random(16);
        $currency = (string) ($options['currency'] ?? config('payment.currency', 'INR'));

        return new PaymentOrder(
            provider: 'stub',
            orderId: $orderId,
            paymentId: $this->buildPaymentToken($orderId, $amountPaise, $currency),
            idempotencyKey: $idempotencyKey,
            amountPaise: $amountPaise,
            currency: $currency,
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
        $data = $this->decodePaymentToken($paymentId);

        if ($data === null) {
            return null;
        }

        return [
            'id' => $paymentId,
            'status' => 'captured',
            'order_id' => $data['o'],
            'amount' => $data['a'],
            'currency' => $data['c'],
        ];
    }

    /* ---------------- token encoding ---------------- */

    private function buildPaymentToken(string $orderId, int $amountPaise, string $currency): string
    {
        $json = json_encode(['o' => $orderId, 'a' => $amountPaise, 'c' => $currency], JSON_UNESCAPED_SLASHES);

        return 'stub_pay_' . $this->base64UrlEncode($json) . '.' . $this->signature($json);
    }

    /**
     * Decode and verify a stub payment token. Returns [o, a, c] or null when
     * the token is malformed or the MAC does not match (tampered/foreign id).
     */
    private function decodePaymentToken(string $paymentId): ?array
    {
        if (! str_starts_with($paymentId, 'stub_pay_')) {
            return null;
        }

        $body = substr($paymentId, strlen('stub_pay_'));

        [$encoded, $mac] = array_pad(explode('.', $body, 2), 2, '');

        if ($encoded === '' || $mac === '') {
            return null;
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($json === false || ! hash_equals($this->signature($json), $mac)) {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['o'], $data['a'], $data['c'])) {
            return null;
        }

        return $data;
    }

    private function signature(string $json): string
    {
        return substr(hash_hmac('sha256', $json, (string) config('app.key')), 0, 12);
    }

    private function base64UrlEncode(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}