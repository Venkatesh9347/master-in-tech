<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoAsset extends Model
{
    use HasFactory;

    protected $table = 'video_assets';

    protected $fillable = [
        'lesson_id',
        'course_id',
        'title',
        'driver',
        'asset_id',
        'playback_id',
        'status',
        'duration_seconds',
        'resolutions',
        'encryption_key_hash',
        'storage_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'resolutions' => 'array',
            'metadata' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function playbackSessions(): HasMany
    {
        return $this->hasMany(VideoPlaybackSession::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
