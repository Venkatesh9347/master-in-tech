<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomModerationEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'classroom_moderation_events';

    protected $fillable = [
        'class_session_id',
        'live_classroom_session_id',
        'actor_id',
        'target_user_id',
        'action',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
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
