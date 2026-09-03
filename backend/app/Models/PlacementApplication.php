<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacementApplication extends Model
{
    use HasFactory;

    protected $table = 'placement_applications';

    public const STATUS_APPLIED = 'applied';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_SHORTLISTED = 'shortlisted';
    public const STATUS_INTERVIEW_SCHEDULED = 'interview_scheduled';
    public const STATUS_SELECTED = 'selected';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_JOINED = 'joined';

    public const STATUSES = [
        self::STATUS_APPLIED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_SHORTLISTED,
        self::STATUS_INTERVIEW_SCHEDULED,
        self::STATUS_SELECTED,
        self::STATUS_REJECTED,
        self::STATUS_JOINED,
    ];

    protected $fillable = [
        'placement_opportunity_id',
        'user_id',
        'batch_id',
        'batch_code',
        'student_name',
        'email',
        'phone',
        'course_id',
        'course_title',
        'resume_url',
        'cover_note',
        'status',
        'interview_date',
        'interview_notes',
        'admin_notes',
        'reviewed_by',
        'applied_at',
    ];

    protected $casts = [
        'interview_date' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PlacementOpportunity::class, 'placement_opportunity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function interviews()
    {
        return $this->hasMany(PlacementInterview::class, 'placement_application_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->whereHas('opportunity', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        });
    }

    public function scopeForStudent(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForStatus(Builder $query, ?string $status): Builder
    {
        if ($status && $status !== 'all') {
            return $query->where('status', $status);
        }
        return $query;
    }

    public function scopeForBatch(Builder $query, ?int $batchId): Builder
    {
        if ($batchId) {
            return $query->where('batch_id', $batchId);
        }
        return $query;
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('student_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('batch_code', 'like', "%{$search}%")
                ->orWhere('course_title', 'like', "%{$search}%")
                ->orWhereHas('opportunity', function ($oq) use ($search) {
                    $oq->where('title', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
        });
    }
}
