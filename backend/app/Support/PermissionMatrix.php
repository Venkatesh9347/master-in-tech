<?php

namespace App\Support;

/**
 * Single source of truth for role-based access control across the LMS.
 *
 * All route-level guards (middleware), model helpers and the resolved
 * fine-grained tutor/faculty permissions delegate to this matrix so the
 * backend and the frontend guard table stay consistent.
 */
final class PermissionMatrix
{
    /**
     * Route-level access areas. Each area maps to the roles allowed to
     * enter that area (matching the middleware aliases in bootstrap/app.php).
     *
     * @var array<string, array<int, string|null>>
     */
    public const AREAS = [
        'admin' => ['super_admin', 'admin'],
        'tutor' => ['super_admin', 'admin', 'tutor', 'faculty'],
        'content' => ['super_admin', 'admin', 'tutor', 'faculty'],
        'crm' => ['super_admin', 'admin', 'counsellor'],
        'company' => ['company', 'recruiter'],
        'student' => ['student'],
    ];

    /**
     * Default fine-grained permissions granted to tutors/faculty.
     *
     * @var array<string, bool>
     */
    public const TUTOR_ABILITIES = [
        'view_assigned_courses' => true,
        'view_students' => true,
        'upload_materials' => true,
        'manage_materials' => true,
        'create_quizzes' => false,
        'edit_quizzes' => false,
        'delete_quizzes' => false,
        'publish_quizzes' => false,
        'view_quiz_results' => true,
    ];

    /**
     * Roles treated as platform administrators (full access to staff areas).
     */
    public static function isSuperUser(?string $role): bool
    {
        return in_array($role, ['admin', 'super_admin'], true);
    }

    /**
     * Whether the given role may access a route-level area.
     */
    public static function canAccess(?string $role, string $area): bool
    {
        if (! isset(self::AREAS[$area])) {
            return false;
        }

        if (in_array($role, self::AREAS[$area], true)) {
            return true;
        }

        // Platform admins are implicitly authorised for every staff area.
        return self::isSuperUser($role) && in_array($area, ['admin', 'tutor', 'content', 'crm'], true);
    }

    /**
     * Resolve the complete fine-grained permission set for a role.
     *
     * Super users receive every capability; tutors/faculty receive the
     * default tutor matrix; all other roles receive no capabilities so the
     * resolved set exposed via /api/user is never overstated for students,
     * counsellors or corporate partners.
     *
     * @return array<string, bool>
     */
    public static function abilitiesForRole(?string $role): array
    {
        $keys = array_keys(self::TUTOR_ABILITIES);

        if (self::isSuperUser($role)) {
            return array_fill_keys($keys, true);
        }

        if (in_array($role, ['tutor', 'faculty'], true)) {
            return self::TUTOR_ABILITIES;
        }

        return array_fill_keys($keys, false);
    }
}