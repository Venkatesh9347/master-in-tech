<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Enquiry extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_DEMO_SCHEDULED = 'demo_scheduled';
    public const STATUS_DEMO_COMPLETED = 'demo_completed';
    public const STATUS_INTERESTED = 'interested';
    public const STATUS_FOLLOW_UP = 'follow_up';
    public const STATUS_PAYMENT_PENDING = 'payment_pending';
    public const STATUS_ADMISSION_CONFIRMED = 'admission_confirmed';
    public const STATUS_ENROLLED = 'enrolled';
    public const STATUS_CONVERTED = 'converted'; // Alias for enrolled in CRM
    public const STATUS_NOT_INTERESTED = 'not_interested';
    public const STATUS_LOST = 'lost'; // Alias for not_interested/closed in CRM
    public const STATUS_NO_RESPONSE = 'no_response';
    public const STATUS_CLOSED = 'closed';

    public const PIPELINE_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_DEMO_SCHEDULED,
        self::STATUS_DEMO_COMPLETED,
        self::STATUS_INTERESTED,
        self::STATUS_FOLLOW_UP,
        self::STATUS_PAYMENT_PENDING,
        self::STATUS_ADMISSION_CONFIRMED,
        self::STATUS_ENROLLED,
        self::STATUS_CONVERTED,
        self::STATUS_NOT_INTERESTED,
        self::STATUS_LOST,
        self::STATUS_NO_RESPONSE,
        self::STATUS_CLOSED,
    ];

    public const SOURCES = [
        'website',
        'google_ads',
        'social_media',
        'referral',
        'direct_call',
        'event',
        'walk_in',
        'email_campaign',
        'other',
    ];

    public const PRIORITIES = [
        'hot',
        'warm',
        'cold',
        'high',
        'medium',
        'low',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'source',
        'priority',
        'course_id',
        'course_title',
        'preferred_time',
        'message',
        'qualification',
        'experience_level',
        'city',
        'status',
        'demo_date',
        'demo_time',
        'demo_outcome',
        'assigned_agent',
        'assigned_counsellor_id',
        'next_follow_up_date',
        'next_follow_up_time',
        'expected_revenue',
        'amount_paid',
        'payment_status',
        'lost_reason',
        'enrolled_user_id',
        'enrolled_at',
    ];

    protected $casts = [
        'demo_date' => 'datetime',
        'next_follow_up_date' => 'date',
        'enrolled_at' => 'datetime',
        'expected_revenue' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function enrolledUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_user_id');
    }

    public function assignedCounsellor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_counsellor_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(EnquiryNote::class)->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'enquiry_id')->latest();
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(CrmFollowUp::class, 'enquiry_id')->orderBy('scheduled_at', 'asc');
    }

    public function latestFollowUp(): HasOne
    {
        return $this->hasOne(CrmFollowUp::class, 'enquiry_id')->latestOfMany('scheduled_at');
    }

    /**
     * Search scope across lead name, email, phone, course, and city.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('course_title', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('assigned_agent', 'like', "%{$search}%")
                ->orWhereHas('course', function ($cq) use ($search) {
                    $cq->where('title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                })
                ->orWhereHas('assignedCounsellor', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        });
    }

    public function scopeForStatus(Builder $query, ?string $status): Builder
    {
        if ($status && $status !== 'all') {
            if ($status === 'converted') {
                return $query->whereIn('status', ['converted', 'enrolled']);
            }
            if ($status === 'lost') {
                return $query->whereIn('status', ['lost', 'not_interested', 'closed']);
            }
            return $query->where('status', $status);
        }
        return $query;
    }

    public function scopeForPriority(Builder $query, ?string $priority): Builder
    {
        if ($priority && $priority !== 'all') {
            return $query->where('priority', $priority);
        }
        return $query;
    }

    public function scopeForSource(Builder $query, ?string $source): Builder
    {
        if ($source && $source !== 'all') {
            return $query->where('source', $source);
        }
        return $query;
    }

    public function scopeForCounsellor(Builder $query, ?int $counsellorId): Builder
    {
        if ($counsellorId) {
            return $query->where('assigned_counsellor_id', $counsellorId);
        }
        return $query;
    }

    /**
     * Record-level visibility: scoped CRM staff (counsellor, telecaller,
     * course_advisor) see only leads assigned to them plus unassigned leads
     * awaiting pickup. Admins/super_admin see the full pipeline.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->hasScopedCrmAccess()) {
            return $query->where(function ($w) use ($user) {
                $w->where('assigned_counsellor_id', $user->id)
                    ->orWhereNull('assigned_counsellor_id');
            });
        }
        return $query;
    }

    /**
     * Single-record counterpart of scopeVisibleTo for bound models.
     */
    public function isVisibleTo(?User $user): bool
    {
        if (! $user || ! $user->hasScopedCrmAccess()) {
            return true;
        }
        return $this->assigned_counsellor_id === null
            || (int) $this->assigned_counsellor_id === (int) $user->id;
    }

    public function scopeDueFollowUps(Builder $query): Builder
    {
        return $query->whereNotNull('next_follow_up_date')
            ->whereDate('next_follow_up_date', '<=', today())
            ->whereNotIn('status', [self::STATUS_ENROLLED, self::STATUS_CONVERTED, self::STATUS_LOST, self::STATUS_CLOSED]);
    }
}
