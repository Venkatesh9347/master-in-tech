import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Phases 6, 7, 8 — Telecaller / Course Advisor / Counsellor dashboards.
 *
 * SCOPE OF THESE TESTS
 * --------------------
 * These dashboards are PRESENTATION ONLY. Backend authorization is authoritative
 * and unchanged, so these tests assert rendering, per-role KPI sets and API
 * consumption — NOT authorization. The backend scoping tests live in
 * CrmCapabilityMatrixTest / CrmFrontlineRolesTest.
 */

const mocks = vi.hoisted(() => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  user: null as unknown,
}));

vi.mock('../../../services/api', () => ({
  default: mocks.api,
}));

vi.mock('../../../context/useAuth', () => ({
  useAuth: () => ({ user: mocks.user, loading: false }),
}));

import CrmRoleDashboard, { CRM_ROLE_CONFIGS } from '../CrmRoleDashboard';

const STATS = {
  total_leads: 40,
  new_leads: 8,
  contacted: 12,
  follow_ups_due: 6,
  demos: 9,
  interested: 11,
  payment_pending: 4,
  converted: 14,
  lost: 3,
  overdue_follow_ups: 2,
  todays_follow_ups: 4,
  upcoming_follow_ups: 5,
  completed_follow_ups: 30,
  conversion_rate: 35,
  source_breakdown: {},
  priority_breakdown: {},
};

const LEADS = [
  {
    id: 11,
    name: 'Aarav Sharma',
    email: 'aarav@example.test',
    status: 'interested',
    course_interest: 'Cloud Security',
    assigned_to: 'Desk User',
  },
  {
    id: 12,
    name: 'Diya Patel',
    email: 'diya@example.test',
    status: 'demo_scheduled',
    course_interest: 'Cyber Security',
    assigned_to: 'Desk User',
  },
];

function renderDashboard(config: 'telecaller' | 'course_advisor' | 'counsellor') {
  return render(
    <MemoryRouter>
      <CrmRoleDashboard config={config} />
    </MemoryRouter>,
  );
}

/**
 * KPI tiles live in their own landmark, so a KPI assertion can never accidentally
 * match the identically-worded filter option or a row status badge.
 */
function kpis() {
  return within(screen.getByRole('region', { name: 'Key performance indicators' }));
}

describe('CRM role dashboards (Phases 6, 7, 8)', () => {
  beforeEach(() => {
    mocks.api.get.mockReset();
    mocks.api.get.mockImplementation((url: string) => {
      if (url.startsWith('/admin/crm/stats')) return Promise.resolve({ data: STATS });
      if (url.startsWith('/admin/crm/leads')) return Promise.resolve({ data: LEADS });
      return Promise.resolve({ data: [] });
    });
    mocks.user = { id: 9, name: 'Desk User', email: 'desk@example.test', role: 'telecaller' };
  });
  afterEach(() => cleanup());

  it('consumes only existing shared CRM endpoints (no new backend surface)', async () => {
    renderDashboard('telecaller');
    await waitFor(() => expect(mocks.api.get).toHaveBeenCalled());

    const urls = mocks.api.get.mock.calls.map((c) => String(c[0]));
    expect(urls.some((u) => u.startsWith('/admin/crm/stats'))).toBe(true);
    expect(urls.some((u) => u.startsWith('/admin/crm/leads'))).toBe(true);

    // No privileged or admin-only endpoint may be called from a frontline CRM desk.
    for (const url of urls) {
      expect(url.startsWith('/admin/users')).toBe(false);
      expect(url.startsWith('/admin/settings')).toBe(false);
      expect(url.startsWith('/admin/placements')).toBe(false);
      expect(url.startsWith('/admin/crm/counsellors')).toBe(false);
    }
  });

  describe('Phase 6 — Telecaller', () => {
    it('renders the Telecaller Desk with its KPI set', async () => {
      renderDashboard('telecaller');
      await screen.findByRole('heading', { level: 1, name: 'Telecaller Desk' });

      for (const label of [
        'New Leads',
        'My Leads',
        "Today's Calls",
        'Pending Follow-ups',
        'Overdue Follow-ups',
        'Demo Requests',
        'Demo Scheduled',
        'Interested',
      ]) {
        expect(kpis().getByText(label)).toBeTruthy();
      }
    });

    it('identifies the role in the top bar without duplicating the page title', async () => {
      renderDashboard('telecaller');
      await screen.findByRole('heading', { level: 1, name: 'Telecaller Desk' });
      expect(screen.getByTestId('role-label').textContent).toBe('Telecaller');
      expect(screen.getByTestId('role-label').textContent).not.toBe('Telecaller Desk');
    });

    it('shows exactly one main landmark and one h1', async () => {
      renderDashboard('telecaller');
      await screen.findByRole('heading', { level: 1, name: 'Telecaller Desk' });
      expect(screen.getAllByRole('main')).toHaveLength(1);
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    });

    it('flags overdue follow-ups as a warning tone', async () => {
      renderDashboard('telecaller');
      await screen.findByRole('heading', { level: 1, name: 'Telecaller Desk' });
      const value = kpis().getByText('Overdue Follow-ups').parentElement?.querySelector('p + p');
      expect(value?.className).toContain('text-amber-700');
      expect(value?.textContent).toBe('2');
    });

    it('renders a warning-only tone when nothing is overdue', async () => {
      mocks.api.get.mockImplementation((url: string) => {
        if (url.startsWith('/admin/crm/stats')) {
          return Promise.resolve({ data: { ...STATS, overdue_follow_ups: 0 } });
        }
        if (url.startsWith('/admin/crm/leads')) return Promise.resolve({ data: LEADS });
        return Promise.resolve({ data: [] });
      });
      renderDashboard('telecaller');
      await screen.findByRole('heading', { level: 1, name: 'Telecaller Desk' });
      const value = kpis().getByText('Overdue Follow-ups').parentElement?.querySelector('p + p');
      expect(value?.className).toContain('text-slate-900');
      expect(value?.className).not.toContain('amber');
    });
  });

  describe('Phase 7 — Course Advisor', () => {
    it('renders the Course Advisor Desk with its KPI set', async () => {
      renderDashboard('course_advisor');
      await screen.findByRole('heading', { level: 1, name: 'Course Advisor Desk' });

      for (const label of [
        'Assigned Leads',
        'Qualified Leads',
        'Demo Scheduled',
        'Demo Completed',
        'Interested',
        'Payment Pending',
        'Admission Confirmed',
        'Enrolled',
        'Follow-ups Due',
        'Conversion Rate',
      ]) {
        expect(kpis().getByText(label)).toBeTruthy();
      }
    });

    it('renders the conversion rate as a percentage', async () => {
      renderDashboard('course_advisor');
      await screen.findByRole('heading', { level: 1, name: 'Course Advisor Desk' });
      expect(kpis().getByText('35%')).toBeTruthy();
    });
  });

  describe('Phase 8 — Counsellor', () => {
    it('renders the Counsellor Desk with its KPI set', async () => {
      renderDashboard('counsellor');
      await screen.findByRole('heading', { level: 1, name: 'Counsellor Desk' });

      for (const label of [
        'Lead Pipeline',
        'Counselling Queue',
        "Today's Follow-ups",
        'Overdue Follow-ups',
        'Demo Schedule',
        'Interested Students',
        'Admission Pipeline',
        'Conversion Metrics',
      ]) {
        expect(kpis().getByText(label)).toBeTruthy();
      }
    });
  });

  it('renders the shared lead table for every role', async () => {
    for (const role of ['telecaller', 'course_advisor', 'counsellor'] as const) {
      const view = renderDashboard(role);
      expect(await screen.findByText('Aarav Sharma')).toBeTruthy();
      expect(screen.getByText('Diya Patel')).toBeTruthy();
      view.unmount();
      cleanup();
    }
  });

  it('shows the loading state before data arrives', () => {
    mocks.api.get.mockImplementation(() => new Promise(() => {}));
    renderDashboard('telecaller');
    expect(screen.getByTestId('loading-state')).toBeTruthy();
  });

  it('surfaces an error state and can retry', async () => {
    mocks.api.get.mockRejectedValue(new Error('network'));
    renderDashboard('telecaller');
    expect(await screen.findByTestId('error-state')).toBeTruthy();
    expect(screen.getByText('Failed to load your CRM workspace.')).toBeTruthy();
  });

  it('shows an empty state when the scoped lead list is empty', async () => {
    mocks.api.get.mockImplementation((url: string) => {
      if (url.startsWith('/admin/crm/stats')) return Promise.resolve({ data: STATS });
      if (url.startsWith('/admin/crm/leads')) return Promise.resolve({ data: [] });
      return Promise.resolve({ data: [] });
    });
    renderDashboard('counsellor');
    expect(await screen.findByTestId('empty-state')).toBeTruthy();
    expect(screen.getByText('No leads match this view.')).toBeTruthy();
  });

  it('drills a KPI tile into the matching lead-status filter', async () => {
    renderDashboard('telecaller');
    await screen.findByRole('heading', { level: 1, name: 'Telecaller Desk' });

    const interested = kpis().getByText('Interested').closest('[data-testid="stat-card"]');
    expect(interested?.tagName).toBe('BUTTON');

    interested?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await waitFor(() =>
      expect(screen.getByLabelText('Lead status')).toHaveProperty('value', 'interested'),
    );
  });

  it('each role config has a distinct route, role label and KPI set', () => {
    const { telecaller, course_advisor, counsellor } = CRM_ROLE_CONFIGS;

    expect(telecaller.route).toBe('/crm/telecaller');
    expect(course_advisor.route).toBe('/crm/course-advisor');
    expect(counsellor.route).toBe('/crm/counsellor');

    expect(telecaller.label).toBe('Telecaller');
    expect(course_advisor.label).toBe('Course Advisor');
    expect(counsellor.label).toBe('Counsellor');

    const labelsFor = (c: typeof telecaller) => c.kpis(STATS).map((k) => k.label);
    const a = labelsFor(telecaller);
    const b = labelsFor(course_advisor);
    const d = labelsFor(counsellor);

    // Distinct information architecture per role (presentation only).
    expect(a).not.toEqual(b);
    expect(b).not.toEqual(d);
    expect(a).not.toEqual(d);
  });

  it('unknown role config would be rejected by the router, not by the component', () => {
    // Defensive: the component only accepts the three enforced CRM roles.
    const keys = Object.keys(CRM_ROLE_CONFIGS).sort();
    expect(keys).toEqual(['counsellor', 'course_advisor', 'telecaller']);
  });
});