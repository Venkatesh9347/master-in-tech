<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Services\Payment\Exceptions\PaymentNotConfiguredException;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Create a payment order for purchasing a course (at-most-once via
     * idempotency key). The amount is derived server-side from the course
     * price — any client-supplied amount is ignored.
     */
    public function createOrder(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $course = Course::findOrFail($validated['course_id']);
        if (! $course->is_published) {
            return response()->json([
                'message' => 'This course is not available for purchase.',
                'order' => null,
            ], 422);
        }

        $user = $request->user();
        $alreadyEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();
        if ($alreadyEnrolled) {
            return response()->json([
                'message' => 'You are already enrolled in this course.',
                'order' => null,
            ], 409);
        }

        try {
            $order = $payments->createCourseOrder($user, $course, [
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'description' => $validated['description'] ?? "Enrollment: {$course->title}",
                'currency' => $validated['currency'] ?? null,
            ]);

            return response()->json([
                'message' => $order->wasReused() ? 'Order already created; returning existing order.' : 'Order created.',
                'order' => $order->toArray(),
                // Public key for Razorpay checkout.js (public by design).
                // Null in stub/unconfigured mode: the client must not render
                // a payment button without it.
                'key_id' => $order->provider === 'razorpay'
                    ? (string) config('services.razorpay.key_id')
                    : null,
            ], 201);
        } catch (PaymentNotConfiguredException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'order' => null,
            ], 503);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'order' => null,
            ], 422);
        }
    }
}
