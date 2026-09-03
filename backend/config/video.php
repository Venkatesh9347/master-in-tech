<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Video Security Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: 'local_hls', 'mux', 'cloudflare', 's3'
    |
    */
    'default_driver' => env('VIDEO_SECURITY_DRIVER', 'local_hls'),

    /*
    |--------------------------------------------------------------------------
    | Playback Session Token Lifetime (Seconds)
    |--------------------------------------------------------------------------
    |
    | Tokens authorize HLS playlist and segment decryption key exchanges.
    | Short-lived TTL prevents permanent token sharing and URL scraping.
    |
    */
    'token_ttl_seconds' => env('VIDEO_TOKEN_TTL', 300), // 5 minutes default

    /*
    |--------------------------------------------------------------------------
    | Private Storage Disk
    |--------------------------------------------------------------------------
    |
    | Disk used for private HLS encrypted segments (.ts) and encryption keys.
    |
    */
    'storage_disk' => env('VIDEO_STORAGE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Mux / Commercial DRM Provider Configuration (Future Compatibility)
    |--------------------------------------------------------------------------
    */
    'mux' => [
        'token_id' => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
        'signing_key' => env('MUX_SIGNING_KEY'),
        'private_key' => env('MUX_PRIVATE_KEY'),
    ],
];
