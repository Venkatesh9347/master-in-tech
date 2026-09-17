<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbound refund ledger.
 *
 * The single payment_transactions row cannot represent partial refunds (a
 * paid row flipped to `refunded` loses the remaining-refundable amount), so
 * every administrator-initiated or webhook-reconciled refund is recorded here
 * as its own append-only row. Remaining refundable amount for a transaction
 * is always: captured amount minus the sum of `succeeded` rows.
 */
class PaymentRefund extends Model
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_RECONCILED = 'reconciled';

    protected $fillable = [
        'payment_transaction_id',
        'provider',
        'provider_refund_id',
        'idempotency_key',
        'initiated_by',
        'amount_paise',
        'currency',
        'status',
        'source',
        'metadata',
    ];

    protected $casts = [
        'amount_paise' => 'integer',
        'metadata' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    /**
     * Safe API representation: internal ids + amounts only, never provider
     * secrets or raw gateway payloads.
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'payment_transaction_id' => $this->payment_transaction_id,
            'provider' => $this->provider,
            'provider_refund_id' => $this->provider_refund_id,
            'amount' => (int) $this->amount_paise,
            'currency' => $this->currency,
            'status' => $this->status,
            'source' => $this->source,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
