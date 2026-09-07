<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\Exceptions\PaymentNotConfiguredException;
use App\Services\Payment\Exceptions\PaymentVerificationException;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Create a payment order (at-most-once via idempotency key).
     */
    public function createOrder(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $amountPaise = (int) round($validated['amount'] * 100);

        try {
            $order = $payments->createOrder($amountPaise, [
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'description' => $validated['description'] ?? null,
                'currency' => $validated['currency'] ?? null,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => $order->wasReused() ? 'Order already created; returning existing order.' : 'Order created.',
                'order' => $order->toArray(),
            ], 201);
        } catch (PaymentNotConfiguredException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'order' => null,
            ], 503);
        }
    }

    /**
     * Authoritatively confirm a payment via server-side provider verification.
     *
     * The server fetches the payment state from the provider, verifies
     * order/amount/currency consistency, and only then marks it paid.
     * The frontend alone can never declare success.
     */
    public function confirm(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:120'],
            'payment_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            $tx = $payments->authoritativeConfirm(
                $validated['order_id'],
                $validated['payment_id'],
                (int) $request->user()->id,
            );

            return response()->json([
                'message' => 'Payment confirmed.',
                'order' => [
                    'order_id' => $tx->order_id,
                    'payment_id' => $tx->payment_id,
                    'amount' => $tx->amount_paise,
                    'currency' => $tx->currency,
                    'status' => $tx->status,
                    'paid_at' => $tx->paid_at?->toISOString(),
                ],
            ]);
        } catch (PaymentVerificationException $e) {
            $reason = $e->reasonCode();
            $httpStatus = match ($reason) {
                'order_not_found' => 404,
                'forbidden' => 403,
                default => 422,
            };

            return response()->json([
                'message' => 'Payment could not be confirmed.',
                'error' => $reason,
                'order_id' => $e->orderId(),
            ], $httpStatus);
        } catch (PaymentNotConfiguredException $e) {
            return response()->json([
                'message' => 'Payment provider is not configured.',
                'error' => 'payment_not_configured',
            ], 503);
        }
    }
}