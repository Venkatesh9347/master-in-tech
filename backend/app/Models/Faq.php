<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'question',
        'answer',
        'display_order',
        'is_published',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_published' => 'boolean',
    ];
}
