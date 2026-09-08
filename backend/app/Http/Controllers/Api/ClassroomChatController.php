<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassroomMessage;
use App\Models\ClassroomParticipant;
use App\Models\ClassSession;
use App\Models\LiveClassroomSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassroomChatController extends Controller
{
    /**
     * Resolve session model.
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
     * List chat messages for the classroom.
     */
    public function getMessages(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeAccess($user, $session);

        $messages = ClassroomMessage::with('user:id,name,email,avatar,role')
            ->where($foreignKey, $sessionId)
            ->orderBy('created_at', 'asc')
            ->take(150)
            ->get();

        return response()->json([
            'is_chat_enabled' => (bool) $session->is_chat_enabled,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a new chat message. Enforces strict server-side chat permission for students.
     */
    public function sendMessage(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        [$session, $foreignKey, $sessionId] = $this->resolveSession($id);
        $this->authorizeAccess($user, $session);

        // Strict Server-Side Permission Check for Students
        if ($user->role === 'student') {
            if (! $session->is_chat_enabled) {
                return response()->json([
                    'message' => 'Chat is currently disabled by the instructor.',
                ], 403);
            }

            $participant = ClassroomParticipant::where($foreignKey, $sessionId)
                ->where('user_id', $user->id)
                ->first();

            if ($participant && ! $participant->is_chat_allowed) {
                return response()->json([
                    'message' => 'Your chat permission has been restricted by the instructor.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $message = ClassroomMessage::create([
            $foreignKey => $sessionId,
            'user_id' => $user->id,
            'message' => trim($validated['message']),
            'is_pinned' => false,
        ]);

        $message->load('user:id,name,email,avatar,role');

        return response()->json([
            'message' => 'Message sent.',
            'chat_message' => $message,
        ], 201);
    }
}

