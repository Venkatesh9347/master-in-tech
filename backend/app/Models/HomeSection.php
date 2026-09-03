<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HomeSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_key',
        'title',
        'subtitle',
        'badge',
        'content',
        'is_enabled',
        'sort_order',
    ];

    protected $casts = [
        'content' => 'array',
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];
}
