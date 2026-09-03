<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveClassMessage extends Model
{
    use HasFactory;

    protected $table = 'live_class_messages';

    protected $fillable = [
        'live_class_id',
        'user_id',
        'message',
        'is_announcement',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'is_announcement' => 'boolean',
            'is_pinned' => 'boolean',
        ];
    }

    public function liveClass(): BelongsTo
    {
        return $this->belongsTo(LiveClass::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
