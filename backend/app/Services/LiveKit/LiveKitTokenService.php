<?php

namespace App\Services\LiveKit;

use App\Models\LiveClassroomSession;
use App\Models\User;
use Illuminate\Support\Str;

class LiveKitTokenService
{
    protected string $apiKey;
    protected string $apiSecret;
    protected string $wsUrl;
    protected int $defaultTtl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.livekit.api_key');
        $this->apiSecret = (string) config('services.livekit.api_secret');
        $this->wsUrl = (string) config('services.livekit.url');
        $this->defaultTtl = (int) config('services.livekit.token_ttl', 7200);
    }

    /**
     * Whether LiveKit credentials are configured. Tokens must never be minted
     * with an empty secret (fail-open forgery); callers return 503 instead.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '' && $this->wsUrl !== '';
    }

    /**
     * Get configured LiveKit WebSocket URL.
     */
    public function getWsUrl(): string
    {
        return $this->wsUrl;
    }

    /**
     * Get configured LiveKit API Key.
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Generate secure, signed LiveKit JWT AccessToken for a user in a live session.
     *
     * @param  LiveClassroomSession  $session
     * @param  User  $user
     * @param  array  $overrides  Optional grant overrides (e.g. mic permission in later phases)
     * @return string Signed JWT Token
     */
    public function createTokenForSession(LiveClassroomSession $session, User $user, array $overrides = []): string
    {
        $isHost = $session->isHost($user);
        $ttl = $this->defaultTtl;
        $now = time();

        // Phase 1 Rules:
        // - Host (Tutor/Admin): canPublish = true, roomAdmin = true, roomCreate = true
        // - Student (Participant): canPublish = false (camera & mic disabled initially), canSubscribe = true
        $canPublish = $isHost ? true : (bool) ($overrides['can_publish'] ?? false);
        $roomAdmin = $isHost ? true : false;
        $roomCreate = $isHost ? true : false;

        $videoGrants = [
            'room' => $session->room_id,
            'roomJoin' => true,
            'canPublish' => $canPublish,
            'canSubscribe' => true,
            'canPublishData' => true,
            'roomAdmin' => $roomAdmin,
            'roomCreate' => $roomCreate,
            'roomList' => false,
            'roomRecord' => false,
            'hidden' => false,
        ];

        $metadata = [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $isHost ? 'host' : 'participant',
            'user_role' => $user->role ?? 'student',
            'batch_id' => $session->batch_id,
            'batch_code' => $session->batch?->code,
            'session_id' => $session->id,
            'avatar' => $user->avatar,
            'is_mic_allowed' => $canPublish,
            'is_camera_allowed' => $canPublish,
        ];

        $payload = [
            'iss' => $this->apiKey,
            'sub' => "user-{$user->id}",
            'jti' => (string) Str::uuid(),
            'name' => $user->name,
            'nbf' => $now - 5,
            'exp' => $now + $ttl,
            'video' => $videoGrants,
            'metadata' => json_encode($metadata),
        ];

        return $this->signJwt($payload, $this->apiSecret);
    }

    /**
     * Generate secure, signed LiveKit JWT AccessToken for an existing ClassSession.
     * Room format: masterintech-session-{session_id}
     */
    public function createTokenForClassSession(\App\Models\ClassSession $session, User $user, array $overrides = []): string
    {
        $isHost = $session->isHost($user);
        $roomName = $session->resolveLivekitRoomName();
        $ttl = $this->defaultTtl;
        $now = time();

        $canPublish = $isHost ? true : (bool) ($overrides['can_publish'] ?? false);
        $roomAdmin = $isHost ? true : false;
        $roomCreate = $isHost ? true : false;

        $videoGrants = [
            'room' => $roomName,
            'roomJoin' => true,
            'canPublish' => $canPublish,
            'canSubscribe' => true,
            'canPublishData' => true,
            'roomAdmin' => $roomAdmin,
            'roomCreate' => $roomCreate,
            'roomList' => false,
            'roomRecord' => false,
            'hidden' => false,
        ];

        $metadata = [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $isHost ? 'host' : 'participant',
            'user_role' => $user->role ?? 'student',
            'course_id' => $session->course_id,
            'class_session_id' => $session->id,
            'room_name' => $roomName,
            'avatar' => $user->avatar,
            'is_mic_allowed' => $canPublish,
            'is_camera_allowed' => $canPublish,
        ];

        $payload = [
            'iss' => $this->apiKey,
            'sub' => "user-{$user->id}",
            'jti' => (string) Str::uuid(),
            'name' => $user->name,
            'nbf' => $now - 5,
            'exp' => $now + $ttl,
            'video' => $videoGrants,
            'metadata' => json_encode($metadata),
        ];

        return $this->signJwt($payload, $this->apiSecret);
    }

    /**
     * Encode header and payload, then sign with HMAC-SHA256.
     */
    public function signJwt(array $payload, string $secret): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $segments = [];
        $segments[] = $this->base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $segments[] = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Decode and inspect a LiveKit JWT token for testing/verification without exposing secrets.
     */
    public static function decodeJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payloadJson = static::base64UrlDecode($parts[1]);
        if (! $payloadJson) {
            return null;
        }

        return json_decode($payloadJson, true);
    }

    /**
     * Helper for URL-safe base64 encoding.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Helper for URL-safe base64 decoding.
     */
    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
