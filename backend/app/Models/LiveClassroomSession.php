<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LiveClassroomSession extends Model
{
    use HasFactory;

    protected $table = 'live_classroom_sessions';

    protected $fillable = [
        'room_id',
        'batch_id',
        'course_id',
        'tutor_id',
        'current_host_id',
        'title',
        'description',
        'scheduled_date',
        'start_time',
        'end_time',
        'status',
        'is_chat_enabled',
        'started_at',
        'ended_at',
        'settings',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_chat_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LiveClassroomSession $session) {
            if (empty($session->room_id)) {
                $session->room_id = 'mit-room-' . (string) Str::uuid();
            }
            if (empty($session->course_id) && $session->batch_id) {
                $batch = Batch::find($session->batch_id);
                if ($batch) {
                    $session->course_id = $batch->course_id;
                }
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(LiveClassroomParticipant::class, 'live_classroom_session_id');
    }

    public function currentHost(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_host_id');
    }

    public function classroomParticipants(): HasMany
    {
        return $this->hasMany(ClassroomParticipant::class, 'live_classroom_session_id');
    }

    public function permissionRequests(): HasMany
    {
        return $this->hasMany(ClassroomPermissionRequest::class, 'live_classroom_session_id');
    }

    public function classroomMessages(): HasMany
    {
        return $this->hasMany(ClassroomMessage::class, 'live_classroom_session_id');
    }

    public function moderationEvents(): HasMany
    {
        return $this->hasMany(ClassroomModerationEvent::class, 'live_classroom_session_id');
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

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
     * Determine if a student user is enrolled and active in this session's batch.
     */
    public function isStudentEnrolled(User $user): bool
    {
        return BatchStudent::where('batch_id', $this->batch_id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
