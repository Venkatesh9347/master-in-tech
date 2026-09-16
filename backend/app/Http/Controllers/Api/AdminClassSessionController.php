<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminClassSessionController extends Controller
{
    /**
     * List current / active class sessions with optional filters and search.
     */
    public function index(Request $request): JsonResponse
    {
        // 1. Run real-time status synchronization (expire past, promote live, revert premature)
        ClassSession::syncRealtimeStatuses();

        $query = ClassSession::with([
            'course:id,title,code,category,thumbnail,slug',
            'tutor:id,name,email,avatar,headline',
            'materials',
            'attendances.user:id,name,email',
            'creator:id,name,email',
            'updater:id,name,email',
        ]);

        // Default to active / current scheduled & live classes if status not specified
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        } elseif (! $request->filled('status')) {
            $query->whereIn('status', ['scheduled', 'live']);
        }

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('tutor_id') && $request->tutor_id !== 'all') {
            $query->where('tutor_id', $request->tutor_id);
        }

        if ($request->filled('platform') && $request->platform !== 'all') {
            $platform = strtolower(str_replace(' ', '', $request->platform));
            if ($platform === 'microsoftteams') {
                $platform = 'teams';
            }
            $query->where('platform', $platform);
        }

        if ($request->filled('date')) {
            $query->whereDate('scheduled_date', $request->date);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('id', $search)
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('meeting_id', 'like', "%{$search}%")
                    ->orWhereHas('course', fn ($cq) => $cq->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('batches', fn ($bq) => $bq->where('code', 'like', "%{$search}%"))
                    )
                    ->orWhereHas('tutor', fn ($tq) => $tq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $sessionsQuery = $query->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC")
            ->orderBy('scheduled_date', 'asc')
            ->orderBy('start_time', 'asc');

        $limit = $this->limitCap($request);
        if ($limit !== null) {
            $sessionsQuery->limit($limit);
        }

        $sessions = $sessionsQuery->get();

        // Enrich with authoritative batch codes in a single batch lookup
        $courseIds = $sessions->pluck('course_id')->unique()->filter()->values();
        $courseBatches = Batch::whereIn('course_id', $courseIds)
            ->orderBy('start_date', 'desc')
            ->get()
            ->groupBy('course_id');

        $enrichedSessions = $sessions->map(function (ClassSession $session) use ($courseBatches) {
            $tBatch = $courseBatches->get($session->course_id)?->first();
            $batchCode = $tBatch?->code;
            if (! $batchCode) {
                $batchCode = $session->course
                    ? Batch::generateBatchCode($session->course, $session->scheduled_date ?? now(), false)
                    : 'RIT(TECH)BC'.now()->format('dmy');
            }

            $courseCode = $session->course ? Batch::getCourseCode($session->course) : 'TECH';

            $arr = $session->toArray();
            $arr['batch_code'] = $batchCode;
            $arr['batch_number'] = $batchCode;
            $arr['course_code'] = $courseCode;
            $arr['batch'] = $tBatch ? [
                'id' => $tBatch->id,
                'code' => $tBatch->code,
                'name' => $tBatch->name,
                'start_date' => $tBatch->start_date ? $tBatch->start_date->toDateString() : (string) $tBatch->start_date,
            ] : null;

            return $arr;
        });

        // Global status counts
        $allTotal = ClassSession::count();
        $countScheduled = ClassSession::where('status', 'scheduled')->count();
        $countLive = ClassSession::where('status', 'live')->count();
        $countExpired = ClassSession::where('status', 'expired')->count();
        $countCompleted = ClassSession::where('status', 'completed')->count();
        $countCancelled = ClassSession::where('status', 'cancelled')->count();

        $allCourses = Course::select('id', 'title', 'code', 'category')->orderBy('title')->get();
        $allTutors = User::whereIn('role', ['tutor', 'admin'])->select('id', 'name', 'email', 'role')->orderBy('name')->get();
        $allBatches = Batch::select('id', 'code', 'name', 'course_id', 'start_date')->orderBy('code')->get();

        return response()->json([
            'sessions' => $enrichedSessions,
            'counts' => [
                'total' => $allTotal,
                'active' => $countScheduled + $countLive,
                'scheduled' => $countScheduled,
                'live' => $countLive,
                'history' => $countExpired + $countCompleted + $countCancelled,
                'expired' => $countExpired,
                'completed' => $countCompleted,
                'cancelled' => $countCancelled,
            ],
            'courses' => $allCourses,
            'tutors' => $allTutors,
            'batches' => $allBatches,
        ]);
    }

    /**
     * List all Class History sessions (EXPIRED, COMPLETED, CANCELLED) with advanced filters.
     */
    public function history(Request $request): JsonResponse
    {
        // 1. Run automatic expiration
        ClassSession::expirePastSessions();

        $query = ClassSession::with([
            'course:id,title,code,category,thumbnail,slug',
            'tutor:id,name,email,avatar,headline',
            'materials',
            'attendances.user:id,name,email',
            'creator:id,name,email',
            'updater:id,name,email',
        ]);

        // Filter status: by default includes all history statuses (expired, completed, cancelled)
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        } else {
            $query->whereIn('status', ['expired', 'completed', 'cancelled']);
        }

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('tutor_id') && $request->tutor_id !== 'all') {
            $query->where('tutor_id', $request->tutor_id);
        }

        if ($request->filled('platform') && $request->platform !== 'all') {
            $platform = strtolower(str_replace(' ', '', $request->platform));
            if ($platform === 'microsoftteams') {
                $platform = 'teams';
            }
            $query->where('platform', $platform);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('scheduled_date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('scheduled_date', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('id', $search)
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('meeting_id', 'like', "%{$search}%")
                    ->orWhereHas('course', fn ($cq) => $cq->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('batches', fn ($bq) => $bq->where('code', 'like', "%{$search}%"))
                    )
                    ->orWhereHas('tutor', fn ($tq) => $tq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $historyQuery = $query->orderBy('scheduled_date', 'desc')
            ->orderBy('start_time', 'desc');

        $limit = $this->limitCap($request);
        if ($limit !== null) {
            $historyQuery->limit($limit);
        }

        $sessions = $historyQuery->get();

        // Enrich with authoritative batch codes
        $courseIds = $sessions->pluck('course_id')->unique()->filter()->values();
        $courseBatches = Batch::whereIn('course_id', $courseIds)
            ->orderBy('start_date', 'desc')
            ->get()
            ->groupBy('course_id');

        $enrichedHistory = $sessions->map(function (ClassSession $session) use ($courseBatches) {
            $tBatch = $courseBatches->get($session->course_id)?->first();
            $batchCode = $tBatch?->code;
            if (! $batchCode) {
                $batchCode = $session->course
                    ? Batch::generateBatchCode($session->course, $session->scheduled_date ?? now(), false)
                    : 'RIT(TECH)BC'.now()->format('dmy');
            }

            $courseCode = $session->course ? Batch::getCourseCode($session->course) : 'TECH';

            // Mask sensitive meeting password
            $maskedPassword = ! empty($session->meeting_password)
                ? str_repeat('•', min(8, strlen($session->meeting_password)))
                : null;

            // Formatted Expiration / Completion Time
            $expiredAtStr = null;
            if ($session->expired_at) {
                $expiredAtStr = $session->expired_at->format('d-m-Y h:i A') . ' ' . Carbon::now(config('app.business_timezone'))->format('T');
            } elseif ($session->ended_at) {
                $expiredAtStr = $session->ended_at->format('d-m-Y h:i A') . ' ' . Carbon::now(config('app.business_timezone'))->format('T');
            } elseif ($session->status === 'expired') {
                $expiredAtStr = $session->end_time . ' ' . Carbon::now(config('app.business_timezone'))->format('T');
            }

            $arr = $session->toArray();
            $arr['batch_code'] = $batchCode;
            $arr['batch_number'] = $batchCode;
            $arr['course_code'] = $courseCode;
            $arr['masked_password'] = $maskedPassword;
            $arr['expired_at_formatted'] = $expiredAtStr;
            $arr['attendances_count'] = $session->attendances->count();
            $arr['materials_count'] = $session->materials->count();
            $arr['created_by_name'] = $session->creator?->name ?? 'System Administrator';
            $arr['updated_by_name'] = $session->updater?->name ?? ($session->creator?->name ?? 'Admin');
            $arr['batch'] = $tBatch ? [
                'id' => $tBatch->id,
                'code' => $tBatch->code,
                'name' => $tBatch->name,
                'start_date' => $tBatch->start_date ? $tBatch->start_date->toDateString() : (string) $tBatch->start_date,
            ] : null;

            return $arr;
        });

        // Filter batches by search if user searched a specific batch code
        if ($request->filled('search')) {
            $search = strtoupper(trim($request->search));
            $enrichedHistory = $enrichedHistory->filter(function ($item) use ($search) {
                return str_contains(strtoupper($item['batch_code']), $search) ||
                       str_contains(strtoupper((string) $item['id']), $search) ||
                       str_contains(strtoupper($item['title']), $search) ||
                       str_contains(strtoupper($item['course']['title'] ?? ''), $search) ||
                       str_contains(strtoupper($item['course_code'] ?? ''), $search) ||
                       str_contains(strtoupper($item['tutor']['name'] ?? ''), $search) ||
                       str_contains(strtoupper($item['meeting_id'] ?? ''), $search);
            })->values();
        }

        if ($request->filled('batch_id') && $request->batch_id !== 'all') {
            $batchId = (int) $request->batch_id;
            $enrichedHistory = $enrichedHistory->filter(function ($item) use ($batchId) {
                return isset($item['batch']['id']) && $item['batch']['id'] === $batchId;
            })->values();
        }

        $allCourses = Course::select('id', 'title', 'code', 'category')->orderBy('title')->get();
        $allTutors = User::whereIn('role', ['tutor', 'admin'])->select('id', 'name', 'email', 'role')->orderBy('name')->get();
        $allBatches = Batch::select('id', 'code', 'name', 'course_id', 'start_date')->orderBy('code')->get();

        return response()->json([
            'history' => $enrichedHistory,
            'counts' => [
                'total' => $enrichedHistory->count(),
                'expired' => $enrichedHistory->where('status', 'expired')->count(),
                'completed' => $enrichedHistory->where('status', 'completed')->count(),
                'cancelled' => $enrichedHistory->where('status', 'cancelled')->count(),
            ],
            'courses' => $allCourses,
            'tutors' => $allTutors,
            'batches' => $allBatches,
        ]);
    }

    /**
     * Show complete details for a historical class session.
     */
    public function historyShow(int $id): JsonResponse
    {
        ClassSession::expirePastSessions();

        $session = ClassSession::with([
            'course:id,title,code,category,thumbnail,slug,description',
            'tutor:id,name,email,avatar,headline',
            'materials',
            'attendances.user:id,name,email',
            'creator:id,name,email',
            'updater:id,name,email',
        ])->findOrFail($id);

        $courseBatch = Batch::where('course_id', $session->course_id)
            ->latest('start_date')
            ->first();

        $batchCode = $courseBatch?->code;
        if (! $batchCode) {
            $batchCode = $session->course
                ? Batch::generateBatchCode($session->course, $session->scheduled_date ?? now(), false)
                : 'RIT(TECH)BC'.now()->format('dmy');
        }

        $courseCode = $session->course ? Batch::getCourseCode($session->course) : 'TECH';

        $maskedPassword = ! empty($session->meeting_password)
            ? str_repeat('•', min(8, strlen($session->meeting_password)))
            : null;

        $arr = $session->toArray();
        $arr['batch_code'] = $batchCode;
        $arr['batch_number'] = $batchCode;
        $arr['course_code'] = $courseCode;
        $arr['masked_password'] = $maskedPassword;
        $arr['created_by_name'] = $session->creator?->name ?? 'System Administrator';
        $arr['updated_by_name'] = $session->updater?->name ?? ($session->creator?->name ?? 'Admin');
        $arr['batch'] = $courseBatch ? [
            'id' => $courseBatch->id,
            'code' => $courseBatch->code,
            'name' => $courseBatch->name,
            'start_date' => $courseBatch->start_date ? $courseBatch->start_date->toDateString() : (string) $courseBatch->start_date,
        ] : null;

        return response()->json($arr);
    }

    /**
     * Store a newly created class session.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'tutor_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'platform' => 'nullable|string',
            'meeting_url' => 'nullable|url',
            'meeting_id' => 'nullable|string|max:100',
            'meeting_password' => 'nullable|string|max:100',
            'scheduled_date' => 'required|date',
            'start_time' => 'required|string|max:20',
            'end_time' => 'required|string|max:20',
            'status' => 'nullable|string|in:scheduled,live,completed,cancelled',
            'admin_notes' => 'nullable|string',
            'recording_url' => 'nullable|url',
        ]);

        $this->validateTimeAndDate(
            $validated['start_time'],
            $validated['end_time'],
            $validated['scheduled_date'],
            $validated['status'] ?? 'scheduled'
        );

        // L4: the tutor_id must reference a user whose role is tutor.
        $tutor = User::where('id', $validated['tutor_id'])->first();
        if (! $tutor || $tutor->role !== 'tutor') {
            throw ValidationException::withMessages([
                'tutor_id' => ['The selected tutor must be a user with the tutor role.'],
            ]);
        }

        $platform = 'livekit';
        if (! empty($validated['platform']) && $validated['platform'] !== 'livekit') {
            $platform = strtolower(str_replace(' ', '', $validated['platform']));
            if ($platform === 'microsoftteams') {
                $platform = 'teams';
            }
        }

        $session = ClassSession::create([
            'course_id' => $validated['course_id'],
            'tutor_id' => $validated['tutor_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'platform' => $platform,
            'meeting_url' => $validated['meeting_url'] ?? null,
            'meeting_id' => $validated['meeting_id'] ?? null,
            'meeting_password' => $validated['meeting_password'] ?? null,
            'scheduled_date' => $validated['scheduled_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => $validated['status'] ?? 'scheduled',
            'admin_notes' => $validated['admin_notes'] ?? null,
            'recording_url' => $validated['recording_url'] ?? null,
            'created_by' => $request->user()->id,
            'livekit_status' => 'idle',
            'is_chat_enabled' => false,
        ]);

        // Automatically assign unique internal room name: masterintech-class-{id}
        $session->update([
            'livekit_room_name' => "masterintech-class-{$session->id}",
        ]);

        $session->load(['course:id,title,category', 'tutor:id,name,email,avatar']);

        AuditLog::log('created_class_session', $session, null, $session->toArray());

        return response()->json([
            'message' => 'Class session created successfully.',
            'session' => $session,
        ], 201);
    }

    /**
     * Show a single class session.
     */
    public function show(int $id): JsonResponse
    {
        $session = ClassSession::with([
            'course:id,title,category,thumbnail,slug',
            'tutor:id,name,email,avatar,headline',
            'materials',
            'attendances.user:id,name,email',
        ])->findOrFail($id);

        $arr = $session->toArray();
        $arr['masked_password'] = ! empty($session->meeting_password)
            ? str_repeat('•', min(8, strlen($session->meeting_password)))
            : null;

        return response()->json($arr);
    }

    /**
     * Update an existing class session.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $session = ClassSession::findOrFail($id);
        $old = $session->toArray();

        $validated = $request->validate([
            'course_id' => 'sometimes|required|exists:courses,id',
            'tutor_id' => 'sometimes|required|exists:users,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'platform' => 'nullable|string',
            'meeting_url' => 'nullable|url',
            'meeting_id' => 'nullable|string|max:100',
            'meeting_password' => 'nullable|string|max:100',
            'scheduled_date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required|string|max:20',
            'end_time' => 'sometimes|required|string|max:20',
            'status' => 'sometimes|required|string|in:scheduled,live,completed,cancelled',
            'admin_notes' => 'nullable|string',
            'recording_url' => 'nullable|url',
        ]);

        $startTime = $validated['start_time'] ?? $session->start_time;
        $endTime = $validated['end_time'] ?? $session->end_time;
        $date = $validated['scheduled_date'] ?? $session->scheduled_date->toDateString();
        $status = $validated['status'] ?? $session->status;

        $this->validateTimeAndDate($startTime, $endTime, $date, $status);

        if (isset($validated['platform'])) {
            $platform = strtolower(str_replace(' ', '', $validated['platform']));
            if ($platform === 'microsoftteams') {
                $platform = 'teams';
            }
            $validated['platform'] = $platform;
        }

        if (empty($session->livekit_room_name)) {
            $validated['livekit_room_name'] = "masterintech-class-{$session->id}";
        }

        $session->update($validated);
        $session->load(['course:id,title,category', 'tutor:id,name,email,avatar']);

        AuditLog::log('updated_class_session', $session, $old, $session->toArray());

        return response()->json([
            'message' => 'Class session updated successfully.',
            'session' => $session,
        ]);
    }

    /**
     * Delete a class session.
     */
    public function destroy(int $id): JsonResponse
    {
        $session = ClassSession::findOrFail($id);
        $old = $session->toArray();
        $session->delete();

        AuditLog::log('deleted_class_session', null, $old, null);

        return response()->json([
            'message' => 'Class session deleted successfully.',
        ]);
    }

    /**
     * Cancel a class session.
     */
    public function cancel(int $id): JsonResponse
    {
        $session = ClassSession::findOrFail($id);
        $old = $session->toArray();
        $session->update(['status' => 'cancelled']);

        AuditLog::log('cancelled_class_session', $session, $old, $session->toArray());

        return response()->json([
            'message' => 'Class session cancelled successfully.',
            'session' => $session,
        ]);
    }

    /**
     * Upload and attach material to a class session.
     */
    public function storeMaterial(Request $request, int $id): JsonResponse
    {
        $session = ClassSession::findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,ppt,pptx,txt,zip',
        ]);

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        $fileType = $file->getClientOriginalExtension();
        $path = $file->store('materials', 'materials');

        $material = ClassMaterial::create([
            'course_id' => $session->course_id,
            'class_session_id' => $session->id,
            'uploaded_by' => $request->user()->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'file_path' => $path,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => $fileSize,
        ]);

        $material->load('uploader:id,name,email');

        \App\Models\AuditLog::log('created_class_session_material', $material, null, [
            'id' => $material->id,
            'class_session_id' => $material->class_session_id,
            'course_id' => $material->course_id,
            'title' => $material->title,
        ]);

        return response()->json([
            'message' => 'Material uploaded successfully.',
            'material' => $material,
        ], 201);
    }

    /**
     * Delete a material attached to a session.
     */
    public function destroyMaterial(int $id, int $materialId): JsonResponse
    {
        $material = ClassMaterial::where('class_session_id', $id)->findOrFail($materialId);
        $old = $material->only([
            'id', 'class_session_id', 'course_id', 'title', 'file_name',
        ]);
        $material->delete();

        \App\Models\AuditLog::log('deleted_class_session_material', null, $old, null);

        return response()->json([
            'message' => 'Material removed successfully.',
        ]);
    }

    /**
     * Helper to validate start/end times and completion logic.
     */
    private function validateTimeAndDate(string $startTime, string $endTime, string $date, string $status): void
    {
        // Parse time comparison
        $startTimestamp = strtotime("1970-01-01 " . $startTime);
        $endTimestamp = strtotime("1970-01-01 " . $endTime);

        if ($startTimestamp === false || $endTimestamp === false || $endTimestamp <= $startTimestamp) {
            throw ValidationException::withMessages([
                'end_time' => ['The end time must be after the start time.'],
            ]);
        }

        // Do not allow a completed class to have a future date
        $today = Carbon::now(config('app.business_timezone'))->toDateString();
        if ($status === 'completed' && $date > $today) {
            throw ValidationException::withMessages([
                'status' => ['A completed class cannot have a future date.'],
            ]);
        }
    }
}
