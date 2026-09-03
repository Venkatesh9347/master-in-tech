<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MockInterview extends Model
{
    use HasFactory;

    const STATUS_BOOKED = 'booked';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_RESCHEDULED = 'rescheduled';
    const STATUS_NO_SHOW = 'no_show';

    protected $fillable = [
        'booking_code',
        'student_id',
        'slot_id',
        'interviewer_id',
        'course_id',
        'batch_id',
        'scheduled_at',
        'status',
        'student_notes',
        'admin_notes',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'rescheduled_from_id',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(MockInterviewSlot::class, 'slot_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(MockInterviewer::class, 'interviewer_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(MockInterviewEvaluation::class, 'mock_interview_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(MockInterview::class, 'rescheduled_from_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_BOOKED, self::STATUS_CONFIRMED, self::STATUS_RESCHEDULED]);
    }

    public function scopeForStudent($query, $studentId)
    {
        return $query->where('student_id', $studentId);
    }
}
