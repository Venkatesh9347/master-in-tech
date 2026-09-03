<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomParticipant extends Model
{
    use HasFactory;

    protected $table = 'classroom_participants';

    protected $fillable = [
        'class_session_id',
        'live_classroom_session_id',
        'user_id',
        'role',
        'is_host_active',
        'is_mic_allowed',
        'is_camera_allowed',
        'is_chat_allowed',
        'is_hand_raised',
        'hand_raised_at',
        'joined_at',
        'left_at',
        'duration_seconds',
        'connection_state',
    ];

    protected function casts(): array
    {
        return [
            'is_host_active' => 'boolean',
            'is_mic_allowed' => 'boolean',
            'is_camera_allowed' => 'boolean',
            'is_chat_allowed' => 'boolean',
            'is_hand_raised' => 'boolean',
            'hand_raised_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function liveClassroomSession(): BelongsTo
    {
        return $this->belongsTo(LiveClassroomSession::class);
    }
}
