<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Provider webhook endpoint (public but signature-verified + throttled).
     *
     * The raw request body is required for correct HMAC signature verification,
     * so we read it directly rather than relying on parsed JSON.
     *
     * After signature verification, unknown events are acknowledged with 200
     * 'ignored'. For payment events the service performs order/amount/currency
     * verification before any paid transition — the payment id alone is never
     * trusted to mark a transaction as paid.
     */
    public function handle(Request $request, PaymentService $payments): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! $payments->verifyWebhookSignature($payload, $signature)) {
            // B3-15: safe observability (no secrets, no raw payload).
            Log::warning('payment.webhook.invalid_signature', [
                'provider' => $payments->providerName(),
            ]);

            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            Log::warning('payment.webhook.malformed_payload', [
                'provider' => $payments->providerName(),
            ]);

            return response()->json(['error' => 'Malformed payload.'], 400);
        }

        $eventName = (string) ($event['event'] ?? '');
        $providerEventId = isset($event['id']) ? (string) $event['id'] : null;

        $entity = $event['payload']['payment']['entity']
            ?? $event['payload']['order']['entity']
            ?? $event['payload']['refund']['entity']
            ?? null;

        if ($entity === null || ! is_array($entity)) {
            Log::info('payment.webhook.ignored', [
                'provider' => $payments->providerName(),
                'provider_event_id' => $providerEventId,
                'event' => $eventName,
                'reason' => 'missing_entity',
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // Map provider event name -> canonical status.
        $status = match ($eventName) {
            'payment.captured', 'order.paid' => 'paid',
            'payment.failed', 'order.failed' => 'failed',
            'payment.authorized' => 'authorized',
            'refund.processed', 'refund.created' => 'refunded',
            default => 'unknown',
        };

        // Unknown events are silently acknowledged (no retry storm).
        if ($status === 'unknown') {
            Log::info('payment.webhook.unknown_event', [
                'provider' => $payments->providerName(),
                'provider_event_id' => $providerEventId,
                'event' => $eventName,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // Persistent event ledger first: provider retries of an already-seen
        // delivery are acknowledged without reprocessing.
        $provider = $payments->providerName();
        $recorded = $payments->recordWebhookEvent($provider, $providerEventId, $eventName, $event);

        // B3-7 crash recovery: a duplicate stuck at `received` means the first
        // attempt crashed before processed/ignored was recorded. Recover by
        // reprocessing (downstream stays idempotent); only ack-and-skip when
        // the original already reached processed/ignored/failed.
        if ($recorded['duplicate']) {
            $existingStatus = (string) ($recorded['event']->status ?? '');

            if ($existingStatus !== PaymentWebhookEvent::STATUS_RECEIVED) {
                Log::info('payment.webhook.duplicate', [
                    'provider' => $provider,
                    'provider_event_id' => $recorded['event']->provider_event_id,
                    'event' => $eventName,
                    'ledger_status' => $existingStatus,
                ]);

                return response()->json(['status' => 'duplicate']);
            }

            Log::warning('payment.webhook.recovering_received', [
                'provider' => $provider,
                'provider_event_id' => $recorded['event']->provider_event_id,
                'event' => $eventName,
            ]);
        }

        $ledger = $recorded['event']->fresh() ?? $recorded['event'];

        $paymentId = (string) ($entity['id'] ?? '');

        if ($paymentId === '') {
            $ledger->update(['status' => PaymentWebhookEvent::STATUS_IGNORED]);
            Log::info('payment.webhook.ignored', [
                'provider' => $provider,
                'provider_event_id' => $ledger->provider_event_id,
                'event' => $eventName,
                'reason' => 'missing_payment_id',
            ]);

            return response()->json(['error' => 'No payment id in event.'], 422);
        }

        if ($status === 'refunded') {
            // Refund entities carry their own id; the payment link is separate.
            $refundPaymentId = (string) ($entity['payment_id'] ?? $entity['id'] ?? '');
            if ($refundPaymentId === '') {
                $ledger->update(['status' => PaymentWebhookEvent::STATUS_IGNORED]);
                Log::info('payment.webhook.ignored', [
                    'provider' => $provider,
                    'provider_event_id' => $ledger->provider_event_id,
                    'event' => $eventName,
                    'reason' => 'missing_refund_payment_id',
                ]);

                return response()->json(['error' => 'No payment id in event.'], 422);
            }
            $transaction = $payments->recordRefund($refundPaymentId, [
                'provider' => $provider,
                'event' => $eventName,
                'refund_id' => (string) ($entity['id'] ?? ''),
                'webhook_received_at' => now()->toISOString(),
            ]);
        } else {
            $transaction = $payments->processWebhookPayment($provider, $entity, $status);
        }

        if ($transaction === null) {
            $ledger->update(['status' => PaymentWebhookEvent::STATUS_IGNORED]);
            Log::info('payment.webhook.ignored', [
                'provider' => $provider,
                'provider_event_id' => $ledger->provider_event_id,
                'event' => $eventName,
                'reason' => 'verification_or_lookup_failed',
                'payment_id' => $paymentId,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $ledger->update([
            'status' => PaymentWebhookEvent::STATUS_PROCESSED,
            'payment_transaction_id' => $transaction->id,
            'processed_at' => now(),
        ]);

        // F1: only a fresh status transition notifies (duplicate deliveries
        // re-resolve an unchanged row and stay silent). Post-commit here.
        if ($transaction->wasChanged('status')) {
            \App\Services\NotificationService::paymentStatusChanged($transaction);
        }

        Log::info('payment.webhook.processed', [
            'provider' => $provider,
            'provider_event_id' => $ledger->provider_event_id,
            'event' => $eventName,
            'order_id' => $transaction->order_id,
            'payment_id' => $transaction->payment_id,
            'status' => $transaction->status,
        ]);

        return response()->json(['status' => $transaction->status]);
    }
}