<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'provider',
        'order_id',
        'payment_id',
        'idempotency_key',
        'user_id',
        'course_id',
        'enrollment_id',
        'amount_paise',
        'currency',
        'status',
        'description',
        'metadata',
        'paid_at',
    ];

    protected $casts = [
        'amount_paise' => 'integer',
        'metadata' => 'array',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'enrollment_id');
    }

    public static function newIdempotencyKey(): string
    {
        return 'mit_' . now()->format('Ymd_His') . '_' . Str::random(24);
    }
}
