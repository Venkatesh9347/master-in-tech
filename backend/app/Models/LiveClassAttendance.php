<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveClassAttendance extends Model
{
    use HasFactory;

    protected $table = 'live_class_attendances';

    protected $fillable = [
        'live_class_id',
        'user_id',
        'course_id',
        'joined_at',
        'left_at',
        'duration_seconds',
        'status',
        'is_hand_raised',
        'hand_raised_at',
        'is_mic_allowed',
        'is_muted',
        'is_removed',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'hand_raised_at' => 'datetime',
            'duration_seconds' => 'integer',
            'is_hand_raised' => 'boolean',
            'is_mic_allowed' => 'boolean',
            'is_muted' => 'boolean',
            'is_removed' => 'boolean',
        ];
    }

    public function liveClass(): BelongsTo
    {
        return $this->belongsTo(LiveClass::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Mark session as ended/left and recalculate total duration.
     */
    public function recordLeave(): void
    {
        $leftTime = now();
        // Carbon 3 diffInSeconds() returns a float; duration_seconds is an
        // integer column and Eloquent integer casts apply on read only, so
        // normalize explicitly (truncation matches the hardened call sites).
        $duration = $this->joined_at ? max(0, (int) $this->joined_at->diffInSeconds($leftTime)) : 0;

        $this->update([
            'left_at' => $leftTime,
            'duration_seconds' => $this->duration_seconds + $duration,
            'status' => 'left',
            'is_hand_raised' => false,
        ]);
    }
}
