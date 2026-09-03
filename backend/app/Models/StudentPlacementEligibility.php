<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPlacementEligibility extends Model
{
    use HasFactory;

    const STATUS_DISABLED = 'DISABLED';
    const STATUS_ELIGIBLE = 'ELIGIBLE';
    const STATUS_ENABLED = 'ENABLED';
    const STATUS_SUSPENDED = 'SUSPENDED';

    protected $fillable = [
        'user_id',
        'course_completed',
        'mock_interview_status',
        'placement_eligible',
        'dashboard_status',
        'dashboard_status_reason',
        'dashboard_status_updated_by',
        'dashboard_status_updated_at',
        'dashboard_enabled_at',
        'dashboard_enabled_by',
        'is_admin_override',
        'override_reason',
        'override_by',
        'notes',
        'last_evaluated_at',
    ];

    protected $casts = [
        'course_completed' => 'boolean',
        'placement_eligible' => 'boolean',
        'is_admin_override' => 'boolean',
        'last_evaluated_at' => 'datetime',
        'dashboard_status_updated_at' => 'datetime',
        'dashboard_enabled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function overrideAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    public function dashboardStatusUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dashboard_status_updated_by');
    }

    public function dashboardEnabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dashboard_enabled_by');
    }

    public function isDashboardEnabled(): bool
    {
        return $this->dashboard_status === self::STATUS_ENABLED;
    }

    public function isDashboardSuspended(): bool
    {
        return $this->dashboard_status === self::STATUS_SUSPENDED;
    }

    public function isDashboardEligible(): bool
    {
        return $this->dashboard_status === self::STATUS_ELIGIBLE;
    }
}
