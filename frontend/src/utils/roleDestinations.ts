/**
 * Single source of truth for "where does this role land?".
 *
 * WHY THIS EXISTS
 * ---------------
 * Login and the route guards used to each carry their own copy of the
 * role -> destination rules. Phases 6/7/8 gave telecaller, course_advisor and
 * counsellor their own desks, and the browser smoke test caught the drift: login
 * still sent all three to the shared /admin/crm page, so the new desks were
 * unreachable without typing a URL. Two copies of the same rule is exactly how
 * that drift happens, so the rule now lives in one place.
 *
 * NOT A SECURITY BOUNDARY
 * -----------------------
 * This is routing/UX only. Every route it points at is independently guarded by
 * backend middleware, and a wrong value here cannot grant access to anything.
 */

/** Frontline CRM roles, each with its own desk (Phases 6, 7, 8). */
export const CRM_ROLE_DESKS: Record<string, string> = {
  telecaller: '/crm/telecaller',
  counsellor: '/crm/counsellor',
  course_advisor: '/crm/course-advisor',
};

export const CRM_ROLES = Object.keys(CRM_ROLE_DESKS);

/**
 * The landing destination for a role after login, or when a guard rejects them.
 *
 * `instructor` is deliberately absent: it is RESERVED / UNPROVISIONED with no
 * portal, so it must fall through to the default rather than be granted a desk.
 */
export function destinationForRole(role?: string | null): string {
  switch (role) {
    case 'admin':
    case 'super_admin':
      return '/admin';
    case 'tutor':
    case 'faculty':
      return '/tutor';
    case 'company':
    case 'recruiter':
      return '/company';
    case 'placement_advisor':
      // Checked before the student fallback: sending it to /student makes
      // StudentRoute reject it and the two redirect in a loop.
      return '/placement';
    default: {
      const desk = role ? CRM_ROLE_DESKS[role] : undefined;
      if (desk) return desk;
      return '/student';
    }
  }
}

/** True when the role is one of the frontline CRM roles with a dedicated desk. */
export function isCrmDeskRole(role?: string | null): boolean {
  return Boolean(role && CRM_ROLE_DESKS[role]);
}