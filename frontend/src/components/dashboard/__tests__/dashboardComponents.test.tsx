import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import type { ReactElement } from 'react';

/**
 * Phase 14 — shared dashboard design system.
 *
 * These components are PRESENTATION ONLY. The tests therefore assert rendering,
 * accessibility landmarks and empty/loading/error handling — never authorization,
 * which the backend enforces independently.
 *
 * No useAuth mock is needed: no component in this module reads auth state.
 */

import {
  ActivityTimeline,
  ConfirmDialog,
  DashboardShell,
  DataTable,
  EmptyState,
  ErrorState,
  FilterBar,
  LoadingState,
  Sidebar,
  StatCard,
  StatusBadge,
  TopBar,
} from '../index';

function wrap(ui: ReactElement) {
  return render(<MemoryRouter>{ui}</MemoryRouter>);
}

describe('dashboard design system', () => {
  afterEach(() => cleanup());

  describe('StatusBadge', () => {
    it('renders the humanised status and exposes it for assertions', () => {
      wrap(<StatusBadge status="demo_scheduled" />);
      expect(screen.getByTestId('status-badge').textContent).toBe('demo scheduled');
      expect(screen.getByTestId('status-badge').dataset.status).toBe('demo_scheduled');
    });

    it('never renders an unknown status as a positive tone', () => {
      wrap(<StatusBadge status="totally_unknown_status" />);
      const badge = screen.getByTestId('status-badge');
      expect(badge.textContent).toBe('totally unknown status');
      expect(badge.className).toContain('bg-slate-100');
      expect(badge.className).not.toContain('emerald');
    });

    it('handles a missing status explicitly', () => {
      wrap(<StatusBadge status={null} />);
      expect(screen.getByTestId('status-badge').textContent).toBe('unknown');
    });
  });

  describe('StatCard', () => {
    it('renders label, value and hint', () => {
      wrap(<StatCard label="New Leads" value={7} hint="since yesterday" />);
      expect(screen.getByText('New Leads')).toBeTruthy();
      expect(screen.getByText('7')).toBeTruthy();
      expect(screen.getByText('since yesterday')).toBeTruthy();
    });

    it('is not interactive when no handler is supplied', () => {
      wrap(<StatCard label="Total" value={1} />);
      expect(screen.getByTestId('stat-card').tagName).toBe('DIV');
    });

    it('invokes the handler when one is supplied', () => {
      const onClick = vi.fn();
      wrap(<StatCard label="Interested" value={3} onClick={onClick} />);
      fireEvent.click(screen.getByTestId('stat-card'));
      expect(onClick).toHaveBeenCalledTimes(1);
    });
  });

  describe('LoadingState / ErrorState / EmptyState', () => {
    it('loading announces itself politely', () => {
      wrap(<LoadingState label="Loading your workspace..." />);
      const node = screen.getByTestId('loading-state');
      expect(node.getAttribute('role')).toBe('status');
      expect(node.getAttribute('aria-live')).toBe('polite');
      expect(screen.getByText('Loading your workspace...')).toBeTruthy();
    });

    it('error is an alert and can retry', () => {
      const onRetry = vi.fn();
      wrap(<ErrorState message="Boom" onRetry={onRetry} />);
      expect(screen.getByTestId('error-state').getAttribute('role')).toBe('alert');
      fireEvent.click(screen.getByText('Try again'));
      expect(onRetry).toHaveBeenCalled();
    });

    it('empty state renders title, description and action', () => {
      wrap(
        <EmptyState
          title="No leads"
          description="Nothing here yet"
          action={<button type="button">Create</button>}
        />,
      );
      expect(screen.getByText('No leads')).toBeTruthy();
      expect(screen.getByText('Nothing here yet')).toBeTruthy();
      expect(screen.getByText('Create')).toBeTruthy();
    });
  });

  describe('DataTable', () => {
    interface Row {
      id: number;
      name: string;
      status: string;
    }
    const columns: Array<{ key: string; header: string; render: (r: Row) => ReactElement | string }> = [
      { key: 'name', header: 'Lead', render: (r) => r.name },
      { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    ];
    const rows: Row[] = [
      { id: 2, name: 'Second', status: 'interested' },
      { id: 1, name: 'First', status: 'new' },
    ];

    it('renders a caption, scoped headers and preserves API row order', () => {
      wrap(
        <DataTable
          caption="Test leads"
          columns={columns as never}
          rows={rows}
          rowKey={(r) => String(r.id)}
        />,
      );
      expect(screen.getByText('Test leads')).toBeTruthy();
      const headers = screen.getAllByRole('columnheader').map((h) => h.textContent);
      expect(headers).toEqual(['Lead', 'Status']);

      const cells = screen.getAllByRole('cell').map((c) => c.textContent);
      // Deterministic: whatever the API ordered stays ordered.
      expect(cells[0]).toBe('Second');
      expect(cells[2]).toBe('First');
    });

    it('falls back to an empty state rather than an empty table', () => {
      wrap(
        <DataTable
          caption="Test leads"
          columns={columns as never}
          rows={[]}
          rowKey={(r: Row) => String(r.id)}
          emptyMessage="Nothing here"
        />,
      );
      expect(screen.queryByTestId('data-table')).toBeNull();
      expect(screen.getByText('Nothing here')).toBeTruthy();
    });
  });

  describe('FilterBar', () => {
    it('reports changes with the filter id', () => {
      const onChange = vi.fn();
      wrap(
        <FilterBar
          filters={[
            {
              id: 'status',
              label: 'Lead status',
              value: 'all',
              options: [
                { value: 'all', label: 'All statuses' },
                { value: 'new', label: 'New' },
              ],
            },
          ]}
          onChange={onChange}
        />,
      );
      expect(screen.getByText('Lead status')).toBeTruthy();
      fireEvent.change(screen.getByLabelText('Lead status'), { target: { value: 'new' } });
      expect(onChange).toHaveBeenCalledWith('status', 'new');
    });
  });

  describe('ConfirmDialog', () => {
    it('renders nothing when closed', () => {
      wrap(
        <ConfirmDialog
          open={false}
          title="Approve"
          message="Sure?"
          onConfirm={vi.fn()}
          onCancel={vi.fn()}
        />,
      );
      expect(screen.queryByTestId('confirm-dialog')).toBeNull();
    });

    it('is a labelled modal dialog and fires confirm/cancel', () => {
      const onConfirm = vi.fn();
      const onCancel = vi.fn();
      wrap(
        <ConfirmDialog
          open
          title="Approve vacancy"
          message="This publishes the vacancy."
          confirmLabel="Approve"
          onConfirm={onConfirm}
          onCancel={onCancel}
        />,
      );
      const dialog = screen.getByTestId('confirm-dialog');
      expect(dialog.getAttribute('role')).toBe('dialog');
      expect(dialog.getAttribute('aria-modal')).toBe('true');
      expect(screen.getByText('Approve vacancy')).toBeTruthy();
      fireEvent.click(screen.getByText('Approve'));
      fireEvent.click(screen.getByText('Cancel'));
      expect(onConfirm).toHaveBeenCalledTimes(1);
      expect(onCancel).toHaveBeenCalledTimes(1);
    });
  });

  describe('ActivityTimeline', () => {
    it('renders entries with status and timestamp', () => {
      wrap(
        <ActivityTimeline
          entries={[
            { id: 1, title: 'Follow-up completed', description: 'Called lead', timestamp: '2026-10-05', status: 'completed' },
          ]}
        />,
      );
      expect(screen.getByText('Follow-up completed')).toBeTruthy();
      expect(screen.getByText('Called lead')).toBeTruthy();
      expect(screen.getByText('2026-10-05')).toBeTruthy();
      expect(screen.getByTestId('status-badge')).toBeTruthy();
    });

    it('shows an empty state when there is no activity', () => {
      wrap(<ActivityTimeline entries={[]} />);
      expect(screen.getByText('No recent activity.')).toBeTruthy();
    });
  });

  describe('TopBar / Sidebar', () => {
    it('topbar shows the role label and user', () => {
      wrap(<TopBar roleLabel="Telecaller Desk" userName="Desk User" />);
      expect(screen.getByTestId('role-label').textContent).toBe('Telecaller Desk');
      expect(screen.getByText('Desk User')).toBeTruthy();
    });

    it('sidebar is labelled navigation and marks the active item', () => {
      wrap(
        <Sidebar
          label="Telecaller navigation"
          items={[
            { label: 'My leads', to: '/crm/telecaller', isActive: true },
            { label: 'Follow-ups', to: '/crm/telecaller/followups' },
          ]}
        />,
      );
      const nav = screen.getByRole('navigation', { name: 'Telecaller navigation' });
      expect(nav).toBeTruthy();
      const active = screen.getByText('My leads');
      expect(active.getAttribute('aria-current')).toBe('page');
      expect(screen.getByText('Follow-ups').getAttribute('aria-current')).toBeNull();
    });
  });

  describe('DashboardShell', () => {
    it('provides exactly one main landmark and one h1', () => {
      wrap(
        <DashboardShell roleLabel="Telecaller Desk" userName="Desk User" title="Telecaller Desk">
          <p>content</p>
        </DashboardShell>,
      );
      expect(screen.getAllByRole('main')).toHaveLength(1);
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
      expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Telecaller Desk');
    });

    it('renders sidebar items when supplied', () => {
      wrap(
        <DashboardShell
          roleLabel="Counsellor Desk"
          title="Counsellor Desk"
          sidebarItems={[{ label: 'Lead pipeline', to: '/crm/counsellor' }]}
        >
          <p>content</p>
        </DashboardShell>,
      );
      expect(screen.getByText('Lead pipeline')).toBeTruthy();
    });
  });
});