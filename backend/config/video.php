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
    | FFmpeg / FFprobe Binary Paths
    |--------------------------------------------------------------------------
    |
    | Used by video upload/transcode pipeline jobs. Values are environment driven
    | so Linux/AWS Lightsail can point at the system binaries (e.g. /usr/bin/ffmpeg)
    | while local Windows development relies on the binaries being on PATH (`ffmpeg`,
    | `ffprobe`). No hardcoded OS-specific paths are used.
    |
    | If empty, the platform's default lookup (command on PATH) is used.
    |
    */
    'ffmpeg_binary' => env('FFMPEG_BINARY', ''),
    'ffprobe_binary' => env('FFPROBE_BINARY', ''),

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

    /*
    |--------------------------------------------------------------------------
    | S3-compatible Object Storage (AES-128 encrypted HLS)
    |--------------------------------------------------------------------------
    |
    | Used when VIDEO_SECURITY_DRIVER=s3. Credentials come from the `s3`
    | filesystem disk in config/filesystems.php (AWS_* env). For self-hosted
    | S3-compatible stores (MinIO etc.) also set AWS_ENDPOINT and
    | AWS_USE_PATH_STYLE_ENDPOINT=true.
    |
    */
    's3' => [
        'bucket' => env('AWS_BUCKET'),
        'prefix' => env('VIDEO_S3_PREFIX', 'videos'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    ],
];
