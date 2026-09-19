<?php

namespace App\Services\Payment;

use App\Models\CourseEnrollment;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\Data\PaymentOrder;
use App\Services\Payment\Exceptions\PaymentNotConfiguredException;
use App\Services\Payment\Exceptions\PaymentProviderException;
use App\Services\Payment\Exceptions\PaymentRefundException;
use App\Services\Payment\Exceptions\PaymentVerificationException;
use App\Services\Payment\Providers\RazorpayProvider;
use App\Services\Payment\Providers\StubPaymentProvider;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * B3-14: production fail-closed guard. Production must never silently
     * operate with the stub provider or missing Razorpay credentials. Local
     * and test environments are unaffected. Messages never expose secrets.
     *
     * @throws \App\Services\Payment\Exceptions\PaymentNotConfiguredException
     */
    public function ensureProductionPaymentConfigured(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $provider = (string) config('payment.default_provider', 'stub');

        if ($provider !== 'razorpay') {
            throw new \App\Services\Payment\Exceptions\PaymentNotConfiguredException(
                'Payments are not configured for production.'
            );
        }

        if ((string) config('services.razorpay.key_id') === ''
            || (string) config('services.razorpay.key_secret') === ''
            || (string) config('services.razorpay.webhook_secret') === '') {
            throw new \App\Services\Payment\Exceptions\PaymentNotConfiguredException(
                'Payments are not configured for production.'
            );
        }
    }

    /**
     * Create a payment order for an amount in paise/cents.
     *
     * Idempotency: same-price retries reuse the existing transaction and the
     * gateway is NOT called again. Refunded terminals and stale-price
     * non-terminals advance to a versioned key (`{base}_v2`, …).
     *
     * @param  int  $amountPaise  Amount in minor units (paise/cents).
     * @param  array{idempotency_key?: string, description?: string, customer?: array, notes?: array}  $options
     */
    public function createOrder(int $amountPaise, array $options = []): PaymentOrder
    {
        $this->ensureProductionPaymentConfigured();

        $providerName = $this->providerName();
        $baseKey = $options['idempotency_key'] ?? PaymentTransaction::newIdempotencyKey();
        $currency = (string) ($options['currency'] ?? config('payment.currency', 'INR'));

        $key = $this->resolveOrderKey($providerName, $baseKey, $amountPaise, $currency);

        $existing = $this->findByKey($providerName, $key);
        if ($existing !== null) {
            return $this->orderFromTransaction($existing, reused: true);
        }

        $order = $this->provider()->createOrder($amountPaise, array_merge($options, [
            'idempotency_key' => $key,
        ]));

        $saved = $this->persist(new PaymentTransaction([
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

        // B3-10: on a unique-constraint race the loser returns the winner's
        // persisted order, never its own orphan gateway order.
        if ($saved->order_id !== $order->orderId) {
            return $this->orderFromTransaction($saved, reused: true);
        }

        return $order;
    }

    /**
     * Resolve the idempotency key for a new order request.
     *
     * Same-price retries reuse the base key. Refunded terminals and
     * stale-price non-terminals advance to the next free versioned key so the
     * old row is preserved and never resurrected or silently repriced.
     */
    private function resolveOrderKey(string $provider, string $baseKey, int $amountPaise, string $currency): string
    {
        $existing = $this->findByKey($provider, $baseKey);

        if ($existing === null) {
            return $baseKey;
        }

        $sameAmount = (int) $existing->amount_paise === (int) $amountPaise
            && strtoupper((string) $existing->currency) === strtoupper($currency);

        // Paid rows are reused (already have access; never double-charge).
        // Non-terminal rows with the same amount are reused (legit retry).
        if ($existing->status === 'paid' || ($sameAmount && $existing->status !== 'refunded')) {
            return $baseKey;
        }

        for ($version = 2; $version <= 25; $version++) {
            $candidate = $baseKey.'_v'.$version;
            $row = $this->findByKey($provider, $candidate);

            if ($row === null) {
                return $candidate;
            }

            $rowSame = (int) $row->amount_paise === (int) $amountPaise
                && strtoupper((string) $row->currency) === strtoupper($currency);

            if ($row->status === 'paid' || ($rowSame && $row->status !== 'refunded')) {
                return $candidate;
            }
        }

        return $baseKey.'_v'.time();
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
        $this->ensureProductionPaymentConfigured();

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
            'provider' => $providerName,
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
        // carries order info so stale/mismatched events are ignored. Events
        // without order linkage keep the historical behavior (still recorded
        // as authorized/failed; they never grant access).
        if (in_array($status, ['authorized', 'failed'], true)) {
            $entityOrderId = (string) ($entity['order_id'] ?? '');
            if ($entityOrderId !== '' && $this->verifyEntityAgainstTransaction($transaction, $entity) !== null) {
                return null;
            }
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
            'provider' => $provider,
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
        // B3-9: prefer the provider-scoped lookup so identical payment IDs
        // from different providers can never cross-contaminate.
        $provider = isset($context['provider']) && is_string($context['provider']) && $context['provider'] !== ''
            ? $context['provider']
            : null;

        $transaction = $provider !== null
            ? $this->findByProviderAndPayment($provider, $paymentId)
            : PaymentTransaction::where('payment_id', $paymentId)->first();

        if ($transaction === null && $provider !== null) {
            $transaction = PaymentTransaction::where('payment_id', $paymentId)->first();
        }

        if ($transaction === null) {
            return null;
        }

        $transactionId = (int) $transaction->id;

        return DB::transaction(function () use ($transactionId, $paymentId, $status, $context) {
            // B3-9: row-level lock makes paid/refunded races last-writer-safe
            // under the terminal-state rules below (no TOCTOU downgrade).
            $transaction = PaymentTransaction::where('id', $transactionId)->lockForUpdate()->first();

            if ($transaction === null) {
                return null;
            }

            $newStatus = match ($status) {
                'captured', 'paid' => 'paid',
                'authorized' => 'authorized',
                'failed', 'cancelled' => 'failed',
                'partially_refunded' => 'partially_refunded',
                'refunded' => 'refunded',
                default => $transaction->status,
            };

            // Terminal states are append-only with forward-only refund
            // progression: paid -> partially_refunded -> refunded. A refunded
            // row never changes again; paid/partially_refunded rows never
            // downgrade to anything else.
            $forwardOnly = [
                'paid' => ['partially_refunded', 'refunded'],
                'partially_refunded' => ['refunded'],
                'refunded' => [],
            ];

            if (array_key_exists($transaction->status, $forwardOnly)
                && $newStatus !== $transaction->status
                && ! in_array($newStatus, $forwardOnly[$transaction->status], true)) {
                return $transaction;
            }

            // Idempotent: already at same status → no change.
            if ($transaction->status === $newStatus) {
                return $transaction;
            }

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
        // B3-8: deliveries without a provider event ID get a deterministic
        // fallback key (no secrets hashed) so identical redeliveries dedupe
        // instead of creating unlimited ledger rows.
        if ($providerEventId === null || $providerEventId === '') {
            $providerEventId = self::fallbackWebhookEventId($provider, $eventType, $payload);
        }

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

        // Atomic insert-if-absent: a single statement the database serializes
        // on the unique index, so concurrent duplicate deliveries converge on
        // one row. Unlike insert-then-catch, this never raises a unique
        // violation — which matters on PostgreSQL, where a failed statement
        // aborts the enclosing transaction and would break the fetch below
        // (SQLSTATE 25P02). Eloquent model events/casts are bypassed here,
        // so every column is provided explicitly exactly as the model would
        // persist it (including timestamps and JSON-encoded payload).
        $now = now();
        $encodedPayload = json_encode($payload);

        if ($encodedPayload === false) {
            throw JsonEncodingException::forAttribute(
                new PaymentWebhookEvent(),
                'payload',
                (string) json_last_error_msg()
            );
        }

        $inserted = PaymentWebhookEvent::insertOrIgnore([
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'event_type' => $eventType,
            'payload' => $encodedPayload,
            'status' => PaymentWebhookEvent::STATUS_RECEIVED,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $event = PaymentWebhookEvent::where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->firstOrFail();

        return ['event' => $event, 'duplicate' => $inserted === 0];
    }

    /**
     * Deterministic fallback deduplication key for deliveries without a
     * provider event ID. Built only from non-secret routing identifiers
     * (entity/payment/order linkage, type, amount, currency, status) so
     * identical redeliveries map to one ledger row without hashing secrets
     * or storing extra sensitive material.
     */
    public static function fallbackWebhookEventId(string $provider, string $eventType, array $payload): ?string
    {
        $entity = $payload['payload']['payment']['entity']
            ?? $payload['payload']['order']['entity']
            ?? $payload['payload']['refund']['entity']
            ?? null;

        if (! is_array($entity)) {
            return null;
        }

        $paymentId = (string) ($entity['payment_id'] ?? $entity['id'] ?? '');
        $orderId = (string) ($entity['order_id'] ?? '');
        $amount = isset($entity['amount']) && is_numeric($entity['amount']) ? (string) (int) $entity['amount'] : '';
        $currency = strtoupper((string) ($entity['currency'] ?? ''));
        $entityStatus = strtolower((string) ($entity['status'] ?? ''));

        if ($paymentId === '' && $orderId === '') {
            return null;
        }

        $fingerprint = implode('|', [$provider, $eventType, $paymentId, $orderId, $amount, $currency, $entityStatus]);

        return 'noid_'.substr(hash('sha256', $fingerprint), 0, 32);
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
     * Total amount (paise) already refunded for a transaction across all
     * `succeeded` refund ledger rows. Failed provider attempts never reduce
     * the remaining refundable amount.
     */
    public function refundedAmountFor(PaymentTransaction $transaction): int
    {
        return (int) PaymentRefund::where('payment_transaction_id', $transaction->id)
            ->where('status', PaymentRefund::STATUS_SUCCEEDED)
            ->sum('amount_paise');
    }

    /**
     * Remaining refundable amount (paise): captured minus succeeded refunds,
     * floored at zero.
     */
    public function remainingRefundableFor(PaymentTransaction $transaction): int
    {
        return max(0, (int) $transaction->amount_paise - $this->refundedAmountFor($transaction));
    }

    /**
     * Phase 1 of initiateRefund: reconcile authoritative provider refund
     * state into the local ledger before any new refund may be issued.
     *
     * Runs in its own transaction so reconciled truth commits even when the
     * subsequent issue phase rejects the request. Discovers orphans from
     * lost-response attempts (and out-of-band dashboard refunds) and records
     * them as `reconciled` rows, restoring the original logical identity
     * from `notes.idempotency_key` so a same-key retry replays the original
     * row instead of issuing a second provider refund.
     *
     * Fails closed when provider state is unreachable: issuing blind while
     * unable to verify is exactly how double refunds happen.
     *
     * @throws PaymentRefundException
     */
    public function reconcileProviderRefunds(PaymentTransaction $transaction, string $providerName, int $adminId): void
    {
        DB::transaction(function () use ($transaction, $providerName, $adminId) {
            $locked = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($locked === null || $locked->provider !== $providerName) {
                throw new PaymentRefundException('payment_not_found', (string) $transaction->payment_id);
            }

            if (! in_array($locked->status, ['paid', 'partially_refunded'], true)) {
                return;
            }

            if ((string) $locked->payment_id === '') {
                return;
            }

            try {
                $remote = $this->provider()->fetchRefunds((string) $locked->payment_id);
            } catch (PaymentNotConfiguredException $e) {
                throw new PaymentRefundException('provider_not_configured', (string) $locked->payment_id);
            } catch (\Throwable $e) {
                $remote = null;
            }

            if ($remote === null) {
                Log::warning('payment.refund.reconcile_unavailable', [
                    'provider' => $providerName,
                    'payment_transaction_id' => $locked->id,
                ]);

                throw new PaymentRefundException('provider_failed', (string) $locked->payment_id);
            }

            $inserted = [];

            foreach ($remote as $item) {
                if (! is_array($item) || (string) ($item['id'] ?? '') === '') {
                    continue;
                }

                // Failed/cancelled provider refunds moved no money: ignore.
                if (in_array(strtolower((string) ($item['status'] ?? '')), ['failed', 'cancelled'], true)) {
                    continue;
                }

                $already = PaymentRefund::where('provider', $providerName)
                    ->where('provider_refund_id', (string) $item['id'])
                    ->first();

                if ($already !== null) {
                    continue;
                }

                // Restore the original logical identity when the provider
                // echoes it back; otherwise key by the provider refund id so
                // concurrent reconcilers still converge on one row.
                $key = (isset($item['idempotency_key']) && is_string($item['idempotency_key']) && $item['idempotency_key'] !== '')
                    ? substr($item['idempotency_key'], 0, 120)
                    : substr('reconciled_'.(string) $item['id'], 0, 120);

                try {
                    $inserted[] = PaymentRefund::create([
                        'payment_transaction_id' => $locked->id,
                        'provider' => $providerName,
                        'provider_refund_id' => (string) $item['id'],
                        'idempotency_key' => $key,
                        'initiated_by' => $adminId,
                        'amount_paise' => max(0, (int) ($item['amount'] ?? 0)),
                        'currency' => (string) ($item['currency'] !== '' ? $item['currency'] : $locked->currency),
                        'status' => PaymentRefund::STATUS_SUCCEEDED,
                        'source' => PaymentRefund::SOURCE_RECONCILED,
                        'metadata' => ['reconciled' => true],
                    ]);
                } catch (QueryException $e) {
                    if (! $this->isUniqueViolation($e)) {
                        throw $e;
                    }
                    // A concurrent reconciler or webhook recorded it first.
                }
            }

            if ($inserted === []) {
                return;
            }

            // The transaction status must reflect provider truth discovered
            // above, even though no new refund was issued in this request.
            $cumulative = $this->refundedAmountFor($locked);
            $old = $locked->toArray();

            if ($cumulative >= (int) $locked->amount_paise) {
                $locked->fill(['status' => 'refunded']);
            } elseif ($cumulative > 0 && $locked->status === 'paid') {
                $locked->fill(['status' => 'partially_refunded']);
            }

            if ($locked->isDirty('status')) {
                $locked->save();
            }

            \App\Models\AuditLog::log('payment_refund_reconciled', $locked, $old, [
                'payment_transaction_id' => $locked->id,
                'provider' => $providerName,
                'reconciled_refund_ids' => array_map(
                    static fn (PaymentRefund $r): ?string => $r->provider_refund_id,
                    $inserted
                ),
                'reconciled_amount_paise' => array_sum(
                    array_map(static fn (PaymentRefund $r): int => (int) $r->amount_paise, $inserted)
                ),
                'currency' => (string) $locked->currency,
                'initiated_by' => $adminId,
            ]);
        });
    }

    /**
     * Administrator-initiated outbound refund (P1-A).
     *
     * Unknown-outcome safety (Blocker 2): the gateway offers NO
     * provider-side idempotency for refunds (no Idempotency-Key support;
     * `receipt`/`notes` are correlation aids only, verified against the
     * installed SDK + Razorpay docs). A lost response after gateway
     * acceptance therefore looks identical to a definite failure. To ensure
     * such an ambiguous outcome can never silently become a second refund,
     * every attempt runs in two phases:
     *
     * Phase 1 — reconcile: under lock, fetch the authoritative provider
     * refund list and persist any provider refunds missing from the local
     * ledger (source `reconciled`, original logical identity restored from
     * `notes.idempotency_key`). If provider state is unreachable the request
     * fails closed (retryable) instead of issuing blind.
     *
     * Phase 2 — issue: under lock, re-check the remaining amount (now
     * including reconciled orphans), replay same-key duplicates, and only
     * then call the gateway.
     *
     * Further guarantees:
     * - Only paid/partially_refunded transactions are refundable; uncaptured,
     *   failed, or already-refunded rows are rejected.
     * - The amount is validated (> 0, never above the remaining refundable
     *   amount computed from previously recorded refunds). A null amount
     *   refunds exactly the remaining balance — never the original captured
     *   amount again after a prior partial.
     * - The payment row is locked (lockForUpdate) before the remaining amount
     *   is calculated and re-checked, so two simultaneous requests cannot both
     *   conclude the same amount is refundable.
     * - Replaying the same idempotency key returns the original refund row
     *   instead of refunding twice (unique key backstop on races). A reused
     *   key with a different explicit amount is a 409 conflict.
     * - Definite provider failure persists no successful refund row and
     *   fabricates no provider refund id; the failure is audit-logged via the
     *   existing AuditLog convention plus server-side diagnostics, and the
     *   request stays retryable.
     * - Enrollment access is never touched: revoking access after a refund is
     *   an explicit admin decision, not an automatic side effect.
     *
     * @param  array{idempotency_key?: ?string, reason?: ?string}  $options
     *
     * @throws PaymentRefundException with a client-safe reason code
     */
    public function initiateRefund(
        PaymentTransaction $transaction,
        ?int $amountPaise,
        int $adminId,
        array $options = []
    ): PaymentRefund {
        $this->ensureProductionPaymentConfigured();

        $providerName = $this->providerName();
        $requestedKey = isset($options['idempotency_key']) && is_string($options['idempotency_key']) && $options['idempotency_key'] !== ''
            ? substr($options['idempotency_key'], 0, 120)
            : null;
        $reason = isset($options['reason']) && is_string($options['reason']) ? substr(trim($options['reason']), 0, 500) : null;

        try {
            // Phase 1 commits independently: reconciled (true) provider state
            // must survive even when phase 2 then rejects the request.
            $this->reconcileProviderRefunds($transaction, $providerName, $adminId);

            return DB::transaction(function () use ($transaction, $amountPaise, $adminId, $providerName, $requestedKey, $reason) {
                // Lock the payment row first: every concurrent refund for this
                // payment serializes here, and the remaining-amount re-check
                // below runs inside the lock.
                $locked = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

                if ($locked === null) {
                    throw new PaymentRefundException('payment_not_found', (string) $transaction->payment_id);
                }

                if ($locked->provider !== $providerName) {
                    throw new PaymentRefundException('payment_not_found', (string) $locked->payment_id);
                }

                $key = $requestedKey ?? 'refund_'.Str::random(32);

                // Same-key replay first: an exact duplicate of a completed
                // logical request returns the original row even when the
                // transaction has since reached `refunded` (e.g. the retry
                // that reconciled the orphan which completed the refund).
                // A reused key for a different payment or a different
                // explicit amount is a conflict, never a silent replay.
                $duplicate = PaymentRefund::where('provider', $providerName)
                    ->where('idempotency_key', $key)
                    ->first();

                if ($duplicate !== null) {
                    if ((int) $duplicate->payment_transaction_id !== (int) $locked->id) {
                        throw new PaymentRefundException('idempotency_key_conflict', (string) $locked->payment_id);
                    }

                    if ($amountPaise !== null && (int) $duplicate->amount_paise !== $amountPaise) {
                        throw new PaymentRefundException('idempotency_key_conflict', (string) $locked->payment_id);
                    }

                    return $duplicate;
                }

                if (! in_array($locked->status, ['paid', 'partially_refunded'], true)) {
                    throw new PaymentRefundException(
                        $locked->status === 'refunded' ? 'already_refunded' : 'not_refundable',
                        (string) $locked->payment_id
                    );
                }

                if ((string) $locked->payment_id === '') {
                    throw new PaymentRefundException('not_refundable', '');
                }

                $remaining = $this->remainingRefundableFor($locked);

                if ($remaining <= 0) {
                    throw new PaymentRefundException('already_refunded', (string) $locked->payment_id);
                }

                $amount = $amountPaise ?? $remaining;

                if ($amount <= 0) {
                    throw new PaymentRefundException('invalid_amount', (string) $locked->payment_id);
                }

                if ($amount > $remaining) {
                    throw new PaymentRefundException('amount_exceeds_remaining', (string) $locked->payment_id);
                }

                try {
                    $result = $this->provider()->refundPayment(
                        (string) $locked->payment_id,
                        $amount,
                        ['idempotency_key' => $key, 'currency' => (string) $locked->currency]
                    );
                } catch (PaymentNotConfiguredException $e) {
                    throw new PaymentRefundException('provider_not_configured', (string) $locked->payment_id);
                } catch (PaymentProviderException $e) {
                    throw new PaymentRefundException('provider_failed', (string) $locked->payment_id);
                }

                if ($result->refundId === '') {
                    Log::warning('payment.refund.missing_refund_id', [
                        'provider' => $providerName,
                        'payment_id' => (string) $locked->payment_id,
                    ]);

                    throw new PaymentRefundException('provider_failed', (string) $locked->payment_id);
                }

                try {
                    $refund = PaymentRefund::create([
                        'payment_transaction_id' => $locked->id,
                        'provider' => $providerName,
                        'provider_refund_id' => $result->refundId,
                        'idempotency_key' => $key,
                        'initiated_by' => $adminId,
                        'amount_paise' => $amount,
                        'currency' => (string) $locked->currency,
                        'status' => PaymentRefund::STATUS_SUCCEEDED,
                        'source' => PaymentRefund::SOURCE_ADMIN,
                        'metadata' => array_filter(['reason' => $reason]),
                    ]);
                } catch (QueryException $e) {
                    if (! $this->isUniqueViolation($e)) {
                        throw $e;
                    }

                    // Same-key race: the winner already committed; return its
                    // row instead of refunding twice.
                    $winner = PaymentRefund::where('provider', $providerName)
                        ->where('idempotency_key', $key)
                        ->first();

                    if ($winner !== null && (int) $winner->payment_transaction_id === (int) $locked->id) {
                        return $winner;
                    }

                    throw new PaymentRefundException('idempotency_key_conflict', (string) $locked->payment_id);
                }

                $cumulative = $this->refundedAmountFor($locked);
                $newStatus = $cumulative >= (int) $locked->amount_paise ? 'refunded' : 'partially_refunded';
                $old = $locked->toArray();

                $locked->fill(['status' => $newStatus]);
                $locked->save();

                // Single audited admin action (safe fields only: no secrets,
                // no gateway payloads, no customer PII beyond internal ids).
                \App\Models\AuditLog::log('payment_refund_initiated', $locked, $old, [
                    'payment_transaction_id' => $locked->id,
                    'provider' => $providerName,
                    'provider_refund_id' => $result->refundId,
                    'amount_paise' => $amount,
                    'currency' => (string) $locked->currency,
                    'remaining_refundable_paise' => max(0, (int) $locked->amount_paise - $cumulative),
                    'initiated_by' => $adminId,
                    'reason' => $reason,
                ]);

                return $refund->fresh() ?? $refund;
            });
        } catch (PaymentRefundException $e) {
            // Failed attempts are audit-logged under the existing convention
            // (no second mechanism) without persisting a refund row, so a
            // retry with the same logical key re-attempts the provider instead
            // of replaying a failure. Provider-side failure details stay in
            // server logs; the reason code stays client-safe.
            if (in_array($e->reasonCode(), ['provider_failed', 'provider_not_configured'], true)) {
                Log::warning('payment.refund.rejected', [
                    'reason' => $e->reasonCode(),
                    'payment_transaction_id' => $transaction->id,
                    'admin_id' => $adminId,
                ]);

                \App\Models\AuditLog::log('payment_refund_failed', $transaction, null, [
                    'payment_transaction_id' => $transaction->id,
                    'reason' => $e->reasonCode(),
                    'initiated_by' => $adminId,
                ]);
            }

            throw $e;
        }
    }

    /**
     * Reconcile an inbound provider refund webhook against the outbound
     * refund ledger. When the provider refund id was already recorded by an
     * administrator-initiated refund, the webhook is acknowledged without
     * creating a duplicate row; otherwise the refund is recorded as
     * webhook-sourced and the existing terminal-state rules apply.
     */
    public function reconcileInboundRefund(
        string $provider,
        string $providerRefundId,
        string $paymentId,
        ?int $amountPaise,
        array $context = []
    ): ?PaymentTransaction {
        if ($providerRefundId !== '') {
            $existing = PaymentRefund::where('provider', $provider)
                ->where('provider_refund_id', $providerRefundId)
                ->first();

            if ($existing !== null) {
                return $existing->transaction;
            }
        }

        $transaction = $this->findByProviderAndPayment($provider, $paymentId);

        if ($transaction === null) {
            return null;
        }

        // Record the provider refund exactly once (provider retries of the
        // same delivery hit the unique index and fall through to the stored
        // row instead of double-counting).
        if ($providerRefundId !== '') {
            try {
                PaymentRefund::create([
                    'payment_transaction_id' => $transaction->id,
                    'provider' => $provider,
                    'provider_refund_id' => $providerRefundId,
                    'idempotency_key' => 'webhook_'.$providerRefundId,
                    'initiated_by' => null,
                    'amount_paise' => (int) ($amountPaise ?? $transaction->amount_paise),
                    'currency' => (string) $transaction->currency,
                    'status' => PaymentRefund::STATUS_SUCCEEDED,
                    'source' => PaymentRefund::SOURCE_WEBHOOK,
                    'metadata' => [],
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                // Concurrent duplicate delivery already recorded it.
            }
        }

        return $this->recordRefund($paymentId, $context);
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
        // B3-14: production never accepts stub verification semantics.
        if (app()->environment('production') && (string) config('payment.default_provider', 'stub') !== 'razorpay') {
            return false;
        }

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
     * concurrent identical requests. The loser re-fetches and returns the
     * committed winner so both callers agree on one transaction (at-most-once).
     */
    private function persist(PaymentTransaction $tx, string $provider, string $key): PaymentTransaction
    {
        try {
            $tx->save();

            return $tx->fresh() ?? $tx;
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            // Concurrent duplicate: another request already committed this key.
            // Return the winner instead of an orphan gateway order.
            $winner = $this->findByKey($provider, $key);

            if ($winner === null) {
                $winner = static::findByOrderFallback($provider, $tx);
            }

            if ($winner !== null) {
                return $winner;
            }

            throw $e;
        }
    }

    private static function findByOrderFallback(string $provider, PaymentTransaction $tx): ?PaymentTransaction
    {
        if (! empty($tx->order_id)) {
            $row = PaymentTransaction::where('provider', $provider)
                ->where('order_id', $tx->order_id)
                ->first();

            if ($row !== null) {
                return $row;
            }
        }

        return null;
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