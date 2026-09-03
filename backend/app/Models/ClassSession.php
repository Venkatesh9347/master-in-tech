<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    use HasFactory;

    protected $table = 'class_sessions';

    protected $fillable = [
        'course_id',
        'tutor_id',
        'current_host_id',
        'quiz_id',
        'title',
        'description',
        'platform',
        'meeting_url',
        'meeting_id',
        'meeting_password',
        'livekit_room_name',
        'livekit_status',
        'is_chat_enabled',
        'host_user_id',
        'scheduled_date',
        'start_time',
        'end_time',
        'started_at',
        'status',
        'expired_at',
        'ended_at',
        'actual_duration_seconds',
        'admin_notes',
        'recording_url',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'expired_at' => 'datetime',
            'ended_at' => 'datetime',
            'actual_duration_seconds' => 'integer',
            'is_chat_enabled' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ClassMaterial::class, 'class_session_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(ClassSessionAttendance::class, 'class_session_id');
    }

    /**
     * Synchronize real-time class statuses strictly based on IST datetime:
     * - LIVE: scheduled_date = today AND start_time <= now < end_time
     * - SCHEDULED: scheduled_date > today OR (scheduled_date = today AND now < start_time)
     * - EXPIRED: scheduled_date < today OR (scheduled_date = today AND now >= end_time)
     */
    public static function syncRealtimeStatuses(): int
    {
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');
        $updatedCount = 0;

        // 1. Expire past sessions (scheduled or live where end_time <= currentTime or date < today)
        $expired = static::whereIn('status', ['scheduled', 'live'])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('scheduled_date', '<', $today)
                    ->orWhere(function ($q2) use ($today, $currentTime) {
                        $q2->where('scheduled_date', '=', $today)
                            ->where('end_time', '<=', $currentTime);
                    });
            })
            ->update([
                'status' => 'expired',
                'expired_at' => $now,
                'ended_at' => $now,
            ]);
        $updatedCount += $expired;

        // 2. Transition active sessions to LIVE if start_time <= currentTime < end_time on today's date
        $live = static::where('status', 'scheduled')
            ->where('scheduled_date', '=', $today)
            ->where('start_time', '<=', $currentTime)
            ->where('end_time', '>', $currentTime)
            ->update([
                'status' => 'live',
            ]);
        $updatedCount += $live;

        // 3. Revert premature live status if scheduled for the future
        $reverted = static::where('status', 'live')
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('scheduled_date', '>', $today)
                    ->orWhere(function ($q2) use ($today, $currentTime) {
                        $q2->where('scheduled_date', '=', $today)
                            ->where('start_time', '>', $currentTime);
                    });
            })
            ->update([
                'status' => 'scheduled',
            ]);
        $updatedCount += $reverted;

        return $updatedCount;
    }

    /**
     * Backward-compatible alias for expiring past sessions.
     */
    public static function expirePastSessions(): int
    {
        return static::syncRealtimeStatuses();
    }

    /**
     * Compute real-time status in IST without relying on stale DB values.
     */
    public function calculateStatus(?Carbon $at = null): string
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return $this->status;
        }

        $at = $at ?: Carbon::now('Asia/Kolkata');
        $date = $at->toDateString();
        $time = $at->format('H:i');

        $sessionDate = is_string($this->scheduled_date)
            ? $this->scheduled_date
            : $this->scheduled_date?->toDateString();

        if (! $sessionDate) {
            return $this->status;
        }

        if ($sessionDate < $date) {
            return 'expired';
        }

        if ($sessionDate > $date) {
            return 'scheduled';
        }

        $start = is_string($this->start_time) ? substr($this->start_time, 0, 5) : '00:00';
        $end = is_string($this->end_time) ? substr($this->end_time, 0, 5) : '23:59';

        if ($time < $start) {
            return 'scheduled';
        }

        if ($time >= $start && $time < $end) {
            return 'live';
        }

        return 'expired';
    }

    /**
     * Determine if session is live right now based on IST.
     */
    public function isLiveNow(?Carbon $at = null): bool
    {
        return $this->calculateStatus($at) === 'live';
    }

    /**
     * Determine if session is expired or completed based on IST.
     */
    public function isExpired(?Carbon $at = null): bool
    {
        return in_array($this->calculateStatus($at), ['completed', 'expired']);
    }

    /**
     * Resolve authoritative batch code for this class session.
     */
    public function resolveBatchCode(?User $forUser = null): string
    {
        if ($forUser && $forUser->role === 'student') {
            $studentBatch = BatchStudent::where('user_id', $forUser->id)
                ->where('status', 'active')
                ->whereHas('batch', fn ($q) => $q->where('course_id', $this->course_id))
                ->with('batch')
                ->first()?->batch;

            if ($studentBatch && ! empty($studentBatch->code)) {
                return $studentBatch->code;
            }
        }

        // Tutor-assigned batch
        if ($this->tutor_id) {
            $tutorBatch = Batch::where('course_id', $this->course_id)
                ->where('tutor_id', $this->tutor_id)
                ->latest('start_date')
                ->first();

            if ($tutorBatch && ! empty($tutorBatch->code)) {
                return $tutorBatch->code;
            }
        }

        // Active course batch
        $courseBatch = Batch::where('course_id', $this->course_id)
            ->latest('start_date')
            ->first();

        if ($courseBatch && ! empty($courseBatch->code)) {
            return $courseBatch->code;
        }

        // Fallback: derive authoritative code using Batch generator
        if ($this->course) {
            return Batch::generateBatchCode($this->course, $this->scheduled_date ?? now(), false);
        }

        $course = Course::find($this->course_id);
        if ($course) {
            return Batch::generateBatchCode($course, $this->scheduled_date ?? now(), false);
        }

        return 'RIT(TECH)BC'.now()->format('dmy');
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Scope for active/scheduled sessions (strictly excluding expired/completed/cancelled).
     * Puts LIVE classes first, followed by upcoming classes sorted chronologically.
     */
    public function scopeActiveOrScheduled(Builder $query): Builder
    {
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        return $query->whereIn('status', ['scheduled', 'live'])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('scheduled_date', '>', $today)
                    ->orWhere(function ($q2) use ($today, $currentTime) {
                        $q2->where('scheduled_date', '=', $today)
                            ->where('end_time', '>', $currentTime);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC")
            ->orderBy('scheduled_date', 'asc')
            ->orderBy('start_time', 'asc');
    }

    /**
     * Scope for class history (expired, completed, or cancelled).
     */
    public function scopeHistory(Builder $query): Builder
    {
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        return $query->where(function ($q) use ($today, $currentTime) {
            $q->whereIn('status', ['expired', 'completed', 'cancelled'])
                ->orWhere('scheduled_date', '<', $today)
                ->orWhere(function ($q2) use ($today, $currentTime) {
                    $q2->where('scheduled_date', '=', $today)
                        ->where('end_time', '<=', $currentTime);
                });
        })->orderBy('scheduled_date', 'desc')
            ->orderBy('start_time', 'desc');
    }

    /**
     * Scope for upcoming sessions (scheduled/live with future/current dates).
     * LIVE sessions appear first, followed by upcoming sessions.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        return $query->whereIn('status', ['scheduled', 'live'])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('scheduled_date', '>', $today)
                    ->orWhere(function ($q2) use ($today, $currentTime) {
                        $q2->where('scheduled_date', '=', $today)
                            ->where('end_time', '>', $currentTime);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC")
            ->orderBy('scheduled_date', 'asc')
            ->orderBy('start_time', 'asc');
    }

    /**
     * Scope for today's active sessions.
     * LIVE sessions appear first, followed by today's upcoming sessions.
     */
    public function scopeToday(Builder $query): Builder
    {
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        return $query->where('scheduled_date', $today)
            ->whereIn('status', ['scheduled', 'live'])
            ->where('end_time', '>', $currentTime)
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC")
            ->orderBy('start_time', 'asc');
    }

    /**
     * Scope for previous/completed/expired sessions.
     */
    public function scopePrevious(Builder $query): Builder
    {
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        return $query->where(function ($q) use ($today, $currentTime) {
            $q->whereIn('status', ['completed', 'expired'])
                ->orWhere(function ($q2) use ($today, $currentTime) {
                    $q2->where('scheduled_date', '<', $today)
                        ->where('status', '!=', 'cancelled');
                })
                ->orWhere(function ($q3) use ($today, $currentTime) {
                    $q3->where('scheduled_date', '=', $today)
                        ->where('end_time', '<', $currentTime)
                        ->where('status', '!=', 'cancelled');
                });
        })->orderBy('scheduled_date', 'desc')
            ->orderBy('start_time', 'desc');
    }

    /**
     * Resolve standard internal LiveKit room identifier: masterintech-session-{session_id}
     */
    public function resolveLivekitRoomName(): string
    {
        $expected = "masterintech-session-{$this->id}";
        if ($this->livekit_room_name !== $expected) {
            $this->update(['livekit_room_name' => $expected]);
        }
        return $expected;
    }

    public function currentHost(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_host_id');
    }

    public function classroomParticipants(): HasMany
    {
        return $this->hasMany(ClassroomParticipant::class, 'class_session_id');
    }

    public function permissionRequests(): HasMany
    {
        return $this->hasMany(ClassroomPermissionRequest::class, 'class_session_id');
    }

    public function classroomMessages(): HasMany
    {
        return $this->hasMany(ClassroomMessage::class, 'class_session_id');
    }

    public function moderationEvents(): HasMany
    {
        return $this->hasMany(ClassroomModerationEvent::class, 'class_session_id');
    }

    /**
     * Get active host user ID (current_host_id if delegated, fallback to assigned tutor or creator).
     */
    public function getActiveHostId(): int
    {
        return (int) ($this->current_host_id ?: $this->tutor_id ?: $this->created_by);
    }

    /**
     * Determine if a user is currently the active host or platform admin.
     */
    public function isHost(User $user): bool
    {
        if ($user->role === 'admin' || $user->role === 'super_admin') {
            return true;
        }

        if ($this->current_host_id) {
            return (int) $this->current_host_id === (int) $user->id;
        }

        return (int) $this->tutor_id === (int) $user->id;
    }

    /**
     * Determine if a student user is authorized to join this class session.
     * Verifies course enrollment and active batch enrollment.
     */
    public function canStudentJoin(User $user): bool
    {
        if ($user->role === 'admin' || $user->role === 'super_admin') {
            return true;
        }

        $isCourseEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->exists();

        if (! $isCourseEnrolled) {
            return false;
        }

        // If session is assigned to a specific tutor, verify the student belongs to a batch taught by that tutor
        if ($this->tutor_id) {
            $hasMatchingTutorBatch = BatchStudent::where('user_id', $user->id)
                ->where('status', 'active')
                ->whereHas('batch', function ($q) {
                    $q->where('course_id', $this->course_id)
                        ->where('tutor_id', $this->tutor_id);
                })->exists();

            if (! $hasMatchingTutorBatch) {
                return false;
            }
        }

        // Must belong to an active batch for this course
        return BatchStudent::where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('batch', function ($q) {
                $q->where('course_id', $this->course_id);
            })->exists();
    }
}
