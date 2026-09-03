<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Instructor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'designation',
        'company',
        'bio',
        'avatar',
        'rating',
        'graduates_count',
        'experience_years',
        'skills',
        'social_links',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'skills' => 'array',
        'social_links' => 'array',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
