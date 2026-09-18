<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\LiveClassMessage;
use App\Models\User;
use App\Services\LiveClass\LiveClassProviderManager;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveClassController extends Controller
{
    /**
     * List live classes for a specific course.
     */
    public function index(Request $request, int $courseId): JsonResponse
    {
        $user = $request->user();
        $course = Course::findOrFail($courseId);

        $this->authorizeCourseAccess($user, $course);

        $liveClasses = LiveClass::where('course_id', $courseId)
            ->with(['instructor:id,name,email,role,avatar,headline'])
            ->orderBy('class_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
            ],
            'classes' => $liveClasses,
            'upcoming' => $liveClasses->filter(fn ($c) => $c->status === 'scheduled'),
            'live' => $liveClasses->filter(fn ($c) => $c->status === 'live'),
            'completed' => $liveClasses->filter(fn ($c) => $c->status === 'completed'),
        ]);
    }

    /**
     * Get all upcoming and active live classes across all courses for the logged-in student/tutor.
     */
    public function myLiveClasses(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'student' || empty($user->role)) {
            // B3 pay-before-classroom: only active/completed enrollments list classes.
            $enrolledCourseIds = CourseEnrollment::where('user_id', $user->id)
                ->whereIn('status', ['active', 'completed'])
                ->pluck('course_id');

            $classes = LiveClass::whereIn('course_id', $enrolledCourseIds)
                ->with(['course:id,title,category,thumbnail', 'instructor:id,name,email,avatar,headline'])
                ->whereIn('status', ['scheduled', 'live'])
                ->orderBy('class_date', 'asc')
                ->orderBy('start_time', 'asc')
                ->get();
        } elseif ($user->role === 'tutor') {
            $classes = LiveClass::where('instructor_id', $user->id)
                ->with(['course:id,title,category,thumbnail'])
                ->orderBy('class_date', 'asc')
                ->orderBy('start_time', 'asc')
                ->get();
        } else {
            // Admin sees all platform live classes
            $classes = LiveClass::with(['course:id,title,category,thumbnail', 'instructor:id,name,email,avatar'])
                ->orderBy('class_date', 'desc')
                ->get();
        }

        return response()->json($classes);
    }

    /**
     * Get details, real-time status, and provider launch information for a live class.
     */
    public function show(Request $request, int $id, LiveClassProviderManager $providerManager): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::with(['course:id,title,category,thumbnail', 'instructor:id,name,email,avatar,headline'])
            ->findOrFail($id);

        $this->authorizeCourseAccess($user, $liveClass->course);

        $launchPayload = $providerManager->buildParticipantPayload($liveClass, $user);
        $myAttendance = $liveClass->attendances()->where('user_id', $user->id)->first();
        $isHost = $liveClass->isHost($user);

        $activeParticipantsCount = $liveClass->attendances()
            ->whereNull('left_at')
            ->where('is_removed', false)
            ->count();

        $raisedHandsCount = $liveClass->attendances()
            ->where('is_hand_raised', true)
            ->whereNull('left_at')
            ->count();

        return response()->json([
            'live_class' => $liveClass,
            'launch' => $launchPayload,
            'my_attendance' => $myAttendance,
            'is_host' => $isHost,
            'active_participants_count' => $activeParticipantsCount,
            'raised_hands_count' => $raisedHandsCount,
        ]);
    }

    /**
     * Student joins the live classroom session and logs attendance.
     */
    public function join(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeCourseAccess($user, $liveClass->course);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $user->id)
            ->first();

        if ($attendance && $attendance->is_removed) {
            throw ValidationException::withMessages([
                'attendance' => ['You have been removed from this live classroom by the instructor.'],
            ]);
        }

        if ($attendance) {
            $attendance->update([
                'joined_at' => now(),
                'left_at' => null,
                'status' => 'present',
                'is_muted' => true,
            ]);
        } else {
            $attendance = LiveClassAttendance::create([
                'live_class_id' => $liveClass->id,
                'user_id' => $user->id,
                'course_id' => $liveClass->course_id,
                'joined_at' => now(),
                'status' => 'present',
                'is_mic_allowed' => (bool) $liveClass->is_mic_allowed_by_default,
                'is_muted' => true,
                'is_hand_raised' => false,
                'is_removed' => false,
            ]);
        }

        return response()->json([
            'message' => 'Joined live classroom successfully.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Student leaves the live classroom and records duration.
     */
    public function leave(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $user->id)
            ->first();

        if ($attendance) {
            $attendance->recordLeave();
        }

        return response()->json([
            'message' => 'Left live classroom successfully.',
        ]);
    }

    /**
     * Student raises their hand to request speaking permission.
     */
    public function raiseHand(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $attendance->update([
            'is_hand_raised' => true,
            'hand_raised_at' => now(),
        ]);

        return response()->json([
            'message' => 'Hand raised. The instructor has been notified.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Student lowers their raised hand.
     */
    public function lowerHand(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $attendance->update([
            'is_hand_raised' => false,
            'hand_raised_at' => null,
        ]);

        return response()->json([
            'message' => 'Hand lowered.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Get live classroom messages/chat stream.
     */
    public function messages(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeCourseAccess($user, $liveClass->course);

        // Bounded chat window matching the classroom chat endpoint: at most
        // the oldest 150 messages are serialized in one response. History is
        // retained (nothing is deleted); ordering is unchanged.
        $messages = LiveClassMessage::where('live_class_id', $liveClass->id)
            ->with(['user:id,name,role,avatar'])
            ->orderBy('created_at', 'asc')
            ->take(150)
            ->get();

        return response()->json($messages);
    }

    /**
     * Post message in live classroom chat (enforces host-level chat permission).
     */
    public function postMessage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeCourseAccess($user, $liveClass->course);

        $isHost = $liveClass->isHost($user);

        // Enforce tutor chat toggle
        if (! $isHost && ! $liveClass->is_chat_enabled) {
            throw ValidationException::withMessages([
                'message' => ['Classroom chat has been disabled by the instructor.'],
            ]);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'is_announcement' => 'nullable|boolean',
        ]);

        $message = LiveClassMessage::create([
            'live_class_id' => $liveClass->id,
            'user_id' => $user->id,
            'message' => trim($validated['message']),
            'is_announcement' => $isHost && ! empty($validated['is_announcement']),
        ]);

        $message->load('user:id,name,role,avatar');

        return response()->json($message, 201);
    }

    /**
     * Polling endpoint for real-time live classroom state.
     */
    public function state(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeCourseAccess($user, $liveClass->course);

        $isHost = $liveClass->isHost($user);
        $myAttendance = $liveClass->attendances()->where('user_id', $user->id)->first();

        $activeParticipants = $liveClass->attendances()
            ->with(['user:id,name,email,avatar,role'])
            ->whereNull('left_at')
            ->where('is_removed', false)
            ->get();

        $raisedHands = $activeParticipants->filter(fn ($p) => $p->is_hand_raised)->values();

        return response()->json([
            'status' => $liveClass->status,
            'is_chat_enabled' => (bool) $liveClass->is_chat_enabled,
            'active_count' => $activeParticipants->count(),
            'my_attendance' => $myAttendance,
            'raised_hands' => $raisedHands,
            'participants' => $isHost ? $activeParticipants : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Tutor & Instructor Host Controls
    |--------------------------------------------------------------------------
    */

    /**
     * Create a scheduled live class for a course.
     */
    public function store(Request $request, int $courseId, LiveClassProviderManager $providerManager): JsonResponse
    {
        $user = $request->user();
        $course = Course::findOrFail($courseId);

        $this->authorizeTutorOwnership($user, $course);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'class_date' => 'required|date',
            'start_time' => 'required|string|max:10',
            'end_time' => 'nullable|string|max:10',
            'duration_minutes' => 'nullable|integer|min:5|max:480',
            'provider' => 'nullable|string|in:zoom,teams,google_meet,jitsi,custom',
            'meeting_id' => 'nullable|string|max:255',
            'meeting_url' => 'nullable|string|url|max:2000',
            'host_url' => 'nullable|string|url|max:2000',
            'passcode' => 'nullable|string|max:100',
            'is_chat_enabled' => 'nullable|boolean',
            'is_mic_allowed_by_default' => 'nullable|boolean',
        ]);

        $normalized = $providerManager->normalizeProviderAttributes($validated);

        $liveClass = LiveClass::create(array_merge([
            'course_id' => $course->id,
            'instructor_id' => $course->instructor_id ?: $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'class_date' => $validated['class_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'] ?? null,
            'duration_minutes' => $validated['duration_minutes'] ?? 60,
            'status' => 'scheduled',
        ], $normalized));

        AuditLog::log('created_live_class', $liveClass, null, $liveClass->toArray());

        // F1: single auto-commit write above; enrolled students notified.
        NotificationService::liveClassScheduled($liveClass->fresh());

        return response()->json([
            'message' => 'Live class scheduled successfully.',
            'live_class' => $liveClass,
        ], 201);
    }

    /**
     * Update an existing live class.
     */
    public function update(Request $request, int $id, LiveClassProviderManager $providerManager): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'class_date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required|string|max:10',
            'end_time' => 'nullable|string|max:10',
            'duration_minutes' => 'nullable|integer|min:5|max:480',
            'provider' => 'nullable|string|in:zoom,teams,google_meet,jitsi,custom',
            'meeting_id' => 'nullable|string|max:255',
            'meeting_url' => 'nullable|string|url|max:2000',
            'host_url' => 'nullable|string|url|max:2000',
            'passcode' => 'nullable|string|max:100',
            'is_chat_enabled' => 'nullable|boolean',
            'is_mic_allowed_by_default' => 'nullable|boolean',
            'status' => 'nullable|string|in:scheduled,live,completed,cancelled',
        ]);

        $normalized = $providerManager->normalizeProviderAttributes($validated);
        $old = $liveClass->toArray();
        $liveClass->update(array_merge($validated, $normalized));

        AuditLog::log('updated_live_class', $liveClass, $old, $liveClass->fresh()->toArray());

        // F1: single auto-commit write above.
        NotificationService::liveClassUpdated($liveClass->fresh());

        return response()->json([
            'message' => 'Live class updated successfully.',
            'live_class' => $liveClass,
        ]);
    }

    /**
     * Delete a live class.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $old = $liveClass->toArray();

        // F1: snapshot immutable facts + recipients before delete; the
        // record is gone afterwards, so the job sends from snapshot only.
        $cancelSnapshot = [
            'title' => (string) $liveClass->title,
            'class_date' => $liveClass->class_date,
            'start_time' => $liveClass->start_time,
            'course_title' => (string) ($liveClass->course?->title ?? ''),
        ];
        $cancelRecipients = CourseEnrollment::where('course_id', $liveClass->course_id)
            ->whereIn('status', ['active', 'completed'])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $liveClass->delete();

        AuditLog::log('deleted_live_class', null, $old, null);

        NotificationService::liveClassCancelled($cancelSnapshot, $cancelRecipients);

        return response()->json([
            'message' => 'Live class deleted successfully.',
        ]);
    }

    /**
     * Host starts the live classroom session.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $oldStatus = $liveClass->status;

        $liveClass->update([
            'status' => 'live',
            'started_at' => $liveClass->started_at ?: now(),
        ]);

        AuditLog::log('started_live_class', $liveClass, [
            'id' => $liveClass->id,
            'course_id' => $liveClass->course_id,
            'status' => $oldStatus,
        ], [
            'id' => $liveClass->id,
            'course_id' => $liveClass->course_id,
            'status' => 'live',
        ]);

        return response()->json([
            'message' => 'Live classroom session is now LIVE.',
            'live_class' => $liveClass,
        ]);
    }

    /**
     * Host ends the live classroom session.
     */
    public function end(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $oldStatus = $liveClass->status;

        $liveClass->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);

        AuditLog::log('ended_live_class', $liveClass, [
            'id' => $liveClass->id,
            'course_id' => $liveClass->course_id,
            'status' => $oldStatus,
        ], [
            'id' => $liveClass->id,
            'course_id' => $liveClass->course_id,
            'status' => 'completed',
        ]);

        // Auto-close any lingering participant sessions
        $now = now();
        $activeAttendances = $liveClass->attendances()->whereNull('left_at')->get();
        foreach ($activeAttendances as $attendance) {
            $duration = $attendance->joined_at ? max(0, $attendance->joined_at->diffInSeconds($now)) : 0;
            $attendance->update([
                'left_at' => $now,
                'duration_seconds' => $attendance->duration_seconds + $duration,
                'status' => 'attended',
                'is_hand_raised' => false,
            ]);
        }

        return response()->json([
            'message' => 'Live classroom session ended.',
            'live_class' => $liveClass,
        ]);
    }

    /**
     * Tutor toggles student chat on/off.
     */
    public function toggleChat(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $liveClass->update([
            'is_chat_enabled' => ! $liveClass->is_chat_enabled,
        ]);

        return response()->json([
            'message' => $liveClass->is_chat_enabled ? 'Student chat enabled.' : 'Student chat disabled.',
            'is_chat_enabled' => $liveClass->is_chat_enabled,
        ]);
    }

    /**
     * Tutor grants microphone speaking permission to a student.
     */
    public function allowMic(Request $request, int $id, int $userId): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $attendance->update([
            'is_mic_allowed' => true,
            'is_muted' => false,
            'is_hand_raised' => false, // Hand lowered automatically when mic granted
        ]);

        return response()->json([
            'message' => 'Microphone permission granted to student.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Tutor revokes microphone speaking permission from a student.
     */
    public function revokeMic(Request $request, int $id, int $userId): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $attendance->update([
            'is_mic_allowed' => false,
            'is_muted' => true,
        ]);

        return response()->json([
            'message' => 'Microphone permission revoked.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Tutor lowers a student's raised hand.
     */
    public function tutorLowerHand(Request $request, int $id, int $userId): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $attendance->update([
            'is_hand_raised' => false,
            'hand_raised_at' => null,
        ]);

        return response()->json([
            'message' => "Student's raised hand lowered.",
            'attendance' => $attendance,
        ]);
    }

    /**
     * Tutor removes / bans a participant from the session.
     */
    public function removeParticipant(Request $request, int $id, int $userId): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $attendance->recordLeave();
        $attendance->update([
            'is_removed' => true,
            'is_mic_allowed' => false,
        ]);

        return response()->json([
            'message' => 'Participant removed from live classroom.',
        ]);
    }

    /**
     * Tutor / Admin views complete attendance sheet.
     */
    public function attendance(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $liveClass = LiveClass::findOrFail($id);

        $this->authorizeTutorOwnership($user, $liveClass->course);

        $attendances = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->with(['user:id,name,email,avatar,role,phone'])
            ->orderBy('joined_at', 'asc')
            ->get();

        $totalMinutesAttended = round($attendances->sum('duration_seconds') / 60, 1);

        return response()->json([
            'live_class' => $liveClass,
            'attendances' => $attendances,
            'total_attendees' => $attendances->count(),
            'total_minutes_attended' => $totalMinutesAttended,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Private Authorization Helpers
    |--------------------------------------------------------------------------
    */

    private function authorizeCourseAccess(User $user, Course $course): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->role === 'tutor' && (int) $course->instructor_id === (int) $user->id) {
            return;
        }

        // B3 pay-before-classroom: only active/completed grant live access.
        $isEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        if (! $isEnrolled) {
            abort(403, 'Access denied: you must be enrolled in this course to access live classes.');
        }
    }

    private function authorizeTutorOwnership(User $user, Course $course): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->role === 'tutor' && (int) $course->instructor_id === (int) $user->id) {
            return;
        }

        abort(403, 'Unauthorized: you are not authorized to manage live classes for this course.');
    }
}
