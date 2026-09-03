<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomMessage extends Model
{
    use HasFactory;

    protected $table = 'classroom_messages';

    protected $fillable = [
        'class_session_id',
        'live_classroom_session_id',
        'user_id',
        'message',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
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
