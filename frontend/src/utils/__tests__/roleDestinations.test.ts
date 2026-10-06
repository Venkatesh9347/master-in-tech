import { describe, it, expect } from 'vitest';
import {
  destinationForRole,
  isCrmDeskRole,
  CRM_ROLE_DESKS,
  CRM_ROLES,
} from '../roleDestinations';

/**
 * Phase 13 — frontend routing, single source of truth.
 *
 * This module replaced four duplicated copies of the role -> destination rules
 * (Login, AdminRoute, StudentRoute, PlacementRoute, CompanyRoute, CounsellorRoute).
 * The browser smoke test caught the drift that duplication caused: after Phases
 * 6/7/8 built the per-role desks, login still sent all three CRM roles to the
 * shared /admin/crm page.
 *
 * These assertions are ROUTING, not authorization. A wrong destination cannot
 * grant access to a backend route.
 */

const ALL_ROLES = [
  'super_admin',
  'admin',
  'tutor',
  'faculty',
  'student',
  'telecaller',
  'counsellor',
  'course_advisor',
  'placement_advisor',
  'company',
  'recruiter',
  'instructor',
];

describe('roleDestinations', () => {
  describe('destinationForRole', () => {
    it('maps every enforced role to its own destination', () => {
      expect(destinationForRole('super_admin')).toBe('/admin');
      expect(destinationForRole('admin')).toBe('/admin');
      expect(destinationForRole('tutor')).toBe('/tutor');
      expect(destinationForRole('faculty')).toBe('/tutor');
      expect(destinationForRole('student')).toBe('/student');
      expect(destinationForRole('placement_advisor')).toBe('/placement');
      expect(destinationForRole('company')).toBe('/company');
      expect(destinationForRole('recruiter')).toBe('/company');
    });

    it('gives each CRM role a DISTINCT desk (Phases 6, 7, 8)', () => {
      expect(destinationForRole('telecaller')).toBe('/crm/telecaller');
      expect(destinationForRole('counsellor')).toBe('/crm/counsellor');
      expect(destinationForRole('course_advisor')).toBe('/crm/course-advisor');

      const desks = CRM_ROLES.map((r) => destinationForRole(r));
      expect(new Set(desks).size).toBe(CRM_ROLES.length);
      // None of them may still land on the shared admin CRM page.
      for (const desk of desks) {
        expect(desk).not.toBe('/admin/crm');
      }
    });

    it('routes placement_advisor to /placement, never to /student (loop guard)', () => {
      // Sending it to /student makes StudentRoute reject it and the two bounce.
      expect(destinationForRole('placement_advisor')).not.toBe('/student');
    });

    it('leaves instructor RESERVED — no portal is provisioned for it', () => {
      expect(destinationForRole('instructor')).toBe('/student');
      expect(CRM_ROLE_DESKS).not.toHaveProperty('instructor');
      expect(isCrmDeskRole('instructor')).toBe(false);
    });

    it('falls back safely for missing or unknown roles', () => {
      expect(destinationForRole(undefined)).toBe('/student');
      expect(destinationForRole(null)).toBe('/student');
      expect(destinationForRole('')).toBe('/student');
      expect(destinationForRole('not_a_role')).toBe('/student');
    });

    it('never sends any role to /login — that is the redirect-loop trap', () => {
      for (const role of ALL_ROLES) {
        expect(destinationForRole(role), role).not.toBe('/login');
      }
      expect(destinationForRole(undefined)).not.toBe('/login');
    });

    it('never returns a bare relative path', () => {
      for (const role of ALL_ROLES) {
        expect(destinationForRole(role), role).toMatch(/^\/[a-z/-]+$/);
      }
    });
  });

  describe('isCrmDeskRole', () => {
    it('recognises exactly the three frontline CRM roles', () => {
      expect(isCrmDeskRole('telecaller')).toBe(true);
      expect(isCrmDeskRole('counsellor')).toBe(true);
      expect(isCrmDeskRole('course_advisor')).toBe(true);

      for (const role of ALL_ROLES.filter((r) => !CRM_ROLES.includes(r))) {
        expect(isCrmDeskRole(role), role).toBe(false);
      }
      expect(isCrmDeskRole(undefined)).toBe(false);
    });
  });
});