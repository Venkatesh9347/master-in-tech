<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchStudent extends Model
{
    use HasFactory;

    protected $table = 'batch_students';

    protected $fillable = [
        'batch_id',
        'user_id',
        'status', // active, transferred, discontinued, completed
        'joined_at',
        'left_at',
        'discontinued_at',
        'discontinuation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'discontinued_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTransferred(): bool
    {
        return $this->status === 'transferred';
    }

    public function isDiscontinued(): bool
    {
        return $this->status === 'discontinued';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
