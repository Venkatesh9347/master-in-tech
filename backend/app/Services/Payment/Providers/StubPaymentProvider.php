<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Data\ProviderRefundResult;
use App\Services\Payment\Exceptions\PaymentProviderException;
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

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        // Stub confirms require no signature (non-empty is always accepted).
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

    /**
     * Test-only switch: when true, the next refundPayment() call throws a
     * provider failure instead of succeeding. Reset via resetFailure().
     */
    private static bool $failNextRefund = false;

    /**
     * Simulates the unknown-outcome window: the refund IS accepted
     * provider-side (registered, discoverable via fetchRefunds) but the
     * response is lost, so the caller sees a failure. Reset via resetState().
     */
    private static bool $loseNextRefundResponse = false;

    /**
     * In-memory provider-side refund registry: payment id => normalized
     * refund rows. Models gateway-side state that survives an application
     * rollback, so reconciliation paths can be tested deterministically.
     *
     * @var array<string, array<int, array{id: string, amount: int, currency: string, status: string, receipt: ?string, idempotency_key: ?string}>>
     */
    private static array $issuedRefunds = [];

    public static function failNextRefund(): void
    {
        self::$failNextRefund = true;
    }

    public static function loseNextRefundResponse(): void
    {
        self::$loseNextRefundResponse = true;
    }

    public static function resetFailure(): void
    {
        self::resetState();
    }

    public static function resetState(): void
    {
        self::$failNextRefund = false;
        self::$loseNextRefundResponse = false;
        self::$issuedRefunds = [];
    }

    /**
     * @return array<int, array{id: string, amount: int, currency: string, status: string, receipt: ?string, idempotency_key: ?string}>
     */
    public static function issuedRefundsFor(string $paymentId): array
    {
        return array_values(self::$issuedRefunds[$paymentId] ?? []);
    }

    public static function issuedCountFor(string $paymentId): int
    {
        return count(self::$issuedRefunds[$paymentId] ?? []);
    }

    /**
     * Deterministic, network-free outbound refund.
     *
     * Mirrors the Razorpay mapping: the stable logical identity travels as
     * `receipt` (truncated) + `notes.idempotency_key` (full) and is echoed
     * back by fetchRefunds() for orphan re-attachment. The refund id is
     * derived from (payment id, amount, idempotency key) so replaying the
     * same logical request yields the same provider id without shared state.
     * Pass ['fail' => true] (or failNextRefund()) to simulate a definite
     * gateway rejection; loseNextRefundResponse() simulates an accepted
     * refund whose response never arrives.
     */
    public function refundPayment(string $paymentId, ?int $amountPaise = null, array $options = []): ProviderRefundResult
    {
        if (self::$failNextRefund || ($options['fail'] ?? false) === true) {
            self::$failNextRefund = false;

            throw new PaymentProviderException('Stub refund failed (injected).');
        }

        if ($paymentId === '') {
            throw new PaymentProviderException('Stub refund failed.');
        }

        $amount = (int) ($amountPaise ?? $options['captured_amount'] ?? 0);
        $currency = strtoupper((string) ($options['currency'] ?? config('payment.currency', 'INR')));
        $key = (string) ($options['idempotency_key'] ?? '');

        $refundId = 'stub_rfnd_'.substr(
            hash_hmac('sha256', $paymentId.'|'.$amount.'|'.$key, (string) config('app.key')),
            0,
            16
        );

        // Provider-side state lands BEFORE any response is delivered, exactly
        // like a real gateway: a lost response still leaves a live refund.
        self::$issuedRefunds[$paymentId][] = [
            'id' => $refundId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'processed',
            'receipt' => $key !== '' ? substr($key, 0, 40) : null,
            'idempotency_key' => $key !== '' ? $key : null,
        ];

        if (self::$loseNextRefundResponse) {
            self::$loseNextRefundResponse = false;

            throw new PaymentProviderException('Stub refund response lost (injected).');
        }

        return new ProviderRefundResult(
            provider: 'stub',
            refundId: $refundId,
            paymentId: $paymentId,
            amountPaise: $amount,
            currency: $currency,
            status: 'processed',
            metadata: ['stub' => true],
        );
    }

    /**
     * Authoritative provider-side listing (in-memory; always available).
     *
     * @return array<int, array{id: string, amount: int, currency: string, status: string, receipt: ?string, idempotency_key: ?string}>
     */
    public function fetchRefunds(string $paymentId): ?array
    {
        if ($paymentId === '') {
            return null;
        }

        return self::issuedRefundsFor($paymentId);
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