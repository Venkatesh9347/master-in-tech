<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\Exceptions\PaymentNotConfiguredException;
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
}
