<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MockInterviewSlot extends Model
{
    use HasFactory;

    const STATUS_AVAILABLE = 'available';
    const STATUS_BOOKED = 'booked';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'interviewer_id',
        'slot_date',
        'start_time',
        'end_time',
        'duration_minutes',
        'meeting_link',
        'platform',
        'status',
        'instructions',
        'created_by',
    ];

    protected $casts = [
        'slot_date' => 'date',
        'duration_minutes' => 'integer',
    ];

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(MockInterviewer::class, 'interviewer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function interview(): HasOne
    {
        return $this->hasOne(MockInterview::class, 'slot_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function scopeUpcoming($query)
    {
        $today = Carbon::today()->toDateString();
        $currentTime = Carbon::now()->toTimeString();

        return $query->where(function ($q) use ($today, $currentTime) {
            $q->where('slot_date', '>', $today)
                ->orWhere(function ($sub) use ($today, $currentTime) {
                    $sub->where('slot_date', '=', $today)
                        ->where('start_time', '>=', $currentTime);
                });
        });
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('slot_date', $date);
    }

    /**
     * Check if slot is in the past.
     */
    public function isPast(): bool
    {
        $slotDateTime = Carbon::parse($this->slot_date->format('Y-m-d') . ' ' . $this->start_time);
        return $slotDateTime->isPast();
    }
}
