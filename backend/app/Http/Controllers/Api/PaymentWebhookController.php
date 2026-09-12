<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return response()->json(['error' => 'Malformed payload.'], 400);
        }

        $entity = $event['payload']['payment']['entity']
            ?? $event['payload']['order']['entity']
            ?? $event['payload']['refund']['entity']
            ?? null;

        if ($entity === null || ! is_array($entity)) {
            return response()->json(['status' => 'ignored']);
        }

        // Map provider event name -> canonical status.
        $status = match ($event['event'] ?? '') {
            'payment.captured', 'order.paid' => 'paid',
            'payment.failed', 'order.failed' => 'failed',
            'payment.authorized' => 'authorized',
            'refund.processed', 'refund.created' => 'refunded',
            default => 'unknown',
        };

        // Unknown events are silently acknowledged (no retry storm).
        if ($status === 'unknown') {
            return response()->json(['status' => 'ignored']);
        }

        // Persistent event ledger first: provider retries of an already-seen
        // delivery are acknowledged without reprocessing.
        $provider = $payments->providerName();
        $providerEventId = isset($event['id']) ? (string) $event['id'] : null;
        $recorded = $payments->recordWebhookEvent($provider, $providerEventId, (string) ($event['event'] ?? ''), $event);

        if ($recorded['duplicate']) {
            return response()->json(['status' => 'duplicate']);
        }

        $ledger = $recorded['event'];

        $paymentId = (string) ($entity['id'] ?? '');

        if ($paymentId === '') {
            $ledger->update(['status' => \App\Models\PaymentWebhookEvent::STATUS_IGNORED]);
            return response()->json(['error' => 'No payment id in event.'], 422);
        }

        if ($status === 'refunded') {
            // Refund entities carry their own id; the payment link is separate.
            $refundPaymentId = (string) ($entity['payment_id'] ?? $entity['id'] ?? '');
            if ($refundPaymentId === '') {
                $ledger->update(['status' => \App\Models\PaymentWebhookEvent::STATUS_IGNORED]);
                return response()->json(['error' => 'No payment id in event.'], 422);
            }
            $transaction = $payments->recordRefund($refundPaymentId, [
                'event' => $event['event'] ?? null,
                'refund_id' => (string) ($entity['id'] ?? ''),
                'webhook_received_at' => now()->toISOString(),
            ]);
        } else {
            $transaction = $payments->processWebhookPayment($provider, $entity, $status);
        }

        if ($transaction === null) {
            $ledger->update(['status' => \App\Models\PaymentWebhookEvent::STATUS_IGNORED]);
            return response()->json(['status' => 'ignored']);
        }

        $ledger->update([
            'status' => \App\Models\PaymentWebhookEvent::STATUS_PROCESSED,
            'payment_transaction_id' => $transaction->id,
            'processed_at' => now(),
        ]);

        return response()->json(['status' => $transaction->status]);
    }
}