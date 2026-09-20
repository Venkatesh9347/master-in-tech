<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD = 'dead';

    /**
     * Maximum delivery attempts (1 initial + 4 retries).
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Backoff delay in seconds after failed attempt N (1-based).
     *
     * @var list<int>
     */
    public const RETRY_BACKOFF_SECONDS = [60, 300, 900, 3600];

    protected $fillable = [
        'webhook_subscription_id',
        'event',
        'payload',
        'status',
        'attempts',
        'next_retry_at',
        'delivered_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_DEAD], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_DEAD], true);
    }
}
