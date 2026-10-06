/**
 * Phase 14 — shared dashboard design system.
 *
 * The brief requires a consistent MasterInTech design system reused across every
 * role dashboard: DashboardShell, Sidebar, TopBar, StatCard, DataTable,
 * StatusBadge, EmptyState, LoadingState, ErrorState, ConfirmDialog,
 * ActivityTimeline and FilterBar.
 *
 * These are PRESENTATION components only. They never fetch, never authorize and
 * never decide what a user may see — backend authorization remains authoritative.
 * Every component here is generic; role dashboards compose them.
 */

import type { ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/* StatusBadge                                                         */
/* ------------------------------------------------------------------ */

/** Status tone map. Unknown statuses fall back to neutral, never to a "good" tone. */
const STATUS_TONES: Record<string, string> = {
  // leads
  new: 'bg-sky-100 text-sky-800',
  contacted: 'bg-indigo-100 text-indigo-800',
  follow_up: 'bg-amber-100 text-amber-800',
  demo_scheduled: 'bg-violet-100 text-violet-800',
  demo_completed: 'bg-purple-100 text-purple-800',
  interested: 'bg-emerald-100 text-emerald-800',
  payment_pending: 'bg-orange-100 text-orange-800',
  admission_confirmed: 'bg-teal-100 text-teal-800',
  enrolled: 'bg-green-100 text-green-800',
  converted: 'bg-green-100 text-green-800',
  not_interested: 'bg-slate-200 text-slate-700',
  lost: 'bg-slate-200 text-slate-700',
  no_response: 'bg-slate-200 text-slate-700',
  closed: 'bg-slate-200 text-slate-700',
  // companies
  pending: 'bg-amber-100 text-amber-800',
  under_review: 'bg-sky-100 text-sky-800',
  approved: 'bg-emerald-100 text-emerald-800',
  rejected: 'bg-rose-100 text-rose-800',
  suspended: 'bg-orange-100 text-orange-800',
  // placements
  draft: 'bg-slate-200 text-slate-700',
  pending_approval: 'bg-amber-100 text-amber-800',
  published: 'bg-emerald-100 text-emerald-800',
  // applications
  applied: 'bg-sky-100 text-sky-800',
  under_review_app: 'bg-indigo-100 text-indigo-800',
  shortlisted: 'bg-violet-100 text-violet-800',
  interview_scheduled: 'bg-purple-100 text-purple-800',
  selected: 'bg-emerald-100 text-emerald-800',
  joined: 'bg-green-100 text-green-800',
  // interviews
  scheduled: 'bg-sky-100 text-sky-800',
  completed: 'bg-emerald-100 text-emerald-800',
  cancelled: 'bg-slate-200 text-slate-700',
  // users
  active: 'bg-emerald-100 text-emerald-800',
  disabled: 'bg-rose-100 text-rose-800',
  inactive: 'bg-slate-200 text-slate-700',
};

export function StatusBadge({ status }: { status?: string | null }) {
  if (!status) {
    return (
      <span
        data-testid="status-badge"
        data-status=""
        className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500"
      >
        unknown
      </span>
    );
  }

  const tone = STATUS_TONES[status] ?? 'bg-slate-100 text-slate-700';

  return (
    <span
      data-testid="status-badge"
      data-status={status}
      className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${tone}`}
    >
      {status.replace(/_/g, ' ')}
    </span>
  );
}

/* ------------------------------------------------------------------ */
/* StatCard                                                            */
/* ------------------------------------------------------------------ */

export function StatCard({
  label,
  value,
  hint,
  tone = 'default',
  onClick,
}: {
  label: string;
  value: number | string;
  hint?: string;
  tone?: 'default' | 'positive' | 'warning' | 'critical';
  onClick?: () => void;
}) {
  const valueTone =
    tone === 'positive'
      ? 'text-emerald-700'
      : tone === 'warning'
        ? 'text-amber-700'
        : tone === 'critical'
          ? 'text-rose-700'
          : 'text-slate-900';

  const Tag = onClick ? 'button' : 'div';

  return (
    <Tag
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      data-testid="stat-card"
      className={`rounded-xl border border-slate-200 bg-white p-4 text-left ${
        onClick ? 'hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-purple-500' : ''
      }`}
    >
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold ${valueTone}`}>{value}</p>
      {hint ? <p className="mt-1 text-xs text-slate-500">{hint}</p> : null}
    </Tag>
  );
}

/* ------------------------------------------------------------------ */
/* LoadingState / ErrorState / EmptyState                             */
/* ------------------------------------------------------------------ */

export function LoadingState({ label = 'Loading...' }: { label?: string }) {
  return (
    <div role="status" aria-live="polite" data-testid="loading-state" className="py-8 text-center">
      <span className="inline-flex items-center gap-3 text-slate-600 font-semibold">
        <span className="inline-block h-5 w-5 animate-spin rounded-full border-2 border-purple-600 border-t-transparent" />
        {label}
      </span>
    </div>
  );
}

export function ErrorState({
  message,
  onRetry,
}: {
  message?: string;
  onRetry?: () => void;
}) {
  return (
    <div role="alert" data-testid="error-state" className="rounded-lg bg-rose-50 p-4">
      <p className="text-sm font-semibold text-rose-800">
        {message ?? 'Something went wrong while loading this view.'}
      </p>
      {onRetry ? (
        <button
          type="button"
          onClick={onRetry}
          className="mt-2 rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white"
        >
          Try again
        </button>
      ) : null}
    </div>
  );
}

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div
      data-testid="empty-state"
      className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center"
    >
      <p className="font-semibold text-slate-700">{title}</p>
      {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}
      {action ? <div className="mt-3 flex justify-center">{action}</div> : null}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* DataTable                                                           */
/* ------------------------------------------------------------------ */

export interface DataTableColumn<T> {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  className?: string;
}

/**
 * Responsive data table.
 *
 * Deterministic ordering is the caller's responsibility — this component never
 * re-sorts rows, so what the API returned is what the user sees.
 */
export function DataTable<T>({
  columns,
  rows,
  rowKey,
  caption,
  emptyMessage = 'No records to display.',
  onRowClick,
}: {
  columns: Array<DataTableColumn<T>>;
  rows: T[];
  rowKey: (row: T, index: number) => string;
  caption: string;
  emptyMessage?: string;
  onRowClick?: (row: T) => void;
}) {
  if (rows.length === 0) {
    return <EmptyState title={emptyMessage} />;
  }

  return (
    <div
      data-testid="data-table"
      className="overflow-x-auto rounded-xl border border-slate-200 bg-white"
    >
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            {columns.map((col) => (
              <th key={col.key} scope="col" className={`px-4 py-3 ${col.className ?? ''}`}>
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((row, index) => (
            <tr
              key={rowKey(row, index)}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              className={onRowClick ? 'cursor-pointer hover:bg-slate-50' : undefined}
            >
              {columns.map((col) => (
                <td key={col.key} className={`px-4 py-3 ${col.className ?? ''}`}>
                  {col.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* FilterBar                                                           */
/* ------------------------------------------------------------------ */

export interface FilterOption {
  value: string;
  label: string;
}

export function FilterBar({
  filters,
  onChange,
  actions,
}: {
  filters: Array<{
    id: string;
    label: string;
    value: string;
    options: FilterOption[];
  }>;
  onChange: (id: string, value: string) => void;
  actions?: ReactNode;
}) {
  return (
    <div
      data-testid="filter-bar"
      className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3"
    >
      {filters.map((filter) => (
        <div key={filter.id}>
          <label
            htmlFor={`filter-${filter.id}`}
            className="block text-xs font-medium uppercase tracking-wide text-slate-500"
          >
            {filter.label}
          </label>
          <select
            id={`filter-${filter.id}`}
            value={filter.value}
            onChange={(e) => onChange(filter.id, e.target.value)}
            className="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
          >
            {filter.options.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      ))}
      {actions ? <div className="ml-auto flex items-end gap-2">{actions}</div> : null}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* ConfirmDialog                                                       */
/* ------------------------------------------------------------------ */

export function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  destructive = false,
  busy = false,
  onConfirm,
  onCancel,
}: {
  open: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
  busy?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  if (!open) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="confirm-dialog-title"
      data-testid="confirm-dialog"
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
    >
      <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h2 id="confirm-dialog-title" className="text-lg font-bold text-slate-900">
          {title}
        </h2>
        <p className="mt-2 text-sm text-slate-600">{message}</p>
        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            disabled={busy}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-60"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={busy}
            className={`rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60 ${
              destructive ? 'bg-rose-600' : 'bg-purple-600'
            }`}
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* ActivityTimeline                                                    */
/* ------------------------------------------------------------------ */

export interface TimelineEntry {
  id: string | number;
  title: string;
  description?: string;
  timestamp?: string | null;
  status?: string | null;
}

export function ActivityTimeline({ entries }: { entries: TimelineEntry[] }) {
  if (entries.length === 0) {
    return <EmptyState title="No recent activity." />;
  }

  return (
    <ol data-testid="activity-timeline" className="space-y-3">
      {entries.map((entry) => (
        <li key={entry.id} className="flex gap-3">
          <span aria-hidden="true" className="mt-2 h-2 w-2 shrink-0 rounded-full bg-purple-600" />
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-900">{entry.title}</p>
            {entry.description ? (
              <p className="text-sm text-slate-600">{entry.description}</p>
            ) : null}
            <div className="mt-1 flex items-center gap-2">
              {entry.timestamp ? (
                <time className="text-xs text-slate-500">{entry.timestamp}</time>
              ) : null}
              {entry.status ? <StatusBadge status={entry.status} /> : null}
            </div>
          </div>
        </li>
      ))}
    </ol>
  );
}

/* ------------------------------------------------------------------ */
/* TopBar / Sidebar / DashboardShell                                  */
/* ------------------------------------------------------------------ */

export function TopBar({
  roleLabel,
  userName,
  actions,
}: {
  roleLabel: string;
  userName?: string | null;
  actions?: ReactNode;
}) {
  return (
    <header
      data-testid="dashboard-topbar"
      className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3"
    >
      <div className="min-w-0">
        <p data-testid="role-label" className="text-xs font-semibold uppercase tracking-wide text-purple-700">
          {roleLabel}
        </p>
        {userName ? <p className="truncate text-sm text-slate-600">{userName}</p> : null}
      </div>
      {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
    </header>
  );
}

export interface SidebarItem {
  label: string;
  to: string;
  isActive?: boolean;
}

/**
 * Navigation only. This is a UX affordance and is deliberately NOT a security
 * boundary — backend authorization is authoritative for every route it links to.
 */
export function Sidebar({ items, label }: { items: SidebarItem[]; label: string }) {
  return (
    <nav
      aria-label={label}
      data-testid="dashboard-sidebar"
      className="flex gap-1 overflow-x-auto border-b border-slate-200 bg-white px-2 py-2 lg:flex-col lg:overflow-visible lg:border-b-0 lg:border-r"
    >
      {items.map((item) => (
        <a
          key={item.to}
          href={item.to}
          aria-current={item.isActive ? 'page' : undefined}
          className={`whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold ${
            item.isActive
              ? 'bg-purple-600 text-white'
              : 'text-slate-700 hover:bg-slate-100'
          }`}
        >
          {item.label}
        </a>
      ))}
    </nav>
  );
}

/**
 * Shared shell for every role dashboard: sidebar + topbar + a single <main>
 * landmark. Guarantees exactly one main landmark per dashboard route, which the
 * accessibility audit (B13) requires.
 */
export function DashboardShell({
  roleLabel,
  userName,
  sidebarItems,
  topBarActions,
  title,
  description,
  children,
}: {
  roleLabel: string;
  userName?: string | null;
  sidebarItems?: SidebarItem[];
  topBarActions?: ReactNode;
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <div data-testid="dashboard-shell" className="min-h-screen bg-slate-50">
      {sidebarItems ? <Sidebar items={sidebarItems} label={`${roleLabel} navigation`} /> : null}
      <div className="min-w-0 flex-1">
        <TopBar roleLabel={roleLabel} userName={userName} actions={topBarActions} />
        <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
          <h1 className="text-2xl font-bold text-slate-900">{title}</h1>
          {description ? <p className="mt-1 max-w-3xl text-sm text-slate-600">{description}</p> : null}
          <div className="mt-6">{children}</div>
        </main>
      </div>
    </div>
  );
}