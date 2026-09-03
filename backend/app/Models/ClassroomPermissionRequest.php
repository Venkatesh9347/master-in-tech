<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomPermissionRequest extends Model
{
    use HasFactory;

    protected $table = 'classroom_permission_requests';

    protected $fillable = [
        'class_session_id',
        'live_classroom_session_id',
        'user_id',
        'type',
        'status',
        'requested_at',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
