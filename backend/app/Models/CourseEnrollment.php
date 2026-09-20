<?php

namespace App\Models;

use App\DomainEvents\DomainEventBus;
use App\Services\WebhookDispatcherService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseEnrollment extends Model
{
    protected static function booted(): void
    {
        // Outbound Phase-9 seam: every created enrollment publishes exactly
        // one enrollment.created domain event. The registered webhook
        // consumer persists its ledger inside the surrounding transaction
        // and queues delivery after commit, so a rolled-back creation emits
        // nothing.
        static::created(function (CourseEnrollment $enrollment): void {
            DomainEventBus::record(WebhookDispatcherService::EVENT_ENROLLMENT_CREATED, [
                'enrollment_id' => (int) $enrollment->id,
                'user_id' => (int) $enrollment->user_id,
                'course_id' => (int) $enrollment->course_id,
                'status' => (string) $enrollment->status,
            ]);
        });
    }

    protected $fillable = [
        'user_id',
        'course_id',
        'enrolled_at',
        'status',
        'progress_percentage',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'progress_percentage' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
