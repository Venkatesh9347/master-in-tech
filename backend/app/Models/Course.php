<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'code',
        'description',
        'full_description',
        'category',
        'thumbnail',
        'banner',
        'brochure',
        'media_id',
        'brochure_media_id',
        'instructor',
        'instructor_id',
        'price',
        'duration',
        'difficulty',
        'prerequisites',
        'learning_objectives',
        'skills_gained',
        'is_published',
        'status',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_published' => 'boolean',
            'priority' => 'integer',
            'media_id' => 'integer',
            'brochure_media_id' => 'integer',
            'prerequisites' => 'array',
            'learning_objectives' => 'array',
            'skills_gained' => 'array',
        ];
    }

    public function mediaAsset()
    {
        return $this->belongsTo(MediaAsset::class, 'media_id');
    }

    public function brochureMediaAsset()
    {
        return $this->belongsTo(MediaAsset::class, 'brochure_media_id');
    }

    public function instructorUser()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CourseReview::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('sort_order');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function liveClasses(): HasMany
    {
        return $this->hasMany(LiveClass::class)->orderBy('class_date', 'asc')->orderBy('start_time', 'asc');
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class)->orderBy('scheduled_date', 'asc')->orderBy('start_time', 'asc');
    }

    public function classMaterials(): HasMany
    {
        return $this->hasMany(ClassMaterial::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class)->orderBy('start_date', 'desc');
    }
}
