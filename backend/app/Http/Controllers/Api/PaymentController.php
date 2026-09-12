<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Payment\Exceptions\PaymentNotConfiguredException;
use App\Services\Payment\Exceptions\PaymentVerificationException;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Create a payment order for a course purchase (at-most-once via a
     * server-derived idempotency key).
     *
     * The amount is ALWAYS taken from the course price in the database — the
     * client can never influence what is charged.
     */
    public function createOrder(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
        ]);

        $course = Course::where('id', $validated['course_id'])
            ->where('is_published', true)
            ->first();

        if ($course === null) {
            return response()->json([
                'message' => 'Course not found.',
                'order' => null,
            ], 404);
        }

        $amountPaise = (int) round((float) $course->price * 100);

        $userId = (int) $request->user()->id;

        try {
            $order = $payments->createOrder($amountPaise, [
                // One order per (user, course): re-clicking "Pay" reuses the
                // same order and never charges twice.
                'idempotency_key' => 'mit_course_' . $userId . '_' . $course->id,
                'description' => 'Enrolment fee for ' . $course->title,
                'notes' => [
                    'course_id' => $course->id,
                    'course_title' => $course->title,
                ],
                'user_id' => $userId,
                'course_id' => $course->id,
            ]);

            return response()->json([
                'message' => $order->wasReused() ? 'Order already created; returning existing order.' : 'Order created.',
                'provider' => $order->provider,
                'key_id' => $order->provider === 'razorpay' ? config('services.razorpay.key_id') : null,
                'theme' => config('services.razorpay.theme'),
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                ],
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
     * order/amount/currency consistency (plus the Razorpay payment callback
     * signature when configured), and only then marks it paid. The frontend
     * alone can never declare success.
     */
    public function confirm(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:120'],
            'payment_id' => ['required', 'string', 'max:255'],
            'signature' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $tx = $payments->authoritativeConfirm(
                $validated['order_id'],
                $validated['payment_id'],
                (int) $request->user()->id,
                $validated['signature'] ?? null,
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
                    'course_id' => $tx->course_id,
                ],
            ]);
        } catch (PaymentVerificationException $e) {
            $reason = $e->reasonCode();
            $httpStatus = match ($reason) {
                'order_not_found' => 404,
                'forbidden' => 403,
                default => 422,
            };

            // B3-15: safe structured logging (no signatures/secrets).
            \Illuminate\Support\Facades\Log::warning('payment.confirm.rejected', [
                'reason' => $reason,
                'order_id' => $validated['order_id'] ?? null,
                'payment_id' => $validated['payment_id'] ?? null,
                'user_id' => (int) $request->user()->id,
            ]);

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