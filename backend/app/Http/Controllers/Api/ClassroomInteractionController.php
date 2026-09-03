<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassroomModerationEvent;
use App\Models\ClassroomParticipant;
use App\Models\ClassroomPermissionRequest;
use App\Models\ClassSession;
use App\Models\LiveClassroomSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassroomInteractionController extends Controller
{
    /**
     * Resolve session model (supports ClassSession and LiveClassroomSession).
     */
    protected function resolveSession(int|string $id): array
    {
        $classSession = ClassSession::with(['course', 'tutor', 'currentHost'])->find($id);
        if ($classSession) {
            return [$classSession, 'class_session_id', $classSession->id];
        }

        $liveSession = LiveClassroomSession::with(['course', 'tutor', 'batch', 'currentHost'])->find($id);
        if ($liveSession) {
            return [$liveSession, 'live_classroom_session_id', $liveSession->id];
        }

        $liveByRoom = LiveClassroomSession::with(['course', 'tutor', 'batch', 'currentHost'])
            ->where('room_id', $id)
            ->first();
        if ($liveByRoom) {
            return [$liveByRoom, 'live_classroom_session_id', $liveByRoom->id];
        }

        abort(404, 'Classroom session not found.');
    }

    /**
     * Authorize user access.
     */
    protected function authorizeAccess(User $user, $session): void
    {
        if ($user->role === 'admin' || $user->role === 'super_admin') {
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
     * Authorize host privileges.
     */
    protected function authorizeHost(User $user, $session): void
    {
        if ($user->role === 'admin' || $user->role === 'super_admin') {
            return;
        }

        if (! $session->isHost($user)) {
            abort(403, 'Forbidden: only the active host or administrator can resolve permission requests.');
        }
    }

    /**
     * Student raises hand to request speaking/microphone permission.
     */
    public function raiseHand(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeAccess($user, $session);

        // Check if student already has a pending hand raise
        $existing = ClassroomPermissionRequest::where($foreignKey, $sessionId)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Your hand is already raised. Please wait for the instructor to approve.',
                'request' => $existing,
            ], 400);
        }

        $permRequest = ClassroomPermissionRequest::create([
            $foreignKey => $sessionId,
            'user_id' => $user->id,
            'type' => 'speak',
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        ClassroomParticipant::updateOrCreate(
            [$foreignKey => $sessionId, 'user_id' => $user->id],
            [
                'is_hand_raised' => true,
                'hand_raised_at' => now(),
            ]
        );

        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'action' => 'raise_hand',
            'metadata' => ['request_id' => $permRequest->id],
        ]);

        return response()->json([
            'message' => 'Hand raised successfully. The instructor has been notified.',
            'request' => $permRequest,
        ]);
    }

    /**
     * Student or Host lowers hand.
     */
    public function lowerHand(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeAccess($user, $session);

        $targetUserId = $request->input('target_user_id', $user->id);

        // If lowering someone else's hand, must be host
        if ((int) $targetUserId !== (int) $user->id) {
            $this->authorizeHost($user, $session);
        }

        ClassroomPermissionRequest::where($foreignKey, $sessionId)
            ->where('user_id', $targetUserId)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'resolved_at' => now(),
                'resolved_by' => $user->id,
            ]);

        ClassroomParticipant::where($foreignKey, $sessionId)
            ->where('user_id', $targetUserId)
            ->update([
                'is_hand_raised' => false,
                'hand_raised_at' => null,
            ]);

        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'target_user_id' => $targetUserId,
            'action' => 'lower_hand',
        ]);

        return response()->json([
            'message' => 'Hand lowered successfully.',
        ]);
    }

    /**
     * Host approves or denies speaking request.
     */
    public function resolveRequest(Request $request, int|string $id, int $requestId): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeHost($user, $session);

        $validated = $request->validate([
            'action' => 'required|in:approve,deny',
        ]);

        $permRequest = ClassroomPermissionRequest::where($foreignKey, $sessionId)
            ->where('id', $requestId)
            ->firstOrFail();

        $isApprove = $validated['action'] === 'approve';

        $permRequest->update([
            'status' => $isApprove ? 'approved' : 'denied',
            'resolved_at' => now(),
            'resolved_by' => $user->id,
        ]);

        // If approved, unlock student microphone
        ClassroomParticipant::where($foreignKey, $sessionId)
            ->where('user_id', $permRequest->user_id)
            ->update([
                'is_hand_raised' => false,
                'is_mic_allowed' => $isApprove,
            ]);

        ClassroomModerationEvent::create([
            $foreignKey => $sessionId,
            'actor_id' => $user->id,
            'target_user_id' => $permRequest->user_id,
            'action' => $isApprove ? 'approve_hand_raise' : 'deny_hand_raise',
            'metadata' => [
                'request_id' => $permRequest->id,
                'action' => $validated['action'],
            ],
        ]);

        return response()->json([
            'message' => $isApprove ? 'Permission granted. Student microphone enabled.' : 'Speaking request denied.',
            'request' => $permRequest,
        ]);
    }
}
