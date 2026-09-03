<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoPlaybackSession extends Model
{
    use HasFactory;

    protected $table = 'video_playback_sessions';

    protected $fillable = [
        'video_asset_id',
        'user_id',
        'course_id',
        'lesson_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'expires_at',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function videoAsset(): BelongsTo
    {
        return $this->belongsTo(VideoAsset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }
}
