<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\LiveClassroomSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminLiveClassroomController extends Controller
{
    /**
     * List all live classroom sessions with filtering for admin.
     */
    public function index(Request $request): JsonResponse
    {
        $query = LiveClassroomSession::with([
            'batch:id,code,name,course_id,start_date',
            'course:id,title,code,category,thumbnail',
            'tutor:id,name,email,avatar,role',
            'participants.user:id,name,email',
            'creator:id,name,email',
        ]);

        if ($request->filled('batch_id') && $request->batch_id !== 'all') {
            $query->where('batch_id', $request->batch_id);
        }

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('tutor_id') && $request->tutor_id !== 'all') {
            $query->where('tutor_id', $request->tutor_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('room_id', 'like', "%{$search}%")
                    ->orWhereHas('batch', fn ($bq) => $bq->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                    ->orWhereHas('tutor', fn ($tq) => $tq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $sessions = $query->orderBy('scheduled_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        return response()->json([
            'sessions' => $sessions,
            'total' => $sessions->count(),
        ]);
    }

    /**
     * Store a newly created live classroom session.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_id' => 'required|exists:batches,id',
            'tutor_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'scheduled_date' => 'required|date',
            'start_time' => 'required|string|max:20',
            'end_time' => 'nullable|string|max:20',
            'status' => 'nullable|string|in:scheduled,live,completed,cancelled',
            'settings' => 'nullable|array',
        ]);

        $batch = Batch::findOrFail($validated['batch_id']);

        $session = LiveClassroomSession::create([
            'room_id' => 'mit-room-' . (string) Str::uuid(),
            'batch_id' => $batch->id,
            'course_id' => $batch->course_id,
            'tutor_id' => $validated['tutor_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'scheduled_date' => $validated['scheduled_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'] ?? null,
            'status' => $validated['status'] ?? 'scheduled',
            'settings' => $validated['settings'] ?? [
                'is_mic_allowed_by_default' => false,
                'is_camera_allowed_by_default' => false,
            ],
            'created_by' => $request->user()->id,
        ]);

        $session->load(['batch', 'course', 'tutor']);

        AuditLog::log('created_live_classroom_session', $session, null, $session->toArray());

        return response()->json([
            'message' => 'Live classroom session created successfully.',
            'session' => $session,
        ], 201);
    }

    /**
     * Show a single live classroom session.
     */
    public function show(int $id): JsonResponse
    {
        $session = LiveClassroomSession::with([
            'batch:id,code,name,course_id,start_date',
            'course:id,title,code,category,thumbnail',
            'tutor:id,name,email,avatar,role',
            'participants.user:id,name,email,avatar,role',
            'creator:id,name,email',
        ])->findOrFail($id);

        return response()->json($session);
    }

    /**
     * Update an existing live classroom session.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $session = LiveClassroomSession::findOrFail($id);
        $old = $session->toArray();

        $validated = $request->validate([
            'batch_id' => 'sometimes|required|exists:batches,id',
            'tutor_id' => 'sometimes|required|exists:users,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'scheduled_date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required|string|max:20',
            'end_time' => 'nullable|string|max:20',
            'status' => 'sometimes|required|string|in:scheduled,live,completed,cancelled',
            'settings' => 'nullable|array',
        ]);

        if (isset($validated['batch_id']) && $validated['batch_id'] != $session->batch_id) {
            $batch = Batch::findOrFail($validated['batch_id']);
            $validated['course_id'] = $batch->course_id;
        }

        $session->update($validated);
        $session->load(['batch', 'course', 'tutor']);

        AuditLog::log('updated_live_classroom_session', $session, $old, $session->toArray());

        return response()->json([
            'message' => 'Live classroom session updated successfully.',
            'session' => $session,
        ]);
    }

    /**
     * Delete a live classroom session.
     */
    public function destroy(int $id): JsonResponse
    {
        $session = LiveClassroomSession::findOrFail($id);
        $old = $session->toArray();
        $session->delete();

        AuditLog::log('deleted_live_classroom_session', null, $old, null);

        return response()->json([
            'message' => 'Live classroom session deleted successfully.',
        ]);
    }
}
