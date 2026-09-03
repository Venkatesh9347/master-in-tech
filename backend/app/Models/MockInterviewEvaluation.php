<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MockInterviewEvaluation extends Model
{
    use HasFactory;

    const REC_READY_FOR_PLACEMENT = 'Ready for Placement';
    const REC_NEEDS_IMPROVEMENT = 'Needs Improvement';
    const REC_REINTERVIEW_REQUIRED = 'Re-interview Required';

    protected $fillable = [
        'mock_interview_id',
        'interviewer_id',
        'evaluated_by',
        'student_id',
        'technical_knowledge',
        'programming_problem_solving',
        'communication',
        'confidence',
        'project_knowledge',
        'interview_readiness',
        'overall_rating',
        'strengths',
        'areas_for_improvement',
        'interviewer_remarks',
        'recommendation',
        'is_published_to_student',
        'evaluated_at',
    ];

    protected $casts = [
        'technical_knowledge' => 'integer',
        'programming_problem_solving' => 'integer',
        'communication' => 'integer',
        'confidence' => 'integer',
        'project_knowledge' => 'integer',
        'interview_readiness' => 'integer',
        'overall_rating' => 'float',
        'is_published_to_student' => 'boolean',
        'evaluated_at' => 'datetime',
    ];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(MockInterview::class, 'mock_interview_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(MockInterviewer::class, 'interviewer_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function isReadyForPlacement(): bool
    {
        return $this->recommendation === self::REC_READY_FOR_PLACEMENT;
    }
}
