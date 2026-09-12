<?php

namespace App\Services\Crm;

use App\Models\User;

/**
 * B3 CRM financial-truth guard (shared by AdminCrmController + legacy EnquiryController).
 *
 * Scoped CRM staff (counsellor/telecaller/course_advisor) may work the pipeline
 * but can never create or alter financial state. Financial keys are rejected
 * regardless of activity_type so amounts cannot be smuggled inside note/call
 * metadata.
 */
class CrmFinanceGuard
{
    /**
     * Metadata keys treated as financial state. Never trust these from scoped staff.
     *
     * @return string[]
     */
    public static function financialMetadataKeys(): array
    {
        return [
            'amount',
            'amount_paid',
            'payment_status',
            'payment_mode',
            'mode',
            'transaction_id',
            'payment_id',
            'gateway',
            'receipt',
            'order_id',
        ];
    }

    /**
     * Top-level lead fields treated as financial state.
     *
     * @return string[]
     */
    public static function financialLeadKeys(): array
    {
        return ['amount_paid', 'payment_status'];
    }

    public static function isScoped(?User $user): bool
    {
        return $user !== null && $user->hasScopedCrmAccess();
    }

    /**
     * True when arbitrary metadata contains any financial key (case-insensitive).
     */
    public static function metadataContainsFinancial(mixed $metadata): bool
    {
        if (! is_array($metadata)) {
            return false;
        }

        $forbidden = array_map('strtolower', self::financialMetadataKeys());

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $forbidden, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Abort 403 when a scoped user submits financial metadata.
     * Call BEFORE any CrmActivity::create().
     */
    public static function denyUnlessMetadataAllowed(?User $user, mixed $metadata): void
    {
        if (self::isScoped($user) && self::metadataContainsFinancial($metadata)) {
            abort(403, 'Only administrators can record payment information.');
        }
    }

    /**
     * Abort 403 when a scoped user submits financial lead fields or a
     * payment_event carrying an amount. Call BEFORE persistence.
     */
    public static function denyUnlessLeadFinanceAllowed(?User $user, array $validated): void
    {
        if (! self::isScoped($user)) {
            return;
        }

        foreach (self::financialLeadKeys() as $key) {
            if (array_key_exists($key, $validated) && $validated[$key] !== null) {
                abort(403, 'Only administrators can record payment amounts or status.');
            }
        }

        if (($validated['activity_type'] ?? null) === 'payment_event'
            && isset($validated['metadata']['amount'])) {
            abort(403, 'Only administrators can record payment events with amounts.');
        }

        self::denyUnlessMetadataAllowed($user, $validated['metadata'] ?? null);
    }

    /**
     * Strict admin amount validation. No negatives, no unbounded values,
     * no arbitrary strings. Max 10M (1,00,00,000) per single activity.
     */
    public static function validateAdminAmount(mixed $amount): float
    {
        if (! is_numeric($amount)) {
            abort(422, 'Payment amount must be numeric.');
        }

        $value = (float) $amount;

        if (! is_finite($value) || $value < 0 || $value > 10000000) {
            abort(422, 'Payment amount is out of range.');
        }

        return round($value, 2);
    }
}
