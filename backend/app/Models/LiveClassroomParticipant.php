<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveClassroomParticipant extends Model
{
    use HasFactory;

    protected $table = 'live_classroom_participants';

    protected $fillable = [
        'live_classroom_session_id',
        'user_id',
        'role',
        'joined_at',
        'left_at',
        'duration_seconds',
        'is_mic_allowed',
        'is_camera_allowed',
        'is_hand_raised',
        'hand_raised_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'hand_raised_at' => 'datetime',
            'duration_seconds' => 'integer',
            'is_mic_allowed' => 'boolean',
            'is_camera_allowed' => 'boolean',
            'is_hand_raised' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveClassroomSession::class, 'live_classroom_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recordLeave(): void
    {
        if ($this->joined_at && ! $this->left_at) {
            $now = now();
            // Carbon 3 diffInSeconds() returns a float; duration_seconds is an
            // integer column and Eloquent integer casts apply on read only, so
            // normalize explicitly (truncation matches the hardened call sites).
            $duration = max(0, (int) $this->joined_at->diffInSeconds($now));
            $this->update([
                'left_at' => $now,
                'duration_seconds' => $this->duration_seconds + $duration,
            ]);
        }
    }
}
