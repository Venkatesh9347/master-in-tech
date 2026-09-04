<?php

namespace App\Services\Payment;

use App\Models\PaymentTransaction;
use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Providers\RazorpayProvider;
use App\Services\Payment\Providers\StubPaymentProvider;
use Illuminate\Database\QueryException;

/**
 * Payment orchestration layer.
 *
 * Responsibilities:
 *  - Resolve the configured provider (razorpay SDK / stub for dev+tests).
 *  - Enforce at-most-once order creation via a unique idempotency key.
 *  - Persist every order as a PaymentTransaction (single source of truth).
 *
 * No credentials live here; they are read from config/env and a real provider
 * will raise PaymentNotConfiguredException when keys are missing.
 */
class PaymentService
{
    public function __construct(private readonly ?PaymentProviderInterface $providerOverride = null)
    {
    }

    /**
     * Resolve the active provider.
     */
    public function provider(): PaymentProviderInterface
    {
        return $this->providerOverride ?? $this->resolveProvider();
    }

    /**
     * Create a payment order for an amount in paise/cents.
     *
     * When an idempotency key is supplied and a transaction already exists for
     * that (provider, key), the existing order is returned untouched and the
     * gateway is NOT called again — preventing duplicate charges.
     *
     * @param  int  $amountPaise  Amount in minor units (paise/cents).
     * @param  array{idempotency_key?: string, description?: string, customer?: array, notes?: array}  $options
     */
    public function createOrder(int $amountPaise, array $options = []): PaymentOrder
    {
        $providerName = $this->providerName();
        $key = $options['idempotency_key'] ?? PaymentTransaction::newIdempotencyKey();

        $existing = $this->findByKey($providerName, $key);
        if ($existing !== null) {
            return $this->orderFromTransaction($existing, reused: true);
        }

        $order = $this->provider()->createOrder($amountPaise, array_merge($options, [
            'idempotency_key' => $key,
        ]));

        $this->persist(new PaymentTransaction([
            'provider' => $order->provider,
            'order_id' => $order->orderId,
            'payment_id' => $order->paymentId,
            'idempotency_key' => $order->idempotencyKey !== '' ? $order->idempotencyKey : $key,
            'user_id' => $options['user_id'] ?? null,
            'amount_paise' => $order->amountPaise,
            'currency' => $order->currency,
            'status' => $order->status,
            'description' => $options['description'] ?? null,
            'metadata' => $options['notes'] ?? [],
        ]), $providerName, $key);

        return $order;
    }

    /**
     * Apply an incoming provider webhook event to a transaction (e.g. mark paid).
     * Idempotent: re-applying the same payment event is a no-op.
     */
    public function applyPaymentEvent(string $paymentId, string $status, array $context = []): ?PaymentTransaction
    {
        $transaction = PaymentTransaction::where('payment_id', $paymentId)->first();

        if ($transaction === null) {
            return null;
        }

        $newStatus = match ($status) {
            'captured', 'paid', 'authorized' => 'paid',
            'failed', 'cancelled' => 'failed',
            default => $transaction->status,
        };

        // Idempotent: already-final events are not downgraded.
        if ($transaction->status === $newStatus && $newStatus !== 'paid') {
            return $transaction;
        }

        $transaction->fill([
            'status' => $newStatus,
            'metadata' => array_merge((array) $transaction->metadata, $context),
        ]);

        if ($newStatus === 'paid') {
            $transaction->paid_at = $transaction->paid_at ?? now();
        }

        $transaction->save();

        return $transaction;
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->provider()->verifyWebhookSignature($payload, $signature);
    }

    /* ---------------- internals ---------------- */

    private function resolveProvider(): PaymentProviderInterface
    {
        return match (config('payment.default_provider', 'stub')) {
            'razorpay' => new RazorpayProvider(),
            default => new StubPaymentProvider(),
        };
    }

    private function providerName(): string
    {
        return config('payment.default_provider', 'stub');
    }

    private function findByKey(string $provider, string $key): ?PaymentTransaction
    {
        return PaymentTransaction::where('provider', $provider)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Insert the transaction, tolerating a unique-constraint race from two
     * concurrent identical requests. The loser re-fetches the committed row so
     * both callers agree on one transaction (at-most-once).
     */
    private function persist(PaymentTransaction $tx, string $provider, string $key): void
    {
        try {
            $tx->save();
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            // Concurrent duplicate: another request already committed this key.
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE') || str_contains($message, 'unique') || str_contains($message, '1062');
    }

    private function orderFromTransaction(PaymentTransaction $tx, bool $reused): PaymentOrder
    {
        return new PaymentOrder(
            provider: $tx->provider,
            orderId: (string) $tx->order_id,
            paymentId: (string) $tx->payment_id,
            idempotencyKey: (string) $tx->idempotency_key,
            amountPaise: (int) $tx->amount_paise,
            currency: (string) $tx->currency,
            status: (string) $tx->status,
            metadata: ['reused' => $reused],
        );
    }
}
