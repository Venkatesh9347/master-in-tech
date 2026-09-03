<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TutorClassSessionController extends Controller
{
    /**
     * Get all class sessions assigned to the logged-in tutor.
     */
    /**
     * Get all class sessions assigned to the authenticated tutor.
     */
    public function index(Request $request): JsonResponse
    {
        ClassSession::syncRealtimeStatuses();

        $user = $request->user();

        $sessions = ClassSession::where('tutor_id', $user->id)
            ->select([
                'id', 'course_id', 'tutor_id', 'quiz_id', 'title', 'description',
                'platform', 'meeting_url', 'meeting_id', 'meeting_password',
                'scheduled_date', 'start_time', 'end_time', 'status', 'expired_at', 'ended_at', 'recording_url', 'created_at',
            ])
            ->with([
                'course:id,title,category,thumbnail',
                'materials:id,class_session_id,course_id,title,file_path,file_name,file_type,file_size',
                'attendances.user:id,name,email',
            ])
            ->orderBy('scheduled_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        return response()->json($this->enrichTutorSessions($sessions, $user));
    }

    /**
     * Get today's class sessions assigned to tutor.
     * LIVE sessions appear first, followed by scheduled sessions.
     */
    public function today(Request $request): JsonResponse
    {
        ClassSession::syncRealtimeStatuses();

        $user = $request->user();
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        $sessions = ClassSession::where('tutor_id', $user->id)
            ->where('scheduled_date', $today)
            ->whereIn('status', ['scheduled', 'live'])
            ->where('end_time', '>', $currentTime)
            ->select([
                'id', 'course_id', 'tutor_id', 'quiz_id', 'title', 'description',
                'platform', 'meeting_url', 'meeting_id', 'meeting_password',
                'scheduled_date', 'start_time', 'end_time', 'status', 'expired_at', 'ended_at', 'recording_url', 'created_at',
            ])
            ->with([
                'course:id,title,category,thumbnail',
                'materials:id,class_session_id,course_id,title,file_path,file_name,file_type,file_size',
            ])
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC")
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json($this->enrichTutorSessions($sessions, $user));
    }

    /**
     * Get upcoming class sessions assigned to tutor.
     * LIVE sessions appear first, followed by upcoming sessions.
     */
    public function upcoming(Request $request): JsonResponse
    {
        ClassSession::syncRealtimeStatuses();

        $user = $request->user();
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        $sessions = ClassSession::where('tutor_id', $user->id)
            ->whereIn('status', ['scheduled', 'live'])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('scheduled_date', '>', $today)
                    ->orWhere(function ($q2) use ($today, $currentTime) {
                        $q2->where('scheduled_date', '=', $today)
                            ->where('end_time', '>', $currentTime);
                    });
            })
            ->select([
                'id', 'course_id', 'tutor_id', 'quiz_id', 'title', 'description',
                'platform', 'meeting_url', 'meeting_id', 'meeting_password',
                'scheduled_date', 'start_time', 'end_time', 'status', 'expired_at', 'ended_at', 'recording_url', 'created_at',
            ])
            ->with([
                'course:id,title,category,thumbnail',
                'materials:id,class_session_id,course_id,title,file_path,file_name,file_type,file_size',
            ])
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC")
            ->orderBy('scheduled_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json($this->enrichTutorSessions($sessions, $user));
    }

    /**
     * Get previous class sessions assigned to tutor.
     */
    public function previous(Request $request): JsonResponse
    {
        ClassSession::syncRealtimeStatuses();

        $user = $request->user();
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        $sessions = ClassSession::where('tutor_id', $user->id)
            ->where(function ($q) use ($today, $currentTime) {
                $q->whereIn('status', ['completed', 'expired'])
                    ->orWhere(function ($q2) use ($today) {
                        $q2->where('scheduled_date', '<', $today)
                            ->where('status', '!=', 'cancelled');
                    })
                    ->orWhere(function ($q3) use ($today, $currentTime) {
                        $q3->where('scheduled_date', '=', $today)
                            ->where('end_time', '<=', $currentTime)
                            ->where('status', '!=', 'cancelled');
                    });
            })
            ->select([
                'id', 'course_id', 'tutor_id', 'quiz_id', 'title', 'description',
                'platform', 'meeting_url', 'meeting_id', 'meeting_password',
                'scheduled_date', 'start_time', 'end_time', 'status', 'expired_at', 'ended_at', 'recording_url', 'created_at',
            ])
            ->with([
                'course:id,title,category,thumbnail',
                'materials:id,class_session_id,course_id,title,file_path,file_name,file_type,file_size',
                'attendances.user:id,name,email',
            ])
            ->orderBy('scheduled_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        return response()->json($this->enrichTutorSessions($sessions, $user));
    }

    /**
     * Get single class session details with strict tutor assignment check.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        ClassSession::syncRealtimeStatuses();

        $user = $request->user();
        $session = ClassSession::with([
            'course:id,title,category,thumbnail,slug,description',
            'tutor:id,name,email,avatar,headline',
            'materials.uploader:id,name,email',
            'attendances.user:id,name,email',
        ])->findOrFail($id);

        if ($session->tutor_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. This class session is not assigned to you.',
            ], 403);
        }

        $enriched = $this->enrichTutorSessions(collect([$session]), $user);

        return response()->json($enriched[0] ?? $session);
    }

    /**
     * Helper to enrich tutor session collection with batch metadata and real-time status.
     */
    private function enrichTutorSessions($sessions, User $user)
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $courseIds = $sessions->pluck('course_id')->unique()->filter()->values();

        $tutorBatches = Batch::where('tutor_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->orderBy('start_date', 'desc')
            ->get()
            ->groupBy('course_id');

        $courseBatches = Batch::whereIn('course_id', $courseIds)
            ->orderBy('start_date', 'desc')
            ->get()
            ->groupBy('course_id');

        return $sessions->map(function (ClassSession $session) use ($user, $tutorBatches, $courseBatches) {
            $tBatch = $tutorBatches->get($session->course_id)?->first();
            if (! $tBatch) {
                $tBatch = $courseBatches->get($session->course_id)?->first();
            }

            $batchCode = $tBatch?->code;
            if (! $batchCode) {
                $batchCode = $session->course
                    ? Batch::generateBatchCode($session->course, $session->scheduled_date ?? now(), false)
                    : 'RIT(TECH)BC'.now()->format('dmy');
            }

            $calculatedStatus = $session->calculateStatus();

            $arr = $session->toArray();
            $arr['batch_code'] = $batchCode;
            $arr['batch_number'] = $batchCode;
            $arr['status'] = $calculatedStatus;
            $arr['is_live'] = ($calculatedStatus === 'live');
            $arr['is_scheduled'] = ($calculatedStatus === 'scheduled');
            $arr['is_expired'] = ($calculatedStatus === 'expired');
            $arr['batch'] = $tBatch ? [
                'id' => $tBatch->id,
                'code' => $tBatch->code,
                'name' => $tBatch->name,
                'start_date' => $tBatch->start_date ? $tBatch->start_date->toDateString() : (string) $tBatch->start_date,
            ] : null;

            return $arr;
        });
    }

    /**
     * Tutor join session.
     */
    public function join(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = ClassSession::findOrFail($id);

        if ($session->tutor_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. You are not the assigned tutor for this class session.',
            ], 403);
        }

        if ($session->status === 'cancelled') {
            return response()->json([
                'message' => 'This class has been cancelled and cannot be joined.',
            ], 400);
        }

        if (empty($session->meeting_url)) {
            return response()->json([
                'message' => 'No meeting URL has been configured for this class session.',
            ], 400);
        }

        return response()->json([
            'message' => 'Proceeding to host live classroom.',
            'meeting_url' => $session->meeting_url,
            'platform' => $session->platform,
            'meeting_id' => $session->meeting_id,
            'meeting_password' => $session->meeting_password,
        ]);
    }

    /**
     * Tutor upload material for assigned session.
     */
    public function storeMaterial(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = ClassSession::findOrFail($id);

        if ($session->tutor_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. You cannot upload materials for a session not assigned to you.',
            ], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,ppt,pptx,txt,zip',
        ]);

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        $fileType = $file->getClientOriginalExtension();
        $path = $file->store('materials', 'public');

        $material = ClassMaterial::create([
            'course_id' => $session->course_id,
            'class_session_id' => $session->id,
            'uploaded_by' => $user->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'file_path' => Storage::url($path),
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => $fileSize,
        ]);

        $material->load('uploader:id,name,email');

        return response()->json([
            'message' => 'Material uploaded successfully.',
            'material' => $material,
        ], 201);
    }
}
