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
}
