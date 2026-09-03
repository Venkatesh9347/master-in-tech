<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlacementOpportunity extends Model
{
    use HasFactory;

    protected $table = 'placement_opportunities';

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id',
        'title',
        'company_name',
        'company_logo',
        'job_code',
        'location',
        'employment_type',
        'work_mode',
        'salary_package',
        'experience_required',
        'eligibility',
        'minimum_qualification',
        'eligible_courses',
        'eligible_batches',
        'skills_required',
        'preferred_skills',
        'description',
        'selection_process',
        'additional_requirements',
        'openings_count',
        'deadline_date',
        'status',
        'is_featured',
        'created_by',
    ];

    protected $casts = [
        'eligible_courses' => 'array',
        'eligible_batches' => 'array',
        'skills_required' => 'array',
        'preferred_skills' => 'array',
        'deadline_date' => 'date',
        'is_featured' => 'boolean',
        'openings_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PlacementApplication::class, 'placement_opportunity_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(PlacementInterview::class, 'placement_opportunity_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('job_code', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%")
                ->orWhere('skills_required', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('deadline_date')
                    ->orWhere('deadline_date', '>=', Carbon::today());
            });
    }
}
