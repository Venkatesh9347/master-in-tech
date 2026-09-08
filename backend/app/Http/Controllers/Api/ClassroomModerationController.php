<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassroomMessage;
use App\Models\ClassroomModerationEvent;
use App\Models\ClassroomParticipant;
use App\Models\ClassroomPermissionRequest;
use App\Models\ClassSession;
use App\Models\LiveClassroomSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassroomModerationController extends Controller
{
    /**
     * Resolve session model (supports ClassSession and LiveClassroomSession).
     */
    protected function resolveSession(int|string $id): array
    {
        // Try ClassSession first
        $classSession = ClassSession::with(['course', 'tutor', 'currentHost'])->find($id);
        if ($classSession) {
            return [$classSession, 'class_session_id', $classSession->id];
        }

        // Try LiveClassroomSession
        $liveSession = LiveClassroomSession::with(['course', 'tutor', 'batch', 'currentHost'])->find($id);
        if ($liveSession) {
            return [$liveSession, 'live_classroom_session_id', $liveSession->id];
        }

        // Try by room_id
        $liveByRoom = LiveClassroomSession::with(['course', 'tutor', 'batch', 'currentHost'])
            ->where('room_id', $id)
            ->first();
        if ($liveByRoom) {
            return [$liveByRoom, 'live_classroom_session_id', $liveByRoom->id];
        }

        abort(404, 'Classroom session not found.');
    }

    /**
     * Authorize user access to the session.
     */
    protected function authorizeAccess(User $user, $session): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($session instanceof ClassSession) {
            if ($user->role === 'tutor') {
                if ((int) $session->tutor_id === (int) $user->id || (int) $session->current_host_id === (int) $user->id) {
                    return;
                }
                abort(403, 'Unauthorized: you are not assigned to this class session.');
            }
            if (! $session->canStudentJoin($user)) {
                abort(403, 'Access denied: you must be enrolled in the course and active in the batch.');
            }
        } elseif ($session instanceof LiveClassroomSession) {
            if ($user->role === 'tutor') {
                if ((int) $session->tutor_id === (int) $user->id || (int) $session->current_host_id === (int) $user->id) {
                    return;
                }
                abort(403, 'Unauthorized: you are not assigned to this live session.');
            }
            if (! $session->isStudentEnrolled($user)) {
                abort(403, 'Access denied: you are not enrolled in this batch.');
            }
        }
    }

    /**
     * Authorize host privileges (Admin or Active Host).
     */
    protected function authorizeHost(User $user, $session): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $session->isHost($user)) {
            abort(403, 'Forbidden: only the active host or administrator can perform moderation.');
        }
    }

    /**
     * Get complete live classroom state.
     */
    public function getState(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeAccess($user, $session);

        $isHost = $session->isHost($user);

        // Ensure user is recorded in classroom_participants
        $myParticipant = ClassroomParticipant::firstOrCreate(
            [
                $foreignKey => $sessionId,
                'user_id' => $user->id,
            ],
            [
                'role' => $isHost ? 'host' : 'participant',
                'is_host_active' => $isHost,
                'is_mic_allowed' => $isHost,
                'is_camera_allowed' => $isHost,
                'is_chat_allowed' => true,
                'is_hand_raised' => false,
                'joined_at' => now(),
                'connection_state' => 'connected',
            ]
        );

        // Fetch all active participants
        $participants = ClassroomParticipant::with('user:id,name,email,avatar,role')
            ->where($foreignKey, $sessionId)
            ->whereNull('left_at')
            ->orderBy('is_host_active', 'desc')
            ->orderBy('is_hand_raised', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        // Fetch pending permission requests (Raise Hand)
        $pendingRequests = ClassroomPermissionRequest::with('user:id,name,email,avatar')
            ->where($foreignKey, $sessionId)
            ->where('status', 'pending')
            ->orderBy('requested_at', 'asc')
            ->get();

        // Fetch recent messages
        $messages = ClassroomMessage::with('user:id,name,email,avatar,role')
            ->where($foreignKey, $sessionId)
            ->orderBy('created_at', 'asc')
            ->take(100)
            ->get();

        return response()->json([
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'status' => method_exists($session, 'calculateStatus') ? $session->calculateStatus() : $session->status,
                'current_host_id' => $session->current_host_id,
                'active_host_id' => $session->current_host_id ?: $session->tutor_id,
                'is_chat_enabled' => (bool) $session->is_chat_enabled,
                'tutor' => $session->tutor ? [
                    'id' => $session->tutor->id,
                    'name' => $session->tutor->name,
                    'email' => $session->tutor->email,
                ] : null,
            ],
            'is_host' => $isHost,
            'my_participant' => $myParticipant,
            'participants' => $participants,
            'pending_requests' => $pendingRequests,
            'messages' => $messages,
        ]);
    }

    /**
     * Transfer host privileges from Admin to assigned Tutor (or another instructor).
     */
    public function transferHost(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeHost($user, $session);

        $validated = $request->validate([
            'target_user_id' => 'required|exists:users,id',
        ]);

        $targetUser = User::findOrFail($validated['target_user_id']);

        // Verify target is tutor or admin
        if ($targetUser->role !== 'tutor' && $targetUser->role !== 'admin' && $targetUser->role !== 'super_admin') {
            return response()->json([
                'message' => 'Host privileges can only be transferred to an instructor or administrator.',
            ], 422);
        }

        $session->update([
            'current_host_id' => $targetUser->id,
        ]);

        // Update participant records
        ClassroomParticipant::where($foreignKey, $sessionId)->update(['is_host_active' => false]);
        ClassroomParticipant::updateOrCreate(
            [$foreignKey => $sessionId, 'user_id' => $targetUser->id],
            [
                'role' => 'host',
                'is_host_active' => true,
                'is_mic_allowed' => true,
                'is_camera_allowed' => true,
            ]
        );

        // Log moderation event
        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'target_user_id' => $targetUser->id,
            'action' => 'transfer_host',
            'metadata' => [
                'from_user' => $user->name,
                'to_user' => $targetUser->name,
            ],
        ]);

        return response()->json([
            'message' => "Host privileges successfully transferred to {$targetUser->name}.",
            'current_host_id' => $targetUser->id,
        ]);
    }

    /**
     * Mute / Unmute individual student (Microphone permission toggle).
     */
    public function toggleMic(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeHost($user, $session);

        $validated = $request->validate([
            'target_user_id' => 'required|exists:users,id',
            'is_mic_allowed' => 'required|boolean',
        ]);

        $targetParticipant = ClassroomParticipant::firstOrCreate(
            [$foreignKey => $sessionId, 'user_id' => $validated['target_user_id']],
            [
                'role' => 'participant',
                'is_mic_allowed' => false,
                'is_camera_allowed' => true,
                'is_chat_allowed' => true,
                'duration_seconds' => 0,
                'connection_state' => 'connected',
                'joined_at' => now(),
            ]
        );

        $targetParticipant->update([
            'is_mic_allowed' => $validated['is_mic_allowed'],
        ]);

        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'target_user_id' => $validated['target_user_id'],
            'action' => $validated['is_mic_allowed'] ? 'unmute_student' : 'mute_student',
            'metadata' => ['is_mic_allowed' => $validated['is_mic_allowed']],
        ]);

        return response()->json([
            'message' => $validated['is_mic_allowed'] ? 'Microphone enabled for student.' : 'Student microphone disabled.',
            'participant' => $targetParticipant,
        ]);
    }

    /**
     * Disable / Enable individual student camera.
     */
    public function toggleCamera(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeHost($user, $session);

        $validated = $request->validate([
            'target_user_id' => 'required|exists:users,id',
            'is_camera_allowed' => 'required|boolean',
        ]);

        $targetParticipant = ClassroomParticipant::firstOrCreate(
            [$foreignKey => $sessionId, 'user_id' => $validated['target_user_id']],
            [
                'role' => 'participant',
                'is_mic_allowed' => false,
                'is_camera_allowed' => true,
                'is_chat_allowed' => true,
                'duration_seconds' => 0,
                'connection_state' => 'connected',
                'joined_at' => now(),
            ]
        );

        $targetParticipant->update([
            'is_camera_allowed' => $validated['is_camera_allowed'],
        ]);

        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'target_user_id' => $validated['target_user_id'],
            'action' => $validated['is_camera_allowed'] ? 'enable_camera' : 'disable_camera',
            'metadata' => ['is_camera_allowed' => $validated['is_camera_allowed']],
        ]);

        return response()->json([
            'message' => $validated['is_camera_allowed'] ? 'Camera enabled for student.' : 'Student camera disabled.',
            'participant' => $targetParticipant,
        ]);
    }

    /**
     * Toggle global student chat permission.
     */
    public function toggleChat(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeHost($user, $session);

        $validated = $request->validate([
            'is_chat_enabled' => 'required|boolean',
        ]);

        $session->update([
            'is_chat_enabled' => $validated['is_chat_enabled'],
        ]);

        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'action' => 'toggle_chat',
            'metadata' => ['is_chat_enabled' => $validated['is_chat_enabled']],
        ]);

        return response()->json([
            'message' => $validated['is_chat_enabled'] ? 'Student chat has been enabled.' : 'Student chat has been disabled.',
            'is_chat_enabled' => $session->is_chat_enabled,
        ]);
    }

    /**
     * End classroom session for everyone.
     */
    public function endClassroom(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeHost($user, $session);

        $session->update([
            'status' => 'completed',
            'livekit_status' => 'ended',
            'ended_at' => now(),
        ]);

        // Mark all active participants as left
        ClassroomParticipant::where($foreignKey, $sessionId)
            ->whereNull('left_at')
            ->update([
                'left_at' => now(),
                'connection_state' => 'disconnected',
            ]);

        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'action' => 'end_classroom',
        ]);

        return response()->json([
            'message' => 'Classroom session ended successfully.',
            'status' => 'completed',
        ]);
    }

    /**
     * Remove a participant from the classroom (Host/Tutor/Admin).
     */
    public function removeParticipant(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeHost($user, $session);

        $validated = $request->validate([
            'target_user_id' => 'required|exists:users,id',
        ]);

        $targetUserId = (int) $validated['target_user_id'];

        $targetParticipant = ClassroomParticipant::firstOrCreate(
            [$foreignKey => $sessionId, 'user_id' => $targetUserId],
            [
                'role' => 'participant',
                'is_mic_allowed' => false,
                'is_camera_allowed' => true,
                'is_chat_allowed' => true,
                'duration_seconds' => 0,
                'connection_state' => 'connected',
                'joined_at' => now(),
            ]
        );

        $now = now();
        $joinedAt = $targetParticipant->joined_at ? \Carbon\Carbon::parse($targetParticipant->joined_at) : null;
        $duration = $joinedAt ? (int) max(0, abs($now->diffInSeconds($joinedAt))) : 0;
        $currentDuration = (int) ($targetParticipant->duration_seconds ?? 0);

        $targetParticipant->update([
            'left_at' => $now,
            'duration_seconds' => max($currentDuration, $duration),
            'connection_state' => 'removed',
            'is_mic_allowed' => false,
            'is_camera_allowed' => false,
            'is_chat_allowed' => false,
            'is_hand_raised' => false,
        ]);

        // Also update ClassSessionAttendance if applicable
        if ($foreignKey === 'class_session_id') {
            $attendance = \App\Models\ClassSessionAttendance::where('class_session_id', $sessionId)
                ->where('user_id', $targetUserId)
                ->first();
            if ($attendance) {
                $attendanceJoinedAt = $attendance->joined_at ? \Carbon\Carbon::parse($attendance->joined_at) : null;
                $attendanceDuration = $attendanceJoinedAt ? (int) max(0, abs($now->diffInSeconds($attendanceJoinedAt))) : 0;
                $currentAttDuration = (int) ($attendance->duration_seconds ?? 0);
                $attendance->update([
                    'left_at' => $now,
                    'duration_seconds' => max($currentAttDuration, $attendanceDuration),
                ]);
            }
        }

        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'target_user_id' => $targetUserId,
            'action' => 'remove_participant',
            'metadata' => ['removed_user_id' => $targetUserId],
        ]);

        return response()->json([
            'message' => 'Participant has been removed from the classroom.',
            'participant' => $targetParticipant,
        ]);
    }

    /**
     * Participant leaves classroom.
     */
    public function leaveClassroom(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);

        $participant = ClassroomParticipant::where($foreignKey, $sessionId)
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

        if ($foreignKey === 'class_session_id') {
            $attendance = \App\Models\ClassSessionAttendance::where('class_session_id', $sessionId)
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

        return response()->json([
            'message' => 'Left classroom successfully.',
        ]);
    }
}

