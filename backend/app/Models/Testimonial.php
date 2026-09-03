<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Testimonial extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_name',
        'student_photo',
        'student_role_or_company',
        'course_id',
        'course_title',
        'rating',
        'content',
        'display_order',
        'is_featured',
        'is_published',
    ];

    protected $casts = [
        'rating' => 'integer',
        'display_order' => 'integer',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
