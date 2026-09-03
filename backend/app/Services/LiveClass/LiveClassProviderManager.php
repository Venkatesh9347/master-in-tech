<?php

namespace App\Services\LiveClass;

use App\Models\LiveClass;
use App\Models\User;

class LiveClassProviderManager
{
    /**
     * Get list of supported video platforms.
     */
    public function getSupportedProviders(): array
    {
        return [
            [
                'id' => 'zoom',
                'name' => 'Zoom Video Communications',
                'icon' => '📹',
                'supports_embedded' => false,
                'supports_passcode' => true,
            ],
            [
                'id' => 'teams',
                'name' => 'Microsoft Teams',
                'icon' => '👥',
                'supports_embedded' => false,
                'supports_passcode' => false,
            ],
            [
                'id' => 'google_meet',
                'name' => 'Google Meet',
                'icon' => '🟢',
                'supports_embedded' => false,
                'supports_passcode' => false,
            ],
            [
                'id' => 'jitsi',
                'name' => 'Jitsi Meet Classroom',
                'icon' => '🌐',
                'supports_embedded' => true,
                'supports_passcode' => true,
            ],
            [
                'id' => 'custom',
                'name' => 'Custom / Universal WebRTC Stream',
                'icon' => '🔗',
                'supports_embedded' => true,
                'supports_passcode' => true,
            ],
        ];
    }

    /**
     * Build meeting metadata and secure launch details for a participant.
     */
    public function buildParticipantPayload(LiveClass $liveClass, User $user): array
    {
        $isHost = $liveClass->isHost($user);
        $provider = $liveClass->provider ?: 'custom';

        // Select host launch URL if user is the assigned instructor or admin
        $targetUrl = ($isHost && $liveClass->host_url) ? $liveClass->host_url : $liveClass->meeting_url;

        // Auto-format Jitsi URL if jitsi provider selected and meeting_id exists
        if ($provider === 'jitsi' && ! $targetUrl && $liveClass->meeting_id) {
            $domain = config('services.jitsi.domain', 'meet.jit.si');
            $room = preg_replace('/[^a-zA-Z0-9_-]/', '', $liveClass->meeting_id);
            $targetUrl = "https://{$domain}/{$room}";
        }

        return [
            'provider' => $provider,
            'meeting_id' => $liveClass->meeting_id,
            'meeting_url' => $targetUrl,
            'passcode' => $liveClass->passcode,
            'is_host' => $isHost,
            'is_chat_enabled' => (bool) $liveClass->is_chat_enabled,
            'is_mic_allowed_by_default' => (bool) $liveClass->is_mic_allowed_by_default,
            'provider_metadata' => $liveClass->provider_metadata ?? [],
        ];
    }

    /**
     * Normalize and validate provider parameters on creation or update.
     */
    public function normalizeProviderAttributes(array $data): array
    {
        $provider = strtolower(trim($data['provider'] ?? 'custom'));
        $meetingUrl = trim($data['meeting_url'] ?? '');
        $hostUrl = trim($data['host_url'] ?? '');
        $meetingId = trim($data['meeting_id'] ?? '');
        $passcode = trim($data['passcode'] ?? '');

        // Auto-extract meeting ID from standard URLs if missing
        if (empty($meetingId)) {
            if ($provider === 'zoom' && preg_match('/\/j\/(\d+)/', $meetingUrl, $matches)) {
                $meetingId = $matches[1];
            } elseif ($provider === 'google_meet' && preg_match('/meet\.google\.com\/([a-z0-9-]+)/i', $meetingUrl, $matches)) {
                $meetingId = $matches[1];
            } elseif ($provider === 'jitsi' && preg_match('/meet\.jit\.si\/([a-zA-Z0-9_-]+)/i', $meetingUrl, $matches)) {
                $meetingId = $matches[1];
            }
        }

        return [
            'provider' => $provider,
            'meeting_id' => $meetingId ?: null,
            'meeting_url' => $meetingUrl ?: null,
            'host_url' => $hostUrl ?: null,
            'passcode' => $passcode ?: null,
            'is_chat_enabled' => isset($data['is_chat_enabled']) ? (bool) $data['is_chat_enabled'] : true,
            'is_mic_allowed_by_default' => isset($data['is_mic_allowed_by_default']) ? (bool) $data['is_mic_allowed_by_default'] : false,
            'provider_metadata' => $data['provider_metadata'] ?? null,
            'settings' => $data['settings'] ?? null,
        ];
    }
}
