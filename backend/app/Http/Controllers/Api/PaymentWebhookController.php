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

        $entity = $event['payload']['payment']['entity'] ?? $event['payload']['order']['entity'] ?? null;

        if ($entity === null || ! is_array($entity)) {
            return response()->json(['status' => 'ignored']);
        }

        // Map provider event name -> canonical status.
        $status = match ($event['event'] ?? '') {
            'payment.captured', 'order.paid' => 'paid',
            'payment.failed', 'order.failed' => 'failed',
            'payment.authorized' => 'authorized',
            default => 'unknown',
        };

        // Unknown events are silently acknowledged (no retry storm).
        if ($status === 'unknown') {
            return response()->json(['status' => 'ignored']);
        }

        $paymentId = (string) ($entity['id'] ?? '');

        if ($paymentId === '') {
            return response()->json(['error' => 'No payment id in event.'], 422);
        }

        $transaction = $payments->processWebhookPayment($payments->providerName(), $entity, $status);

        if ($transaction === null) {
            return response()->json(['status' => 'ignored']);
        }

        return response()->json(['status' => $transaction->status]);
    }
}