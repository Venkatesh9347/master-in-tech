<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchStudent;
use App\Models\LiveClassroomParticipant;
use App\Models\LiveClassroomSession;
use App\Models\User;
use App\Services\LiveKit\LiveKitTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LiveClassroomController extends Controller
{
    /**
     * List live classroom sessions accessible to the authenticated student/tutor/admin.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = LiveClassroomSession::with([
            'batch:id,code,name,course_id,start_date',
            'course:id,title,code,category,thumbnail',
            'tutor:id,name,email,avatar,headline',
        ]);

        if ($user->role === 'student' || empty($user->role)) {
            $activeBatchIds = BatchStudent::where('user_id', $user->id)
                ->where('status', 'active')
                ->pluck('batch_id');

            $query->whereIn('batch_id', $activeBatchIds);
        } elseif ($user->role === 'tutor') {
            $query->where('tutor_id', $user->id);
        }

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $sessions = $query->orderBy('scheduled_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'sessions' => $sessions,
        ]);
    }

    /**
     * Get single live classroom session details with strict authorization.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = LiveClassroomSession::with([
            'batch:id,code,name,course_id,tutor_id,start_date',
            'course:id,title,code,category,thumbnail',
            'tutor:id,name,email,avatar,headline',
            'participants.user:id,name,email,avatar,role',
        ])->findOrFail($id);

        $this->authorizeSessionAccess($user, $session);

        $isHost = $session->isHost($user);
        $myParticipant = $session->participants()->where('user_id', $user->id)->first();

        return response()->json([
            'session' => $session,
            'is_host' => $isHost,
            'my_participant' => $myParticipant,
        ]);
    }

    /**
     * Generate secure server-side LiveKit access token with strict batch/host isolation.
     */
    public function generateToken(Request $request, int $id, LiveKitTokenService $tokenService): JsonResponse
    {
        $user = $request->user();
        $session = LiveClassroomSession::with(['batch', 'course', 'tutor'])->findOrFail($id);

        if ($session->isCancelled()) {
            return response()->json([
                'message' => 'This live classroom session has been cancelled and cannot be joined.',
            ], 400);
        }

        $this->authorizeSessionAccess($user, $session);

        $isHost = $session->isHost($user);
        $token = $tokenService->createTokenForSession($session, $user);

        // Record or refresh participant entry
        $participant = LiveClassroomParticipant::updateOrCreate(
            [
                'live_classroom_session_id' => $session->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $isHost ? 'host' : 'participant',
                'joined_at' => now(),
                'left_at' => null,
                'is_mic_allowed' => $isHost, // Student mic initially disabled
                'is_camera_allowed' => $isHost, // Student camera initially disabled
            ]
        );

        return response()->json([
            'token' => $token,
            'ws_url' => $tokenService->getWsUrl(),
            'room_id' => $session->room_id,
            'session' => [
                'id' => $session->id,
                'room_id' => $session->room_id,
                'title' => $session->title,
                'status' => $session->status,
                'scheduled_date' => $session->scheduled_date->toDateString(),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'batch' => $session->batch ? [
                    'id' => $session->batch->id,
                    'code' => $session->batch->code,
                    'name' => $session->batch->name,
                ] : null,
                'course' => $session->course ? [
                    'id' => $session->course->id,
                    'title' => $session->course->title,
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
            'participant' => $participant,
        ]);
    }

    /**
     * Log student / tutor leaving the live classroom.
     */
    public function leave(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = LiveClassroomSession::findOrFail($id);

        $participant = LiveClassroomParticipant::where('live_classroom_session_id', $session->id)
            ->where('user_id', $user->id)
            ->first();

        if ($participant) {
            $participant->recordLeave();
        }

        return response()->json([
            'message' => 'Left live classroom session successfully.',
        ]);
    }

    /**
     * Tutor / Admin starts the live classroom session.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = LiveClassroomSession::findOrFail($id);

        $this->authorizeHostAction($user, $session);

        $session->update([
            'status' => 'live',
            'started_at' => $session->started_at ?: now(),
        ]);

        return response()->json([
            'message' => 'Live classroom is now LIVE.',
            'session' => $session,
        ]);
    }

    /**
     * Tutor / Admin ends the live classroom session.
     */
    public function end(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = LiveClassroomSession::findOrFail($id);

        $this->authorizeHostAction($user, $session);

        $session->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);

        // Auto-close active participants
        $activeParticipants = $session->participants()->whereNull('left_at')->get();
        foreach ($activeParticipants as $participant) {
            $participant->recordLeave();
        }

        return response()->json([
            'message' => 'Live classroom session completed.',
            'session' => $session,
        ]);
    }

    /**
     * Validate session access based on user role and batch enrollment.
     */
    private function authorizeSessionAccess(User $user, LiveClassroomSession $session): void
    {
        // Admin has universal access
        if ($user->role === 'admin' || $user->role === 'super_admin') {
            return;
        }

        // Assigned tutor has host access
        if ($user->role === 'tutor') {
            if ((int) $session->tutor_id === (int) $user->id) {
                return;
            }
            abort(403, 'Unauthorized: you are not the assigned tutor for this live classroom session.');
        }

        // Student must be an active member of this session's batch
        $isEnrolled = BatchStudent::where('batch_id', $session->batch_id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isEnrolled) {
            abort(403, 'Access denied: you are not an active enrolled student in this batch.');
        }
    }

    /**
     * Validate host action (only assigned tutor or admin).
     */
    private function authorizeHostAction(User $user, LiveClassroomSession $session): void
    {
        if ($user->role === 'admin' || $user->role === 'super_admin') {
            return;
        }

        if ($user->role === 'tutor' && (int) $session->tutor_id === (int) $user->id) {
            return;
        }

        abort(403, 'Unauthorized: only the assigned tutor or administrator can control this session.');
    }
}
