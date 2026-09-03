<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Resource extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'type',
        'tag',
        'icon',
        'url_or_file',
        'author',
        'display_order',
        'is_published',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_published' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($res) {
            if (empty($res->slug)) {
                $res->slug = Str::slug($res->title);
            }
        });
    }
}
