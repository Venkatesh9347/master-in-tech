<?php

namespace App\Services\Video\Drivers;

use App\Models\VideoAsset;
use Illuminate\Support\Facades\Storage;

/**
 * S3-compatible encrypted-HLS storage driver.
 *
 * Keeps exactly the same token-authorised playback flow as the local driver
 * (short-lived HMAC token, DB-backed session, AES-128 key exchange) but sources
 * the encryption key and the media segments from an S3-compatible object store
 * (AWS S3, MinIO, DigitalOcean Spaces, etc.) via the `s3` filesystem disk.
 *
 * The `s3` disk is fully env-driven in `config/filesystems.php`:
 *   - S3:      AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_BUCKET / AWS_REGION
 *   - MinIO/self-hosted S3-compatible: set AWS_ENDPOINT + AWS_USE_PATH_STYLE_ENDPOINT=true
 *
 * Drive selection: VIDEO_SECURITY_DRIVER=s3 (default: local_hls).
 */
class S3HlsDriver extends LocalHlsAes128Driver
{
    /**
     * Always target the S3-compatible filesystem disk.
     */
    protected function storageDisk(): string
    {
        return 's3';
    }

    /**
     * Pull the real encrypted segment object from S3. Unlike the local/dev
     * driver we do NOT fabricate bytes for missing objects; a missing object
     * means an unauthorised/unknown segment, so we return null (→ 403).
     */
    public function readSegment(VideoAsset $asset, string $segment, string $token): ?string
    {
        if (! $this->verifyPlaybackToken($asset, $token)) {
            return null;
        }

        // S-04: same allowlist as local driver; never reach S3 object fetch.
        if (! $this->isValidSegmentName($segment)) {
            return null;
        }

        $path = "videos/{$asset->asset_id}/{$segment}";

        $contents = Storage::disk($this->storageDisk())->get($path);

        return is_string($contents) ? $contents : null;
    }
}
