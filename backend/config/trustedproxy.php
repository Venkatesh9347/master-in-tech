<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of proxy IP addresses (or CIDR ranges) that are
    | allowed to forward X-Forwarded-* headers. This is REQUIRED only when the
    | application sits behind a TLS-terminating reverse proxy / load balancer
    | (see DEPLOYMENT.md "TLS / Reverse Proxy").
    |
    | Examples:
    |   TRUSTED_PROXIES=10.0.0.10                 (single load balancer)
    |   TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12  (private ranges)
    |   TRUSTED_PROXIES=REMOTE_ADDR                (the immediate peer only)
    |
    | Security implications: trusting too much (e.g. '*' — every client) lets
    | callers forge X-Forwarded-For / X-Forwarded-Proto, defeating rate-limit
    | keys, HSTS detection and secure-cookie decisions. Leave empty to trust
    | NO proxies (the safe default): forwarded headers are then ignored and
    | the app behaves as if the peer were the client.
    |
    */
    'proxies' => env('TRUSTED_PROXIES'),

];