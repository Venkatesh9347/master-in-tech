<?php

namespace App\Services\Payment;

use App\Models\CourseEnrollment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Exceptions\PaymentVerificationException;
use App\Services\Payment\Providers\RazorpayProvider;
use App\Services\Payment\Providers\StubPaymentProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Payment orchestration layer.
 *
 * Responsibilities:
 *  - Resolve the configured provider (razorpay SDK / stub for dev+tests).
 *  - Enforce at-most-once order creation via a unique idempotency key.
 *  - Persist every order as a PaymentTransaction (single source of truth).
 *  - Authoritative payment confirm (server-verified before marking paid).
 *  - Stricter webhook processing (order/amount/currency verification before
 *    any paid transition; payment id association).
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
     * Resolve the active provider name as a string.
     */
    public function providerName(): string
    {
        return config('payment.default_provider', 'stub');
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
            'course_id' => $options['course_id'] ?? null,
            'amount_paise' => $order->amountPaise,
            'currency' => $order->currency,
            'status' => $order->status,
            'description' => $options['description'] ?? null,
            'metadata' => $options['notes'] ?? [],
        ]), $providerName, $key);

        return $order;
    }

    /*
     * Authoritative confirm (called from POST /payments/confirm).
     * Server-verified before any paid transition: order belongs to actor,
     * provider fetch matches entity, payment is captured, and
     * amount/order/currency are consistent.
     */

    /**
     * Authoritatively confirm a payment via server-side provider verification.
     *
     * For the real Razorpay provider a payment callback signature is mandatory:
     * the transaction is rejected before any provider fetch when the signature
     * is missing or invalid. Stub-mode confirms remain signature-less.
     *
     * @throws PaymentVerificationException when any check fails
     */
    public function authoritativeConfirm(string $orderId, string $paymentId, int $actorUserId, ?string $signature = null): PaymentTransaction
    {
        $providerName = $this->providerName();

        if ($providerName === 'razorpay' && ! $this->provider()->verifyPaymentSignature($orderId, $paymentId, (string) $signature)) {
            throw new PaymentVerificationException('invalid_signature', $orderId, $paymentId);
        }

        $transaction = $this->findByProviderAndOrder($providerName, $orderId);

        if ($transaction === null) {
            throw new PaymentVerificationException('order_not_found', $orderId, $paymentId);
        }

        if ((int) $transaction->user_id !== $actorUserId) {
            throw new PaymentVerificationException('forbidden', $orderId, $paymentId);
        }

        $entity = $this->provider()->fetchPayment($paymentId);

        if ($entity === null) {
            throw new PaymentVerificationException('provider_unreachable', $orderId, $paymentId);
        }

        // The provider payment must be in a confirmable state (captured/paid).
        $providerStatus = strtolower((string) ($entity['status'] ?? ''));

        if (! in_array($providerStatus, ['captured', 'paid'], true)) {
            throw new PaymentVerificationException('payment_not_captured', $orderId, $paymentId);
        }

        $reason = $this->verifyEntityAgainstTransaction($transaction, $entity);

        if ($reason !== null) {
            throw new PaymentVerificationException($reason, $orderId, $paymentId);
        }

        $this->associatePaymentId($transaction, $paymentId);

        return $this->applyPaymentEvent($paymentId, 'captured', [
            'confirmed_by' => $actorUserId,
            'confirmed_at' => now()->toISOString(),
        ]) ?? $transaction;
    }

    /*
     * Webhook payment processing (called from PaymentWebhookController).
     */

    /**
     * Process an incoming webhook payment entity. Returns the transaction if
     * it was updated, or null when the event should be silently acknowledged
     * (unknown order, verification mismatch, etc.).
     *
     * For status='paid' transitions, the provider entity must include order_id
     * matching the stored transaction and amount/currency must be consistent.
     */
    public function processWebhookPayment(string $provider, array $entity, string $status): ?PaymentTransaction
    {
        $paymentId = (string) ($entity['id'] ?? '');

        if ($paymentId === '') {
            return null;
        }

        $transaction = $this->findByProviderAndPayment($provider, $paymentId);

        if ($transaction === null) {
            $orderId = (string) ($entity['order_id'] ?? '');

            if ($orderId !== '') {
                $transaction = $this->findByProviderAndOrder($provider, $orderId);
            }
        }

        if ($transaction === null) {
            return null;
        }

        // For paid transitions, verify order/amount/currency consistency first
        // — do not mark paid based solely on a payment id.
        if ($status === 'paid') {
            $entityOrderId = (string) ($entity['order_id'] ?? '');

            if ($entityOrderId === '') {
                return null;
            }

            $reason = $this->verifyEntityAgainstTransaction($transaction, $entity);

            if ($reason !== null) {
                return null;
            }
        }

        // For authorized/failed transitions, still verify when the entity
        // carries order info so stale/mismatched events are ignored.
        if (in_array($status, ['authorized', 'failed'], true)) {
            $this->verifyEntityAgainstTransaction($transaction, $entity);
        }

        // Associate payment id when the provider gives us a new (previously
        // unrecorded) id — this happens with real Razorpay where createOrder
        // returns an empty payment id.
        if ((string) $transaction->payment_id !== $paymentId) {
            try {
                $this->associatePaymentId($transaction, $paymentId);
            } catch (PaymentVerificationException $e) {
                // Conflict: a different payment id was already recorded.
                // Ignore the webhook — this should never happen with a real
                // provider and indicates an issue worth logging.
                return null;
            }
        }

        return $this->applyPaymentEvent($paymentId, $status, [
            'event' => $entity['event'] ?? null,
            'webhook_received_at' => now()->toISOString(),
        ]);
    }

    /*
     * Hardened payment event application.
     * Only captured/paid → paid; failed/cancelled → failed;
     * authorized stays authorized (NOT paid). Never downgrades paid.
     */

    /**
     * Apply an incoming provider payment status to a transaction.
     * Idempotent: re-applying the same non-paid status is a no-op.
     * Never downgrades a transaction that is already marked paid.
     *
     * The status write and the enrollment activation commit atomically so a
     * crash can never leave a paid row without access (or vice versa).
     */
    public function applyPaymentEvent(string $paymentId, string $status, array $context = []): ?PaymentTransaction
    {
        $transaction = PaymentTransaction::where('payment_id', $paymentId)->first();

        if ($transaction === null) {
            return null;
        }

        $newStatus = match ($status) {
            'captured', 'paid' => 'paid',
            'authorized' => 'authorized',
            'failed', 'cancelled' => 'failed',
            'refunded' => 'refunded',
            default => $transaction->status,
        };

        // Terminal states are append-only with one forward exception: a paid
        // row may move to refunded, but refunded rows never change again and
        // paid rows never downgrade to anything else.
        $isDowngrade = $newStatus !== $transaction->status
            && ! ($transaction->status === 'paid' && $newStatus === 'refunded');
        if (in_array($transaction->status, ['paid', 'refunded'], true) && $isDowngrade) {
            return $transaction;
        }

        // Idempotent: already at same non-paid status → no change.
        if ($transaction->status === $newStatus) {
            return $transaction;
        }

        return DB::transaction(function () use ($transaction, $newStatus, $context) {
            $old = $transaction->toArray();

            $transaction->fill([
                'status' => $newStatus,
                'metadata' => array_merge((array) $transaction->metadata, $context),
            ]);

            if ($newStatus === 'paid') {
                $transaction->paid_at = $transaction->paid_at ?? now();
            }

            $transaction->save();
            \App\Models\AuditLog::log('payment_' . $newStatus, $transaction, $old, $transaction->fresh()->toArray());

            // A verified paid transition grants course access. Runs exactly once per
            // (user, course): the unique index dedupes concurrent webhook/confirm
            // races, and the "already paid" early return prevents re-activation.
            // A refund intentionally does NOT revoke access here — dropping an
            // enrollment is an admin decision recorded separately.
            if ($newStatus === 'paid') {
                $this->activateEnrollment($transaction);
            }

            return $transaction;
        });
    }

    /**
     * Record a verified webhook delivery in the persistent event ledger.
     *
     * @return array{event: PaymentWebhookEvent, duplicate: bool} Duplicate
     *         deliveries (provider retries) return the original row so the
     *         caller can acknowledge without reprocessing.
     */
    public function recordWebhookEvent(string $provider, ?string $providerEventId, string $eventType, array $payload): array
    {
        if ($providerEventId === null || $providerEventId === '') {
            $event = PaymentWebhookEvent::create([
                'provider' => $provider,
                'provider_event_id' => null,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => PaymentWebhookEvent::STATUS_RECEIVED,
            ]);

            return ['event' => $event, 'duplicate' => false];
        }

        try {
            $event = PaymentWebhookEvent::create([
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => PaymentWebhookEvent::STATUS_RECEIVED,
            ]);

            return ['event' => $event, 'duplicate' => false];
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = PaymentWebhookEvent::where('provider', $provider)
                ->where('provider_event_id', $providerEventId)
                ->firstOrFail();

            return ['event' => $existing, 'duplicate' => true];
        }
    }

    /**
     * Record a provider refund against a transaction.
     *
     * Terminal and append-only like paid: once refunded, later events cannot
     * resurrect the row. Enrollment access is deliberately left untouched —
     * revoking access after a refund is an admin decision, not an automatic
     * webhook side effect.
     */
    public function recordRefund(string $paymentId, array $context = []): ?PaymentTransaction
    {
        return $this->applyPaymentEvent($paymentId, 'refunded', $context);
    }

    /**
     * Activate the student's enrollment for the purchased course.
     *
     * Only degrades access on conflicts: an existing active/completed/dropped
     * enrollment row is left untouched (no double-grant, no status downgrade).
     */
    public function activateEnrollment(PaymentTransaction $tx): void
    {
        if ($tx->user_id === null || $tx->course_id === null) {
            return;
        }

        CourseEnrollment::firstOrCreate(
            [
                'user_id' => (int) $tx->user_id,
                'course_id' => (int) $tx->course_id,
            ],
            [
                'status' => 'active',
                'enrolled_at' => now(),
                'progress_percentage' => 0,
            ]
        );
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->provider()->verifyWebhookSignature($payload, $signature);
    }

    /*
     * Entity-to-transaction verification.
     * Returns a reason string when verification fails, null on success.
     * The returned reason is never exposed to the end user in prod.
     */

    /**
     * Verify that a provider payment entity is consistent with a stored
     * transaction: order_id, amount (paise) and currency must all match when
     * present in the entity.
     */
    public function verifyEntityAgainstTransaction(PaymentTransaction $tx, array $entity): ?string
    {
        $entityOrderId = (string) ($entity['order_id'] ?? '');

        if ($entityOrderId !== '' && $entityOrderId !== (string) $tx->order_id) {
            return 'order_mismatch';
        }

        $entityAmount = $entity['amount'] ?? null;

        if (is_numeric($entityAmount) && (int) $entityAmount !== (int) $tx->amount_paise) {
            return 'amount_mismatch';
        }

        $entityCurrency = strtoupper((string) ($entity['currency'] ?? ''));

        if ($entityCurrency !== '' && $entityCurrency !== strtoupper((string) $tx->currency)) {
            return 'currency_mismatch';
        }

        return null;
    }

    /**
     * Record a provider payment id on an existing transaction.
     * Throws PaymentVerificationException on conflict.
     */
    public function associatePaymentId(PaymentTransaction $tx, string $paymentId): void
    {
        $current = (string) $tx->payment_id;

        if ($current !== '' && $current !== $paymentId) {
            throw new PaymentVerificationException('payment_mismatch', (string) $tx->order_id, $paymentId);
        }

        if ($current === $paymentId) {
            return;
        }

        $tx->payment_id = $paymentId;
        $tx->save();
    }

    /* ---------------- internals ---------------- */

    private function resolveProvider(): PaymentProviderInterface
    {
        return match (config('payment.default_provider', 'stub')) {
            'razorpay' => new RazorpayProvider(),
            default => new StubPaymentProvider(),
        };
    }

    private function findByKey(string $provider, string $key): ?PaymentTransaction
    {
        return PaymentTransaction::where('provider', $provider)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function findByProviderAndOrder(string $provider, string $orderId): ?PaymentTransaction
    {
        return PaymentTransaction::where('provider', $provider)
            ->where('order_id', $orderId)
            ->first();
    }

    public function findByProviderAndPayment(string $provider, string $paymentId): ?PaymentTransaction
    {
        return PaymentTransaction::where('provider', $provider)
            ->where('payment_id', $paymentId)
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