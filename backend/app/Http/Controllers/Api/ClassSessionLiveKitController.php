<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\ClassSessionAttendance;
use App\Models\User;
use App\Services\LiveKit\LiveKitTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassSessionLiveKitController extends Controller
{
    /**
     * Generate secure LiveKit access token for an existing ClassSession.
     * Enforces strict single active session, course enrollment, batch membership, and tutor assignment.
     */
    public function generateToken(Request $request, int $id, LiveKitTokenService $tokenService): JsonResponse
    {
        $user = $request->user();

        if ($user->status && $user->status !== 'active') {
            return response()->json([
                'message' => 'Account is inactive. Please contact support.',
            ], 403);
        }

        $session = ClassSession::with(['course', 'tutor'])->findOrFail($id);

$this->authorizeSessionAccess($user, $session);

        $isHost = $session->isHost($user);

        if ($session->isCancelled()) {
            return response()->json([
                'message' => 'This class session has been cancelled and cannot be joined.',
            ], 400);
        }

        // Students cannot join expired or concluded sessions
        if ($user->role === 'student' && $session->isExpired()) {
            return response()->json([
                'message' => 'This class session has expired or concluded.',
            ], 400);
        }

        // Early-join gate: participants cannot enter before the session starts;
        // hosts/admins may prepare the room ahead of time.
        if (! $isHost && $session->isNotYetStarted()) {
            return response()->json([
                'message' => 'This class session has not started yet. Please join once the session is live.',
            ], 403);
        }

        if (! $tokenService->isConfigured()) {
            return response()->json([
                'message' => 'Live classroom is not configured. Please contact support.',
            ], 503);
        }

        $token = $tokenService->createTokenForClassSession($session, $user);
        $roomName = $session->resolveLivekitRoomName();

        // If host joins, mark livekit status as active
        if ($isHost && $session->livekit_status === 'idle') {
            $session->update(['livekit_status' => 'active']);
        }

        // Record student attendance & track reconnects
        if (! $isHost) {
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

        return response()->json([
            'token' => $token,
            'ws_url' => $tokenService->getWsUrl(),
            'room_name' => $roomName,
            'room_id' => $roomName,
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'description' => $session->description,
                'platform' => 'livekit',
                'status' => $session->calculateStatus(),
                'livekit_status' => $session->livekit_status,
                'scheduled_date' => is_string($session->scheduled_date) ? $session->scheduled_date : $session->scheduled_date?->toDateString(),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'course' => $session->course ? [
                    'id' => $session->course->id,
                    'title' => $session->course->title,
                    'code' => $session->course->code,
                ] : null,
                'tutor' => $session->tutor ? [
                    'id' => $session->tutor->id,
                    'name' => $session->tutor->name,
                ] : null,
            ],
            'is_host' => $isHost,
            'role' => $isHost ? 'host' : 'participant',
            'can_publish' => $isHost,
            'is_mic_allowed' => $isHost,
            'is_camera_allowed' => $isHost,
        ]);
    }

    /**
     * Update LiveKit session status (active, ended).
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = ClassSession::findOrFail($id);

        if (! $session->isHost($user)) {
            return response()->json([
                'message' => 'Unauthorized: only the assigned tutor or administrator can update session status.',
            ], 403);
        }

        $validated = $request->validate([
            'livekit_status' => 'required|string|in:idle,active,ended',
        ]);

        $session->update([
            'livekit_status' => $validated['livekit_status'],
        ]);

        return response()->json([
            'message' => 'LiveKit status updated successfully.',
            'livekit_status' => $session->livekit_status,
        ]);
    }

    /**
     * Record participant leaving the class session and update attendance duration.
     */
    public function leaveSession(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = ClassSession::findOrFail($id);

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

        $participant = \App\Models\ClassroomParticipant::where('class_session_id', $session->id)
            ->where('user_id', $user->id)
            ->first();

        if ($participant) {
            $now = now();
            $joinedAt = $participant->joined_at ? \Carbon\Carbon::parse($participant->joined_at) : null;
            $duration = $joinedAt ? max(0, (int) abs($now->diffInSeconds($joinedAt))) : 0;
            $currentDuration = (int) ($participant->duration_seconds ?? 0);
            $participant->update([
                'left_at' => $now,
                'duration_seconds' => max($currentDuration, $duration),
                'connection_state' => 'disconnected',
            ]);
        }

        return response()->json([
            'message' => 'Left class session successfully.',
        ]);
    }

    /**
     * Verify user authorization against class session, batch, and course.
     */
    private function authorizeSessionAccess(User $user, ClassSession $session): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->role === 'tutor') {
            if ((int) $session->tutor_id === (int) $user->id) {
                return;
            }
            abort(403, 'Unauthorized: you are not the assigned tutor for this class session.');
        }

        // Student verification: Must be enrolled in course and active in batch
        if (! $session->canStudentJoin($user)) {
            abort(403, 'Access denied: you must be enrolled in the course and assigned to an active batch to join this session.');
        }
    }
}

