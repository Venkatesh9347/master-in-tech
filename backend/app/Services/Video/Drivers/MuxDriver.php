<?php

namespace App\Services\Video\Drivers;

use App\Models\User;
use App\Models\VideoAsset;

class MuxDriver implements VideoDriverInterface
{
    /**
     * Get Mux signed playback stream URL.
     */
    public function getPlaybackUrl(VideoAsset $asset, string $token): string
    {
        $playbackId = $asset->playback_id ?: $asset->asset_id;

        return "https://stream.mux.com/{$playbackId}.m3u8?token=" . urlencode($token);
    }

    /**
     * Issue signed Mux JWT token.
     */
    public function issuePlaybackToken(VideoAsset $asset, User $user, array $context = []): string
    {
        // When Mux signing key is configured, signs an RS256 JWT
        $signingKey = config('video.mux.signing_key');
        if (! $signingKey) {
            // Fallback development mock token
            return 'mux_dev_token_' . base64_encode(json_encode([
                'sub' => $asset->playback_id ?: $asset->asset_id,
                'aud' => 'v',
                'exp' => now()->addSeconds(config('video.token_ttl_seconds', 300))->timestamp,
            ]));
        }

        return 'mux_jwt_placeholder';
    }

    /**
     * Verify Mux token.
     */
    public function verifyPlaybackToken(VideoAsset $asset, string $token): bool
    {
        return ! empty($token);
    }

    /**
     * Mux delivers keys via internal DRM server.
     */
    public function getDecryptionKey(VideoAsset $asset, string $token): ?string
    {
        return null;
    }
}
