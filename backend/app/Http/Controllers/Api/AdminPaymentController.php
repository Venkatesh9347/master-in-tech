<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Services\Payment\Exceptions\PaymentNotConfiguredException;
use App\Services\Payment\Exceptions\PaymentRefundException;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminPaymentController extends Controller
{
    /**
     * Administrator-initiated outbound refund (P1-A).
     *
     * POST /api/admin/payments/{payment}/refund
     *
     * Admin-only (route middleware). The amount is optional: omitted means a
     * full refund of the remaining refundable balance. Enrollment access is
     * never revoked here — that stays an explicit admin decision.
     */
    public function refund(Request $request, PaymentTransaction $payment, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $key = $validated['idempotency_key']
            ?? $request->header('Idempotency-Key');

        try {
            $refund = $payments->initiateRefund(
                $payment,
                $validated['amount'] ?? null,
                (int) $request->user()->id,
                [
                    'idempotency_key' => is_string($key) && $key !== '' ? $key : null,
                    'reason' => $validated['reason'] ?? null,
                ]
            );

            $payment->refresh();

            return response()->json([
                'message' => 'Refund initiated.',
                'refund' => $refund->toSafeArray(),
                'payment' => [
                    'id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'payment_id' => $payment->payment_id,
                    'amount' => (int) $payment->amount_paise,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'refunded_amount' => $payments->refundedAmountFor($payment),
                    'remaining_refundable' => $payments->remainingRefundableFor($payment),
                ],
            ], 201);
        } catch (PaymentRefundException $e) {
            $reason = $e->reasonCode();
            $httpStatus = match ($reason) {
                'idempotency_key_conflict' => 409,
                'provider_failed' => 502,
                'provider_not_configured' => 503,
                default => 422,
            };

            // Safe structured logging (ids + reason only: no secrets, no
            // gateway payloads, no customer PII).
            Log::warning('payment.refund.request_rejected', [
                'reason' => $reason,
                'payment_transaction_id' => $payment->id,
                'admin_id' => (int) $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Refund could not be processed.',
                'error' => $reason,
            ], $httpStatus);
        } catch (PaymentNotConfiguredException $e) {
            return response()->json([
                'message' => 'Payment provider is not configured.',
                'error' => 'provider_not_configured',
            ], 503);
        }
    }
}
