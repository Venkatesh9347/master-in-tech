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

        $entity = $event['payload']['payment']['entity'] ?? $event['payload']['order']['entity'] ?? null;
        $paymentId = (string) ($entity['id'] ?? $event['id'] ?? '');

        // Map provider event name -> canonical status. Idempotent in the service.
        $status = match ($event['event'] ?? '') {
            'payment.captured', 'order.paid' => 'paid',
            'payment.failed', 'order.failed' => 'failed',
            'payment.authorized' => 'authorized',
            default => 'unknown',
        };

        if ($paymentId === '') {
            return response()->json(['error' => 'No payment id in event.'], 422);
        }

        $transaction = $payments->applyPaymentEvent($paymentId, $status, [
            'event' => $event['event'] ?? null,
            'webhook_received_at' => now()->toISOString(),
        ]);

        if ($transaction === null) {
            // Not yet locally created (e.g. out-of-order webhook). Acknowledge
            // so the provider stops retrying; reconciliation can backfill later.
            return response()->json(['status' => 'ignored']);
        }

        return response()->json(['status' => $transaction->status]);
    }
}
