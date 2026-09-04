<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount_paise' => 'integer',
        'metadata' => 'array',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function newIdempotencyKey(): string
    {
        return 'mit_' . now()->format('Ymd_His') . '_' . Str::random(24);
    }
}
