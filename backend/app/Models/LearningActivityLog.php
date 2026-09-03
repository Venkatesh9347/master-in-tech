<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningActivityLog extends Model
{
    protected $table = 'learning_activity_logs';

    protected $fillable = [
        'user_id',
        'course_id',
        'lesson_id',
        'event_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
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
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Helper to log learning activity transactionally.
     */
    public static function logEvent(int $userId, int $courseId, ?int $lessonId, string $eventType, array $metadata = []): self
    {
        return self::create([
            'user_id' => $userId,
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
            'event_type' => $eventType,
            'metadata' => $metadata,
        ]);
    }
}
