<?php

namespace App\Services\Video\Drivers;

use App\Models\User;
use App\Models\VideoAsset;

interface VideoDriverInterface
{
    /**
     * Get the master playlist or stream playback URL for this asset.
     */
    public function getPlaybackUrl(VideoAsset $asset, string $token): string;

    /**
     * Issue a signed short-lived playback token for this asset and user.
     */
    public function issuePlaybackToken(VideoAsset $asset, User $user, array $context = []): string;

    /**
     * Verify whether a given playback token is valid and active for this asset.
     */
    public function verifyPlaybackToken(VideoAsset $asset, string $token): bool;

    /**
     * Get the binary AES-128 decryption key for authorized segment playback.
     */
    public function getDecryptionKey(VideoAsset $asset, string $token): ?string;

    /**
     * Generate the master adaptive HLS playlist (.m3u8) as a string.
     */
    public function generateMasterPlaylist(VideoAsset $asset, string $token): string;

    /**
     * Generate a single-quality variant HLS playlist (.m3u8) with the AES-128 key tag.
     */
    public function generateVariantPlaylist(VideoAsset $asset, string $quality, string $token): string;

    /**
     * Return the raw binary bytes of an HLS media segment (.ts) for an
     * authorized token, or null when unavailable/unauthorized.
     */
    public function readSegment(VideoAsset $asset, string $segment, string $token): ?string;

    /**
     * Resolve the FFmpeg binary path for transcode/remux jobs. Environment
     * driven via FFMPEG_BINARY; falls back to "ffmpeg" on PATH.
     */
    public function ffmpegPath(): string;

    /**
     * Resolve the FFprobe binary path for probe/media-inspection jobs.
     * Environment driven via FFPROBE_BINARY; falls back to "ffprobe" on PATH.
     */
    public function ffprobePath(): string;
}
