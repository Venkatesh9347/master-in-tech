<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveClass extends Model
{
    use HasFactory;

    protected $table = 'live_classes';

    protected $fillable = [
        'course_id',
        'instructor_id',
        'title',
        'description',
        'class_date',
        'start_time',
        'end_time',
        'duration_minutes',
        'status',
        'provider',
        'meeting_id',
        'meeting_url',
        'host_url',
        'passcode',
        'is_chat_enabled',
        'is_mic_allowed_by_default',
        'provider_metadata',
        'settings',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'class_date' => 'date',
            'duration_minutes' => 'integer',
            'is_chat_enabled' => 'boolean',
            'is_mic_allowed_by_default' => 'boolean',
            'provider_metadata' => 'array',
            'settings' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(LiveClassAttendance::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LiveClassMessage::class)->orderBy('created_at', 'asc');
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

    public function isHost(User $user): bool
    {
        return $this->instructor_id === $user->id || $user->role === 'admin';
    }

    public function activeAttendanceFor(int $userId): ?LiveClassAttendance
    {
        return $this->attendances()->where('user_id', $userId)->whereNull('left_at')->first();
    }
}
