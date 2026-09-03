<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    protected $table = 'lesson_progress';

    protected $fillable = [
        'user_id',
        'course_id',
        'lesson_id',
        'section_id',
        'status',
        'started',
        'started_at',
        'completed',
        'completed_at',
        'last_accessed_at',
        'progress_percentage',
        'current_playback_seconds',
        'duration_seconds',
        'last_playback_position',
    ];

    protected $casts = [
        'started' => 'boolean',
        'started_at' => 'datetime',
        'completed' => 'boolean',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'progress_percentage' => 'decimal:2',
        'current_playback_seconds' => 'decimal:2',
        'duration_seconds' => 'decimal:2',
        'last_playback_position' => 'decimal:2',
    ];

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
        return $this->belongsTo(Lesson::class, 'lesson_id', 'id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
