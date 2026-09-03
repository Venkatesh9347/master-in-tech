<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacementInterview extends Model
{
    use HasFactory;

    protected $table = 'placement_interviews';

    public const TYPE_ONLINE = 'online';
    public const TYPE_OFFLINE = 'offline';
    public const TYPE_PHONE = 'phone';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const RECOMMENDATION_SELECT = 'select';
    public const RECOMMENDATION_REJECT = 'reject';
    public const RECOMMENDATION_FURTHER_ROUND = 'further_round';

    protected $fillable = [
        'placement_application_id',
        'placement_opportunity_id',
        'company_id',
        'candidate_id',
        'interview_date',
        'interview_type',
        'meeting_link',
        'location',
        'instructions',
        'status',
        'technical_score',
        'communication_score',
        'overall_score',
        'feedback',
        'recommendation',
        'interviewer_notes',
        'conducted_by',
    ];

    protected $casts = [
        'interview_date' => 'datetime',
        'technical_score' => 'integer',
        'communication_score' => 'integer',
        'overall_score' => 'integer',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(PlacementApplication::class, 'placement_application_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PlacementOpportunity::class, 'placement_opportunity_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForCandidate(Builder $query, int $candidateId): Builder
    {
        return $query->where('candidate_id', $candidateId);
    }
}
