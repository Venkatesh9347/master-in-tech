<?php

namespace App\Services;

use App\Models\User;

/**
 * Explicit CRM capability matrix.
 *
 * Phase 0 found that counsellor, telecaller and course_advisor share a single
 * undifferentiated authorization boundary: every check in AdminCrmController,
 * CallRecordingController and EnquiryController is written against
 * User::hasScopedCrmAccess(), which is true for all three. No existing
 * business rule distinguishes them.
 *
 * This class therefore DOCUMENTS that reality rather than inventing new
 * restrictions. Every capability below was read out of the controllers:
 *
 *   AdminCrmController
 *     L493  scoped staff cannot delete leads
 *     L647  scoped staff may only assign follow-ups to themselves
 *     L705  scoped staff cannot open a lead outside their scope
 *     L710  scoped staff may only update their own follow-ups
 *     L1115 scoped staff may only claim unassigned leads
 *     L1136 finance/payment writes are admin-only
 *     EnquiryController L286 only admins may confirm admission
 *   CallRecordingController
 *     L77   scoped staff cannot attribute a recording to another user
 *     L122+ scoped staff cannot open another user's recording
 *     L147  scoped staff may only assign calls to themselves
 *     L234  scoped staff cannot delete recordings
 *
 * Introducing a per-role restriction that the codebase does not currently
 * enforce would remove access that users have today, so the three frontline
 * roles intentionally share one row. They remain DISTINCT ROLES (distinct
 * accounts, distinct dashboards, distinct routing) and are separated at the
 * frontend information-architecture layer, not by removing backend access.
 *
 * placement_advisor is intentionally absent: placement operational authority
 * does not include unrestricted CRM administration.
 */
final class CrmCapabilities
{
    // Capability identifiers. Kept as constants so the matrix, the tests and
    // the controllers all reference the same strings.
    public const READ_LEADS = 'crm.read_leads';
    public const WRITE_LEADS = 'crm.write_leads';
    public const DELETE_LEADS = 'crm.delete_leads';
    public const REASSIGN_LEADS = 'crm.reassign_leads';
    public const CLAIM_LEADS = 'crm.claim_leads';
    public const CONVERT_LEADS = 'crm.convert_leads';

    public const READ_FOLLOW_UPS = 'crm.read_follow_ups';
    public const WRITE_OWN_FOLLOW_UPS = 'crm.write_own_follow_ups';
    public const WRITE_ANY_FOLLOW_UP = 'crm.write_any_follow_up';

    public const READ_CALL_RECORDINGS = 'crm.read_call_recordings';
    public const WRITE_CALL_RECORDINGS = 'crm.write_call_recordings';
    public const DELETE_CALL_RECORDINGS = 'crm.delete_call_recordings';

    public const WRITE_FINANCE = 'crm.write_finance';
    public const CONFIRM_ADMISSION = 'crm.confirm_admission';

    /**
     * Administrative capability set: unrestricted pipeline access.
     *
     * @return list<string>
     */
    public static function adminCapabilities(): array
    {
        return [
            self::READ_LEADS,
            self::WRITE_LEADS,
            self::DELETE_LEADS,
            self::REASSIGN_LEADS,
            self::CLAIM_LEADS,
            self::CONVERT_LEADS,
            self::READ_FOLLOW_UPS,
            self::WRITE_OWN_FOLLOW_UPS,
            self::WRITE_ANY_FOLLOW_UP,
            self::READ_CALL_RECORDINGS,
            self::WRITE_CALL_RECORDINGS,
            self::DELETE_CALL_RECORDINGS,
            self::WRITE_FINANCE,
            self::CONFIRM_ADMISSION,
        ];
    }

    /**
     * Record-scoped capability set: own + unassigned leads, no deletions, no
     * finance, no admission confirmation, no third-party attribution.
     *
     * @return list<string>
     */
    public static function scopedCapabilities(): array
    {
        return [
            self::READ_LEADS,
            self::WRITE_LEADS,
            self::CLAIM_LEADS,
            self::CONVERT_LEADS,
            self::READ_FOLLOW_UPS,
            self::WRITE_OWN_FOLLOW_UPS,
            self::READ_CALL_RECORDINGS,
            self::WRITE_CALL_RECORDINGS,
        ];
    }

    /**
     * The role -> capability matrix.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $admin = self::adminCapabilities();
        $scoped = self::scopedCapabilities();

        return [
            'super_admin' => $admin,
            'admin' => $admin,
            // Not differentiated: see class docblock. Distinct roles, one
            // existing authorization tier.
            'counsellor' => $scoped,
            'telecaller' => $scoped,
            'course_advisor' => $scoped,
        ];
    }

    /**
     * Capabilities the user currently holds.
     *
     * @return list<string>
     */
    public static function capabilitiesFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        // Gate on the real middleware predicate first, so the matrix can never
        // hand out a capability to a role that canAccessCrm() rejects.
        if (! $user->canAccessCrm()) {
            return [];
        }

        return self::matrix()[$user->role] ?? [];
    }

    public static function allows(?User $user, string $capability): bool
    {
        return in_array($capability, self::capabilitiesFor($user), true);
    }

    /**
     * Guard helper for controllers.
     */
    public static function authorize(?User $user, string $capability): void
    {
        if (! self::allows($user, $capability)) {
            abort(403, 'Your role does not permit this CRM operation.');
        }
    }

    /**
     * Human-readable matrix for documentation and admin surfaces.
     *
     * @return array<string, array<string, bool>>
     */
    public static function report(): array
    {
        $out = [];

        foreach (self::matrix() as $role => $capabilities) {
            foreach (self::adminCapabilities() as $capability) {
                $out[$role][$capability] = in_array($capability, $capabilities, true);
            }
        }

        return $out;
    }
}