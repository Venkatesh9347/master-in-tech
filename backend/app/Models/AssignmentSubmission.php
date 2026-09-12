<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentSubmission extends Model
{
    protected $fillable = [
        'user_id',
        'assignment_id',
        'lesson_id',
        'course_id',
        'submission_text',
        'file_url',
        'submitted_at',
        'is_late',
        'revision_number',
        'score',
        'feedback',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'score' => 'decimal:2',
            'is_late' => 'boolean',
            'revision_number' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AssignmentSubmissionRevision::class, 'assignment_submission_id')
            ->orderBy('revision_number');
    }
}
