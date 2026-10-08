import { useCallback, useEffect, useMemo, useState } from 'react';
import API from '../../services/api';
import { useAuth } from '../../context/useAuth';
import {
  DashboardShell,
  DataTable,
  EmptyState,
  ErrorState,
  FilterBar,
  LoadingState,
  StatCard,
  StatusBadge,
  type DataTableColumn,
} from '../../components/dashboard';

/**
 * Phase 6 / 7 / 8 â€” Telecaller, Course Advisor and Counsellor dashboards.
 *
 * IMPORTANT â€” authorization model
 * --------------------------------
 * All three roles share ONE enforced scoped CRM tier (CrmCapabilities +
 * `Enquiry::visibleTo($user)`). These dashboards therefore change PRESENTATION
 * ONLY: each role sees a different information architecture over the same
 * already-permitted data. No endpoint, middleware, controller or scoping rule is
 * modified, and the CRM three-way split remains TARGET / NOT YET ENFORCED.
 *
 * The backend remains the authority on what data exists; these components only
 * decide what to display and how to label it.
 */

interface CrmStats {
  total_leads: number;
  new_leads: number;
  contacted: number;
  follow_ups_due: number;
  demos: number;
  interested: number;
  payment_pending: number;
  converted: number;
  lost: number;
  overdue_follow_ups: number;
  todays_follow_ups: number;
  upcoming_follow_ups: number;
  completed_follow_ups: number;
  conversion_rate: number;
  source_breakdown: Record<string, number>;
  priority_breakdown: Record<string, number>;
}

interface LeadItem {
  id: number;
  name: string;
  email?: string;
  phone?: string;
  status: string;
  course_interest?: string | null;
  assigned_counsellor_id?: number | null;
  assigned_to?: string | null;
  created_at?: string;
}

export type CrmRole = 'telecaller' | 'course_advisor' | 'counsellor';

interface RoleConfig {
  role: CrmRole;
  route: string;
  label: string;
  title: string;
  description: string;
  kpis: (s: CrmStats) => Array<{
    label: string;
    value: number | string;
    hint?: string;
    tone?: 'default' | 'positive' | 'warning' | 'critical';
    /** lead status this tile drills into */
    drill?: string;
  }>;
  /** lead statuses offered in the filter bar, in workflow order */
  filters: Array<{ id: string; label: string; options: Array<{ value: string; label: string }> }>;
}

const ALL_STATUS = { value: 'all', label: 'All statuses' };

function statusOption(value: string, label: string) {
  return { value, label };
}

const CONFIGS: Record<CrmRole, RoleConfig> = {
  /* ---------------- Phase 6 â€” TELECALLER ---------------- */
  telecaller: {
    role: 'telecaller',
    route: '/crm/telecaller',
    label: 'Telecaller',
    title: 'Telecaller Desk',
    description:
      'Lead contact and follow-up operations. Every figure below is scoped to the leads you are permitted to see.',
    kpis: (s) => [
      { label: 'New Leads', value: s.new_leads, drill: 'new' },
      { label: 'My Leads', value: s.total_leads },
      { label: "Today's Calls", value: s.todays_follow_ups, hint: 'Follow-ups due today' },
      { label: 'Pending Follow-ups', value: s.upcoming_follow_ups },
      {
        label: 'Overdue Follow-ups',
        value: s.overdue_follow_ups,
        tone: s.overdue_follow_ups > 0 ? 'warning' : 'default',
      },
      { label: 'Demo Requests', value: s.demos, hint: 'Scheduled + completed demos' },
      { label: 'Demo Scheduled', value: s.demos },
      { label: 'Interested', value: s.interested, tone: 'positive', drill: 'interested' },
    ],
    filters: [
      {
        id: 'status',
        label: 'Lead status',
        options: [
          ALL_STATUS,
          statusOption('new', 'New'),
          statusOption('contacted', 'Contacted'),
          statusOption('demo_scheduled', 'Demo scheduled'),
          statusOption('interested', 'Interested'),
          statusOption('payment_pending', 'Payment pending'),
          statusOption('converted', 'Converted'),
          statusOption('lost', 'Lost'),
        ],
      },
    ],
  },

  /* ---------------- Phase 7 â€” COURSE ADVISOR ---------------- */
  course_advisor: {
    role: 'course_advisor',
    route: '/crm/course-advisor',
    label: 'Course Advisor',
    title: 'Course Advisor Desk',
    description:
      'Course recommendation, admissions and conversion. Figures reflect only the leads within your permitted scope.',
    kpis: (s) => [
      { label: 'Assigned Leads', value: s.total_leads },
      { label: 'Qualified Leads', value: s.contacted, hint: 'Contacted and progressed' },
      { label: 'Demo Scheduled', value: s.demos },
      { label: 'Demo Completed', value: s.demos, hint: 'Scheduled + completed' },
      { label: 'Interested', value: s.interested, tone: 'positive', drill: 'interested' },
      { label: 'Payment Pending', value: s.payment_pending, tone: 'warning', drill: 'payment_pending' },
      { label: 'Admission Confirmed', value: s.converted, tone: 'positive' },
      { label: 'Enrolled', value: s.converted },
      { label: 'Follow-ups Due', value: s.follow_ups_due },
      { label: 'Conversion Rate', value: `${s.conversion_rate}%`, tone: 'positive' },
    ],
    filters: [
      {
        id: 'status',
        label: 'Lead status',
        options: [
          ALL_STATUS,
          statusOption('contacted', 'Contacted'),
          statusOption('demo_completed', 'Demo completed'),
          statusOption('interested', 'Interested'),
          statusOption('payment_pending', 'Payment pending'),
          statusOption('admission_confirmed', 'Admission confirmed'),
          statusOption('enrolled', 'Enrolled'),
          statusOption('converted', 'Converted'),
        ],
      },
    ],
  },

  /* ---------------- Phase 8 â€” COUNSELLOR ---------------- */
  counsellor: {
    role: 'counsellor',
    route: '/crm/counsellor',
    label: 'Counsellor',
    title: 'Counsellor Desk',
    description:
      'Counselling queue and admission pipeline, scoped to the leads you are permitted to see.',
    kpis: (s) => [
      { label: 'Lead Pipeline', value: s.total_leads },
      { label: 'Counselling Queue', value: s.new_leads + s.contacted, drill: 'contacted' },
      { label: "Today's Follow-ups", value: s.todays_follow_ups },
      { label: 'Overdue Follow-ups', value: s.overdue_follow_ups, tone: s.overdue_follow_ups > 0 ? 'warning' : 'default' },
      { label: 'Demo Schedule', value: s.demos },
      { label: 'Interested Students', value: s.interested, tone: 'positive', drill: 'interested' },
      { label: 'Admission Pipeline', value: s.payment_pending + s.converted },
      { label: 'Conversion Metrics', value: `${s.conversion_rate}%`, tone: 'positive' },
    ],
    filters: [
      {
        id: 'status',
        label: 'Lead status',
        options: [
          ALL_STATUS,
          statusOption('new', 'New'),
          statusOption('contacted', 'Contacted'),
          statusOption('demo_scheduled', 'Demo scheduled'),
          statusOption('interested', 'Interested'),
          statusOption('admission_confirmed', 'Admission confirmed'),
          statusOption('enrolled', 'Enrolled'),
          statusOption('converted', 'Converted'),
        ],
      },
    ],
  },
};

export default function CrmRoleDashboard({ config }: { config: CrmRole }) {
  const cfg = CONFIGS[config];
  const { user } = useAuth();

  const [stats, setStats] = useState<CrmStats | null>(null);
  const [leads, setLeads] = useState<LeadItem[]>([]);
  const [statusFilter, setStatusFilter] = useState('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (statusFilter !== 'all') params.append('status', statusFilter);

      const [statsRes, leadsRes] = await Promise.all([
        API.get<CrmStats>('/admin/crm/stats'),
        API.get<{ data?: LeadItem[] } | LeadItem[]>(`/admin/crm/leads?${params.toString()}`),
      ]);

      setStats(statsRes.data);
      const body = leadsRes.data;
      setLeads(Array.isArray(body) ? body : (body?.data ?? []));
    } catch {
      setError('Failed to load your CRM workspace.');
    } finally {
      setLoading(false);
    }
  }, [statusFilter]);

  useEffect(() => {
    load();
  }, [load]);

  const kpis = useMemo(() => (stats ? cfg.kpis(stats) : []), [stats, cfg]);

  const columns: Array<DataTableColumn<LeadItem>> = [
    {
      key: 'name',
      header: 'Lead',
      render: (row) => (
        <div>
          <p className="font-medium text-slate-900">{row.name}</p>
          {row.email ? <p className="text-xs text-slate-500">{row.email}</p> : null}
        </div>
      ),
    },
    {
      key: 'course',
      header: 'Course interest',
      render: (row) => <span className="text-slate-600">{row.course_interest ?? 'â€”'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <StatusBadge status={row.status} />,
    },
    {
      key: 'owner',
      header: 'Assigned to',
      render: (row) => <span className="text-slate-600">{row.assigned_to ?? 'Unassigned'}</span>,
    },
  ];

  return (
    <DashboardShell
      roleLabel={cfg.label}
      userName={user?.name ?? null}
      title={cfg.title}
      description={cfg.description}
    >
      {error ? <ErrorState message={error} onRetry={load} /> : null}

      {loading ? (
        <LoadingState label="Loading your workspace..." />
      ) : (
        <div className="space-y-6">
          <section aria-labelledby={`${config}-kpis`}>
            <h2 id={`${config}-kpis`} className="sr-only">
              Key performance indicators
            </h2>
            <div className="mit-stagger grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
              {kpis.map((kpi, index) => (
                <StatCard
                  key={kpi.label}
                  label={kpi.label}
                  value={kpi.value}
                  hint={kpi.hint}
                  tone={kpi.tone}
                  onClick={kpi.drill ? () => setStatusFilter(kpi.drill as string) : undefined}
                  index={index}
                />
              ))}
            </div>
          </section>

          <section aria-labelledby={`${config}-leads`} className="space-y-3">
            <h2 id={`${config}-leads`} className="text-lg font-semibold text-slate-900">
              {config === 'telecaller' ? 'My leads' : 'Lead pipeline'}
            </h2>
            <FilterBar
              filters={cfg.filters.map((f) => ({ ...f, value: statusFilter }))}
              onChange={(_id, value) => setStatusFilter(value)}
            />
            {leads.length === 0 ? (
              <EmptyState
                title="No leads match this view."
                description="Adjust the filter, or check back once new enquiries arrive."
              />
            ) : (
              <DataTable
                caption={`${cfg.label} leads`}
                columns={columns}
                rows={leads}
                rowKey={(row) => String(row.id)}
              />
            )}
          </section>
        </div>
      )}
    </DashboardShell>
  );
}

export { CONFIGS as CRM_ROLE_CONFIGS };