<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CallRecording;
use App\Models\Enquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CallRecordingController extends Controller
{
    /**
     * List recordings scoped to the caller (admins see all).
     */
    public function index(Request $request)
    {
        $query = CallRecording::with([
            'enquiry:id,name,email,phone,status',
            'handler:id,name,email,role',
            'assignee:id,name,email,role',
        ])->visibleTo($request->user());

        if ($request->filled('enquiry_id')) {
            $query->where('enquiry_id', (int) $request->enquiry_id);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('recording_status', $request->status);
        }
        if ($request->filled('direction') && $request->direction !== 'all') {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('handled_by') && $request->handled_by !== 'all') {
            $query->where('handled_by', (int) $request->handled_by);
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 50))
        );
    }

    /**
     * Log a call with an optional provider-hosted recording reference.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'enquiry_id' => 'nullable|exists:enquiries,id',
            'user_id' => 'nullable|exists:users,id',
            'assigned_to' => 'nullable|exists:users,id',
            'call_started_at' => 'nullable|date',
            'call_ended_at' => 'nullable|date|after_or_equal:call_started_at',
            'duration_seconds' => 'nullable|integer|min:0|max:86400',
            'direction' => 'nullable|string|in:inbound,outbound',
            'outcome' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:5000',
            'follow_up_id' => 'nullable|exists:crm_follow_ups,id',
            'recording_provider' => 'nullable|string|max:64',
            'recording_reference' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        if (! empty($validated['enquiry_id'])) {
            $lead = Enquiry::findOrFail($validated['enquiry_id']);
            if (! $lead->isVisibleTo($user)) {
                return response()->json(['message' => 'You do not have access to this lead.'], 403);
            }
        }

        // Scoped staff log calls as themselves; they cannot attribute
        // handling or assignment to other staff members.
        $handledBy = $user->id;
        $assignedTo = $validated['assigned_to'] ?? null;
        if ($user->hasScopedCrmAccess()) {
            if ($assignedTo !== null && (int) $assignedTo !== (int) $user->id) {
                return response()->json([
                    'message' => 'You can only assign calls to yourself.',
                ], 403);
            }
        }

        $duration = $validated['duration_seconds'] ?? null;
        if ($duration === null && ! empty($validated['call_started_at']) && ! empty($validated['call_ended_at'])) {
            $duration = (int) max(0, strtotime($validated['call_ended_at']) - strtotime($validated['call_started_at']));
        }

        $recording = CallRecording::create([
            'enquiry_id' => $validated['enquiry_id'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'handled_by' => $handledBy,
            'assigned_to' => $assignedTo,
            'call_started_at' => $validated['call_started_at'] ?? null,
            'call_ended_at' => $validated['call_ended_at'] ?? null,
            'duration_seconds' => $duration,
            'direction' => $validated['direction'] ?? CallRecording::DIRECTION_OUTBOUND,
            'outcome' => $validated['outcome'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'follow_up_id' => $validated['follow_up_id'] ?? null,
            'recording_status' => ! empty($validated['recording_reference']) ? CallRecording::STATUS_READY : CallRecording::STATUS_NONE,
            'recording_provider' => $validated['recording_provider'] ?? null,
            'recording_reference' => $validated['recording_reference'] ?? null,
            'processing_state' => 'none',
        ]);

        AuditLog::log('created_call_recording', $recording, null, $recording->toArray());

        return response()->json([
            'message' => 'Call logged successfully.',
            'recording' => $recording->load(['enquiry', 'handler', 'assignee']),
        ], 201);
    }

    /**
     * Show a single recording (scoped).
     */
    public function show(Request $request, CallRecording $recording)
    {
        if (! $recording->isVisibleTo($request->user())) {
            return response()->json(['message' => 'You do not have access to this recording.'], 403);
        }

        return response()->json(
            $recording->load(['enquiry', 'customer:id,name,email', 'handler', 'assignee', 'followUp'])
        );
    }

    /**
     * Update outcome/notes/assignment (never file fields — use upload).
     */
    public function update(Request $request, CallRecording $recording)
    {
        if (! $recording->isVisibleTo($request->user())) {
            return response()->json(['message' => 'You do not have access to this recording.'], 403);
        }

        $validated = $request->validate([
            'outcome' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:5000',
            'assigned_to' => 'nullable|exists:users,id',
            'processing_state' => 'nullable|string|in:pending,processed,failed',
        ]);

        $user = $request->user();
        if ($user->hasScopedCrmAccess()
            && array_key_exists('assigned_to', $validated)
            && $validated['assigned_to'] !== null
            && (int) $validated['assigned_to'] !== (int) $user->id) {
            return response()->json(['message' => 'You can only assign calls to yourself.'], 403);
        }

        $old = $recording->toArray();
        $recording->update($validated);
        AuditLog::log('updated_call_recording', $recording, $old, $recording->fresh()->toArray());

        return response()->json([
            'message' => 'Recording updated successfully.',
            'recording' => $recording->fresh()->load(['enquiry', 'handler', 'assignee']),
        ]);
    }

    /**
     * Upload a recording file to private storage with server-side checksum.
     */
    public function upload(Request $request, CallRecording $recording)
    {
        if (! $recording->isVisibleTo($request->user())) {
            return response()->json(['message' => 'You do not have access to this recording.'], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:mp3,wav,ogg,m4a,flac,webm,aac,mp4|max:102400',
        ]);

        $file = $request->file('file');
        $safeName = 'call_' . $recording->id . '_' . time() . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('call-recordings', $safeName, 'recordings');

        $checksum = hash_file('sha256', Storage::disk('recordings')->path($path));

        $old = $recording->toArray();
        $recording->update([
            'storage_disk' => 'recordings',
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'checksum' => $checksum,
            'recording_status' => CallRecording::STATUS_READY,
            'recording_provider' => $recording->recording_provider ?? 'upload',
            'processing_state' => 'processed',
        ]);
        AuditLog::log('uploaded_call_recording', $recording, $old, $recording->fresh()->toArray());

        return response()->json([
            'message' => 'Recording uploaded successfully.',
            'recording' => $recording->fresh()->load(['enquiry', 'handler']),
        ], 201);
    }

    /**
     * Authorized playback/download. Every access is audit-logged.
     * No public URL is ever exposed for recording files.
     */
    public function download(Request $request, CallRecording $recording)
    {
        if (! $recording->isVisibleTo($request->user())) {
            return response()->json(['message' => 'You do not have access to this recording.'], 403);
        }

        if (empty($recording->storage_path)
            || ! Storage::disk($recording->storage_disk ?? 'recordings')->exists($recording->storage_path)) {
            return response()->json(['message' => 'No stored recording file for this call.'], 404);
        }

        AuditLog::log('accessed_call_recording', $recording, null, [
            'recording_id' => $recording->id,
            'accessed_by' => $request->user()->id,
        ]);

        return Storage::disk($recording->storage_disk ?? 'recordings')
            ->download($recording->storage_path, 'call-recording-' . $recording->id, [
                'Content-Type' => $recording->mime_type ?? 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
            ]);
    }

    /**
     * Retention deletion. Admin-only: removes the file and the record.
     */
    public function destroy(Request $request, CallRecording $recording)
    {
        if ($request->user()->hasScopedCrmAccess()) {
            return response()->json(['message' => 'Only administrators can delete recordings.'], 403);
        }

        if (! empty($recording->storage_path)
            && Storage::disk($recording->storage_disk ?? 'recordings')->exists($recording->storage_path)) {
            Storage::disk($recording->storage_disk ?? 'recordings')->delete($recording->storage_path);
        }

        $old = $recording->toArray();
        $recording->delete();
        AuditLog::log('deleted_call_recording', null, $old, null);

        return response()->json(['message' => 'Recording deleted successfully.']);
    }
}
