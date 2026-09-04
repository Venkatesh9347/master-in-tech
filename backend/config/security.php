<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Security Header Toggles
    |--------------------------------------------------------------------------
    |
    | hsts_force: When the app is served behind a TLS-terminating reverse proxy
    | (e.g. AWS Lightsail + nginx), the framework may not detect the request as
    | secure (`$request->secure()` is false). Set SECURITY_HSTS_FORCE=true to
    | unconditionally send the Strict-Transport-Security header even when the
    | scheme cannot be auto-detected. Keep false in plain-HTTP local dev.
    |
    */
    'hsts_force' => env('SECURITY_HSTS_FORCE', false),
];
