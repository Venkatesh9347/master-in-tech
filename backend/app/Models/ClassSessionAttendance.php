<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassSessionAttendance extends Model
{
    use HasFactory;

    protected $table = 'class_session_attendances';

    protected $fillable = [
        'class_session_id',
        'user_id',
        'status',
        'joined_at',
        'left_at',
        'duration_seconds',
        'reconnect_count',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'duration_seconds' => 'integer',
            'reconnect_count' => 'integer',
        ];
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
