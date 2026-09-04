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

];
