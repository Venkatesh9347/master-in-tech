<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lesson extends Model
{
    protected $fillable = [
        'section_id',
        'course_id',
        'title',
        'slug',
        'description',
        'duration',
        'type',
        'metadata',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(Assignment::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'lesson_id', 'id');
    }

    public function videoAsset(): HasOne
    {
        return $this->hasOne(VideoAsset::class);
    }

    /**
     * Check if this lesson is a video lesson.
     */
    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    /**
     * Check if this lesson is a quiz lesson.
     */
    public function isQuiz(): bool
    {
        return $this->type === 'quiz';
    }

    /**
     * Check if this lesson is an assignment.
     */
    public function isAssignment(): bool
    {
        return $this->type === 'assignment';
    }
}
