<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsage extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'operation',
        'user_id',
        'request_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost',
        'currency',
        'latency_ms',
        'success',
        'error_code',
        'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'estimated_cost' => 'decimal:6',
            'success' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
