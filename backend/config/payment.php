<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Provider
    |--------------------------------------------------------------------------
    |
    | Supported: 'razorpay' (real SDK), 'stub' (no network, for local + tests)
    |
    */
    'default_provider' => env('PAYMENT_PROVIDER', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Every order creation is keyed by a caller-supplied (or auto-generated)
    | idempotency key stored in `payment_transactions`. Re-submitting the same
    | key returns the already-created order instead of charging twice.
    |
    */
    'idempotency' => [
        'enabled' => env('PAYMENT_IDEMPOTENCY', true),
        'ttl_days' => (int) env('PAYMENT_IDEMPOTENCY_TTL_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('PAYMENT_CURRENCY', 'INR'),

    /*
    |--------------------------------------------------------------------------
    | Refund policy (B3-16, operational — preserved, not automatic)
    |--------------------------------------------------------------------------
    |
    | A provider refund moves the PaymentTransaction to `refunded` (terminal;
    | never resurrected, never downgraded). The student's CourseEnrollment
    | deliberately REMAINS active: revoking classroom access after a refund is
    | an explicit admin decision, not an automatic webhook side effect.
    |
    | Documented revocation procedure for refunded access:
    |  1. Finance confirms the refund in the Razorpay dashboard / ledger.
    |  2. An admin sets the CourseEnrollment to cancelled/dropped via
    |     PUT /api/admin/enrollments/{id} (audited as updated_enrollment).
    |  3. If cohort removal is needed, use the batch remove/discontinue
    |     endpoints (audited batch history is preserved).
    |
    */

];
