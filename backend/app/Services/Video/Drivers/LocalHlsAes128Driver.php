<?php

namespace App\Services\Video\Drivers;

use App\Models\User;
use App\Models\VideoAsset;
use App\Models\VideoPlaybackSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalHlsAes128Driver implements VideoDriverInterface
{
    /**
     * Get the master playlist URL for HLS streaming.
     */
    public function getPlaybackUrl(VideoAsset $asset, string $token): string
    {
        return url("/api/video-stream/{$asset->asset_id}/master.m3u8?token=" . urlencode($token));
    }

    /**
     * Issue a signed short-lived playback token.
     */
    public function issuePlaybackToken(VideoAsset $asset, User $user, array $context = []): string
    {
        $ttl = config('video.token_ttl_seconds', 300);
        $expiresAt = now()->addSeconds($ttl);
        $nonce = Str::random(32);

        $payload = [
            'asset_id' => $asset->asset_id,
            'user_id' => $user->id,
            'course_id' => $asset->course_id,
            'lesson_id' => $asset->lesson_id,
            'exp' => $expiresAt->timestamp,
            'nonce' => $nonce,
        ];

        $encodedPayload = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encodedPayload, config('app.key'));
        $rawToken = $encodedPayload . '.' . $signature;

        // Record active playback session in database
        VideoPlaybackSession::create([
            'video_asset_id' => $asset->id,
            'user_id' => $user->id,
            'course_id' => $asset->course_id,
            'lesson_id' => $asset->lesson_id,
            'token_hash' => hash('sha256', $rawToken),
            'ip_address' => $context['ip'] ?? request()->ip(),
            'user_agent' => $context['user_agent'] ?? request()->userAgent(),
            'expires_at' => $expiresAt,
            'last_heartbeat_at' => now(),
        ]);

        return $rawToken;
    }

    /**
     * Verify whether a playback token is valid and not expired.
     */
    public function verifyPlaybackToken(VideoAsset $asset, string $token): bool
    {
        if (! str_contains($token, '.')) {
            return false;
        }

        [$encodedPayload, $signature] = explode('.', $token, 2);

        $expectedSignature = hash_hmac('sha256', $encodedPayload, config('app.key'));
        if (! hash_equals($expectedSignature, $signature)) {
            return false;
        }

        $payload = json_decode(base64_decode($encodedPayload), true);
        if (! is_array($payload)) {
            return false;
        }

        if (($payload['asset_id'] ?? '') !== $asset->asset_id) {
            return false;
        }

        if (($payload['exp'] ?? 0) < now()->timestamp) {
            return false;
        }

        // Verify session exists and is not expired in database
        $session = VideoPlaybackSession::where('video_asset_id', $asset->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        return (bool) $session;
    }

    /**
     * Get or generate the 16-byte binary AES-128 decryption key.
     */
    public function getDecryptionKey(VideoAsset $asset, string $token): ?string
    {
        if (! $this->verifyPlaybackToken($asset, $token)) {
            return null;
        }

        $disk = Storage::disk($this->storageDisk());
        $keyPath = "videos/{$asset->asset_id}/enc.key";

        if ($disk->exists($keyPath)) {
            return $disk->get($keyPath);
        }

        // Generate deterministic 16-byte AES-128 key for local asset if not yet on disk
        $rawKey = substr(hash_hmac('sha256', "mit_aes128_{$asset->asset_id}", config('app.key'), true), 0, 16);
        $disk->put($keyPath, $rawKey);

        if (! $asset->encryption_key_hash) {
            $asset->update(['encryption_key_hash' => hash('sha256', $rawKey)]);
        }

        return $rawKey;
    }

    /**
     * Generate Master HLS Playlist (.m3u8) string.
     */
    public function generateMasterPlaylist(VideoAsset $asset, string $token): string
    {
        $tokenParam = urlencode($token);
        $baseUrl = url("/api/video-stream/{$asset->asset_id}");

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:6',
            '# MasterInTech Adaptive Bitrate Stream (AES-128 Encrypted)',
            '#EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1280x720,FRAME-RATE=30.000,CODECS="avc1.64001f,mp4a.40.2"',
            "{$baseUrl}/720p.m3u8?token={$tokenParam}",
            '#EXT-X-STREAM-INF:BANDWIDTH=1200000,RESOLUTION=854x480,FRAME-RATE=30.000,CODECS="avc1.4d401f,mp4a.40.2"',
            "{$baseUrl}/480p.m3u8?token={$tokenParam}",
            '#EXT-X-STREAM-INF:BANDWIDTH=600000,RESOLUTION=640x360,FRAME-RATE=30.000,CODECS="avc1.42e01e,mp4a.40.2"',
            "{$baseUrl}/360p.m3u8?token={$tokenParam}",
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate Variant Quality Playlist (.m3u8) with AES-128 Key Tag.
     */
    public function generateVariantPlaylist(VideoAsset $asset, string $quality, string $token): string
    {
        $tokenParam = urlencode($token);
        $keyUri = url("/api/video-stream/{$asset->asset_id}/key?token={$tokenParam}");
        $segmentBaseUrl = url("/api/video-stream/{$asset->asset_id}/segments");

        $duration = $asset->duration_seconds ?: 180;
        $segmentDuration = 6;
        $segmentCount = max(1, (int) ceil($duration / $segmentDuration));

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:6',
            "#EXT-X-TARGETDURATION:{$segmentDuration}",
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            "#EXT-X-KEY:METHOD=AES-128,URI=\"{$keyUri}\",IV=0x00000000000000000000000000000001",
        ];

        for ($i = 0; $i < $segmentCount; $i++) {
            $isLast = ($i === $segmentCount - 1);
            $segDur = $isLast ? max(1, $duration - ($i * $segmentDuration)) : $segmentDuration;
            $segName = sprintf("%s_segment_%03d.ts", $quality, $i);
            $lines[] = sprintf("#EXTINF:%0.3f,", $segDur);
            $lines[] = "{$segmentBaseUrl}/{$segName}?token={$tokenParam}";
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The storage disk that backs this HLS asset (key + segment objects).
     *
     * LocalHls reads from the disk configured in `video.storage_disk`; the
     * S3-compatible driver overrides this to always target the S3 disk.
     */
    protected function storageDisk(): string
    {
        return (string) config('video.storage_disk', 'local');
    }

    /**
     * Resolve the FFmpeg binary path for transcode/remux jobs.
     *
     * Environment-driven via FFMPEG_BINARY (see config/video.php). When unset it
     * falls back to a bare `ffmpeg`, letting the OS (Linux /usr/bin/ffmpeg on
     * AWS Lightsail, or Windows binaries on PATH) resolve it. No hardcoded
     * platform-specific paths are used, so local Windows dev keeps working.
     */
    public function ffmpegPath(): string
    {
        $configured = (string) config('video.ffmpeg_binary', '');
        return $configured !== '' ? $configured : 'ffmpeg';
    }

    /**
     * Resolve the FFprobe binary path for probe/media-inspection jobs.
     *
     * Environment-driven via FFPROBE_BINARY; falls back to a bare `ffprobe`.
     */
    public function ffprobePath(): string
    {
        $configured = (string) config('video.ffprobe_binary', '');
        return $configured !== '' ? $configured : 'ffprobe';
    }

    /**
     * Return the raw media segment bytes, or a deterministic dev buffer.
     *
     * On the local driver no real .ts uploads exist, so we emit a fixed-size
     * synthetic encrypted chunk (same content-type as a real MPEG-TS segment)
     * to keep the development stream playable end-to-end.
     */
    public function readSegment(VideoAsset $asset, string $segment, string $token): ?string
    {
        if (! $this->verifyPlaybackToken($asset, $token)) {
            return null;
        }

        $disk = Storage::disk($this->storageDisk());
        $path = "videos/{$asset->asset_id}/{$segment}";

        if ($disk->exists($path)) {
            return $disk->get($path);
        }

        return str_pad("ENC_TS_CHUNK_{$asset->asset_id}_{$segment}_" . hash('sha256', $segment), 188 * 10, "\0");
    }
}
