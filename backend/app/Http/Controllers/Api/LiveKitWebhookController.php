<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\ClassSessionAttendance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LiveKitWebhookController extends Controller
{
    /**
     * Handle a LiveKit webhook event.
     *
     * LiveKit authenticates webhooks by signing a JWT (HS256) placed in the
     * Authorization header (Bearer token). The JWT header "kid" is the LiveKit
     * API key and the token is signed with the API secret. We verify the token
     * before trusting any event, then map the room name back to a ClassSession
     * and the participant identity back to a user to update attendance.
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Verify the webhook signature (JWT in Authorization: Bearer header).
        if (! $this->verifyWebhookToken($request)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        // 2. Parse the event payload.
        $payload = $request->json()->all();
        $event = $payload['event'] ?? null;
        $room = $payload['room'] ?? null;
        $participant = $payload['participant'] ?? null;

        if (! $event || ! is_array($room) || ! isset($room['name'])) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        $roomName = (string) $room['name'];
        $session = $this->resolveSessionByRoom($roomName);

        if (! $session) {
            // No matching session; acknowledge so LiveKit does not retry forever.
            return response()->json(['message' => 'ok']);
        }

        // 3. React to the event.
        switch ($event) {
            case 'participant_joined':
                $this->handleParticipantJoined($session, $participant);
                break;

            case 'participant_left':
                $this->handleParticipantLeft($session, $participant);
                break;

            case 'room_started':
                $this->handleRoomStarted($session);
                break;

            case 'room_finished':
                $this->handleRoomFinished($session);
                break;

            default:
                // Other events (track_*, recording_*, etc.) are acknowledged.
                break;
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Map a LiveKit room name back to a ClassSession using its internal room id.
     */
    private function resolveSessionByRoom(string $roomName): ?ClassSession
    {
        // Our internal room format: masterintech-session-{session_id}.
        // A parsed id that resolves to nothing must NOT short-circuit the
        // stored-name fallback below (room names are stable; ids are not).
        $prefix = 'masterintech-session-';
        if (Str::startsWith($roomName, $prefix)) {
            $id = (int) Str::after($roomName, $prefix);
            if ($id > 0) {
                $found = ClassSession::find($id);
                if ($found) {
                    return $found;
                }
            }
        }

        // Fallback: match on the stored livekit_room_name column.
        return ClassSession::where('livekit_room_name', $roomName)->first();
    }

    /**
     * Extract user id from a LiveKit participant identity ("user-{id}").
     */
    private function resolveUserByIdentity(string $identity): ?User
    {
        $prefix = 'user-';
        if (Str::startsWith($identity, $prefix)) {
            $id = (int) Str::after($identity, $prefix);
            if ($id > 0) {
                return User::find($id);
            }
        }

        return User::where('email', $identity)->first();
    }

    private function handleParticipantJoined(ClassSession $session, ?array $participant): void
    {
        if (! is_array($participant) || empty($participant['identity'])) {
            return;
        }

        $user = $this->resolveUserByIdentity((string) $participant['identity']);
        if (! $user) {
            return;
        }

        $isHost = $session->isHost($user);

        // Host joining marks the session as active.
        if ($isHost && $session->livekit_status === 'idle') {
            $session->update(['livekit_status' => 'active']);
        }

        // Mark session as live when the first participant joins.
        if ($session->status === 'scheduled' && ! $session->isCancelled()) {
            $session->update(['status' => 'live']);
        }

        if ($isHost) {
            return;
        }

        $attendance = ClassSessionAttendance::where('class_session_id', $session->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $attendance) {
            ClassSessionAttendance::create([
                'class_session_id' => $session->id,
                'user_id' => $user->id,
                'status' => 'present',
                'joined_at' => now(),
                'duration_seconds' => 0,
                'reconnect_count' => 0,
            ]);
        } else {
            $attendance->increment('reconnect_count');
            if ($attendance->left_at) {
                $attendance->update(['left_at' => null]);
            }
        }
    }

    private function handleParticipantLeft(ClassSession $session, ?array $participant): void
    {
        if (! is_array($participant) || empty($participant['identity'])) {
            return;
        }

        $user = $this->resolveUserByIdentity((string) $participant['identity']);
        if (! $user) {
            return;
        }

        $attendance = ClassSessionAttendance::where('class_session_id', $session->id)
            ->where('user_id', $user->id)
            ->first();

        if ($attendance) {
            $now = now();
            $joinedAt = $attendance->joined_at ? \Carbon\Carbon::parse($attendance->joined_at) : null;
            $duration = $joinedAt ? max(0, (int) abs($now->diffInSeconds($joinedAt))) : 0;
            $currentDuration = (int) ($attendance->duration_seconds ?? 0);

            $attendance->update([
                'left_at' => $now,
                'duration_seconds' => max($currentDuration, $duration),
            ]);
        }
    }

    private function handleRoomStarted(ClassSession $session): void
    {
        $session->update([
            'livekit_status' => 'active',
            'status' => 'live',
            'started_at' => $session->started_at ?? now(),
        ]);

        \App\Models\AuditLog::log('livekit_room_started', $session, null, [
            'session_id' => $session->id,
            'room' => $session->livekit_room_name,
        ]);
    }

    private function handleRoomFinished(ClassSession $session): void
    {
        // Finalize any still-open attendance rows and mark the session ended.
        $now = now();

        $session->attendances()
            ->whereNull('left_at')
            ->get()
            ->each(function (ClassSessionAttendance $attendance) use ($now) {
                $joinedAt = $attendance->joined_at ? \Carbon\Carbon::parse($attendance->joined_at) : null;
                $duration = $joinedAt ? max(0, (int) abs($now->diffInSeconds($joinedAt))) : 0;
                $currentDuration = (int) ($attendance->duration_seconds ?? 0);

                $attendance->update([
                    'left_at' => $now,
                    'duration_seconds' => max($currentDuration, $duration),
                ]);
            });

        $session->update([
            'livekit_status' => 'ended',
            'status' => 'completed',
            'ended_at' => $session->ended_at ?? $now,
        ]);

        \App\Models\AuditLog::log('livekit_room_finished', $session, null, [
            'session_id' => $session->id,
            'room' => $session->livekit_room_name,
        ]);
    }

    /**
     * Verify the Bearer JWT signed by LiveKit using the API secret.
     */
    private function verifyWebhookToken(Request $request): bool
    {
        $token = $request->bearerToken();
        if (! $token) {
            return false;
        }

        $apiKey = (string) config('services.livekit.api_key');
        $apiSecret = (string) config('services.livekit.api_secret');

        if ($apiKey === '' || $apiSecret === '') {
            // No credentials configured: fail closed so unsigned webhooks cannot
            // mutate attendance. In tests we set stub credentials explicitly.
            return false;
        }

        // Decode header to confirm kid matches our API key.
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $headerJson = $this->base64UrlDecode($parts[0]);
        if (! $headerJson) {
            return false;
        }

        $header = json_decode($headerJson, true);
        if (! isset($header['kid']) || $header['kid'] !== $apiKey) {
            return false;
        }

        // Recompute the expected signature and compare.
        $signingInput = $parts[0] . '.' . $parts[1];
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $apiSecret, true));

        if (! hash_equals($expected, $parts[2])) {
            return false;
        }

        // Validate the JWT payload claims. Genuine LiveKit webhook tokens
        // carry iss/iat/nbf/exp plus a sha256 body binding (S-02A ground
        // truth). Claims are only trusted after signature verification.
        $payloadJson = $this->base64UrlDecode($parts[1]);
        if ($payloadJson === '') {
            return false;
        }

        $claims = json_decode($payloadJson, true);
        if (! is_array($claims)) {
            return false;
        }

        // exp is required: missing/null/non-numeric values reject. Enforced
        // with ~60s leeway per the LiveKit Go reference behavior.
        if (! isset($claims['exp']) || ! is_numeric($claims['exp'])) {
            return false;
        }
        if (time() > (int) $claims['exp'] + 60) {
            return false;
        }

        // nbf, when asserted, must be numeric and satisfied within the same
        // clock-skew allowance.
        if (array_key_exists('nbf', $claims) && $claims['nbf'] !== null) {
            if (! is_numeric($claims['nbf'])) {
                return false;
            }
            if (time() < (int) $claims['nbf'] - 60) {
                return false;
            }
        }

        // iss must equal the configured API key.
        if (! isset($claims['iss']) || $claims['iss'] !== $apiKey) {
            return false;
        }

        // Body binding: the sha256 claim must equal the base64-encoded
        // SHA-256 digest of the EXACT raw HTTP request body. Never hash
        // parsed/reserialized JSON.
        if (! isset($claims['sha256']) || ! is_string($claims['sha256']) || $claims['sha256'] === '') {
            return false;
        }
        $bodyHash = base64_encode(hash('sha256', $request->getContent(), true));
        if (! hash_equals($claims['sha256'], $bodyHash)) {
            return false;
        }

        return true;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
