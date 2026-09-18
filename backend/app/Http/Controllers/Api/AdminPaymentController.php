<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentRefund;
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
     * Paginated admin listing of payment transactions (read-only).
     *
     * Safe projection only: internal ids, amounts, statuses, computed
     * refunded/remaining totals, and user/course identity. Never exposes
     * idempotency keys, descriptions, metadata, or provider internals.
     * Filters apply before pagination; ordering is deterministic (id desc).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'provider' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = PaymentTransaction::query()
            ->with(['user:id,name,email', 'course:id,title'])
            ->withSum(
                ['refunds as refunded_amount' => fn ($q) => $q->where('status', PaymentRefund::STATUS_SUCCEEDED)],
                'amount_paise'
            )
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['provider'] ?? null, fn ($q, $provider) => $q->where('provider', $provider))
            ->when($validated['search'] ?? null, function ($q, $term) {
                $like = '%' . $term . '%';
                $q->where(fn ($w) => $w->where('order_id', 'like', $like)
                    ->orWhere('payment_id', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->orderBy('id', 'desc');

        $paginator = $query->paginate($this->perPage($request));

        $paginator->getCollection()->transform(fn (PaymentTransaction $tx) => $this->transactionPayload($tx));

        return response()->json($paginator);
    }

    /**
     * Admin detail for one payment transaction with its refund ledger rows
     * (newest first). Unknown ids resolve to the generic 404 handler.
     */
    public function show(PaymentTransaction $payment): JsonResponse
    {
        $payment->load([
            'user:id,name,email',
            'course:id,title',
            'refunds' => fn ($q) => $q->orderBy('id', 'desc'),
        ]);

        $payload = $this->transactionPayload($payment);
        $payload['refunds'] = $payment->refunds->map->toSafeArray()->all();

        return response()->json(['payment' => $payload]);
    }

    /**
     * Safe transaction projection shared by index/show. Amounts are derived
     * from the ledger sum (single source of truth with the service).
     *
     * @return array<string, mixed>
     */
    private function transactionPayload(PaymentTransaction $transaction): array
    {
        $refunded = (int) ($transaction->getAttribute('refunded_amount') ?? 0);

        if ($transaction->relationLoaded('refunds')) {
            $refunded = $transaction->refunds
                ->where('status', PaymentRefund::STATUS_SUCCEEDED)
                ->sum('amount_paise');
            $refunded = (int) $refunded;
        }

        return [
            'id' => $transaction->id,
            'provider' => $transaction->provider,
            'order_id' => $transaction->order_id,
            'payment_id' => $transaction->payment_id,
            'user' => $transaction->user ? $transaction->user->only(['id', 'name', 'email']) : null,
            'course' => $transaction->course ? $transaction->course->only(['id', 'title']) : null,
            'amount' => (int) $transaction->amount_paise,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'refunded_amount' => $refunded,
            'remaining_refundable' => max(0, (int) $transaction->amount_paise - $refunded),
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }
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
