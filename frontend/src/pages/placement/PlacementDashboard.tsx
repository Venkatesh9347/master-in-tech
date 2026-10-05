import { useCallback, useEffect, useState } from 'react'
import API from '../../services/api'
import type {
  AdminApplicationItem,
  AdminOpportunityItem,
  AdminPlacementStats,
  CorporatePartnerItem,
} from '../admin/AdminPlacements'

/**
 * Placement Operations Desk.
 *
 * Reuses the existing placement API surface and the types already exported by
 * AdminPlacements.tsx rather than re-declaring the contract or re-implementing
 * approval logic. Every request below is authorized server-side by
 * EnsureUserIsPlacementStaff, so this page's controls are a view onto
 * operations the backend has already permitted — not a security boundary.
 *
 * Workflow enforced by the backend:
 *   company creates job (draft | pending_approval)
 *     -> placement advisor reviews
 *          -> approve => published (live to students)
 *          -> reject  => rejected
 */

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-bold text-slate-900">{value}</p>
    </div>
  )
}

function StatusBadge({ status }: { status: string }) {
  const tone: Record<string, string> = {
    published: 'bg-emerald-100 text-emerald-800',
    pending_approval: 'bg-amber-100 text-amber-800',
    draft: 'bg-slate-100 text-slate-700',
    closed: 'bg-slate-200 text-slate-700',
    rejected: 'bg-rose-100 text-rose-800',
    approved: 'bg-emerald-100 text-emerald-800',
    under_review: 'bg-sky-100 text-sky-800',
  }
  return (
    <span
      className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${
        tone[status] ?? 'bg-slate-100 text-slate-700'
      }`}
    >
      {status.replace(/_/g, ' ')}
    </span>
  )
}

export default function PlacementDashboard() {
  const [stats, setStats] = useState<AdminPlacementStats | null>(null)
  const [pendingJobs, setPendingJobs] = useState<AdminOpportunityItem[]>([])
  const [partners, setPartners] = useState<CorporatePartnerItem[]>([])
  const [applications, setApplications] = useState<AdminApplicationItem[]>([])
  const [loading, setLoading] = useState(true)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [errorMsg, setErrorMsg] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const unwrap = (payload: unknown): never[] => {
    if (Array.isArray(payload)) return payload as never[]
    const nested = (payload as { data?: unknown[] })?.data
    return Array.isArray(nested) ? (nested as never[]) : []
  }

  const load = useCallback(async () => {
    setLoading(true)
    setErrorMsg(null)
    try {
      const [statsRes, pendingRes, partnersRes, appsRes] = await Promise.all([
        API.get<AdminPlacementStats>('/admin/placements/stats'),
        API.get('/admin/placements/jobs/pending'),
        API.get('/admin/placements/partners?per_page=50'),
        API.get('/admin/placements/applications?per_page=50'),
      ])
      setStats(statsRes.data)
      setPendingJobs(unwrap(pendingRes.data) as AdminOpportunityItem[])
      setPartners(unwrap(partnersRes.data) as CorporatePartnerItem[])
      setApplications(unwrap(appsRes.data) as AdminApplicationItem[])
    } catch {
      setErrorMsg('Failed to load placement operations.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const runAction = async (
    id: number,
    request: () => Promise<{ data?: { message?: string } }>,
    fallback: string,
  ) => {
    setBusyId(id)
    setNotice(null)
    setErrorMsg(null)
    try {
      const res = await request()
      setNotice(res.data?.message ?? fallback)
      await load()
    } catch (err) {
      const status = (err as { response?: { status?: number } })?.response?.status
      setErrorMsg(
        status === 403
          ? 'Your role does not permit this placement operation.'
          : 'The operation could not be completed.',
      )
    } finally {
      setBusyId(null)
    }
  }

  const approveJob = (job: AdminOpportunityItem) =>
    runAction(
      job.id,
      () => API.post(`/admin/placements/jobs/${job.id}/approve`),
      `"${job.title}" approved and published live to students.`,
    )

  const rejectJob = (job: AdminOpportunityItem) => {
    const reason = window.prompt(`Reason for rejecting "${job.title}":`) ?? undefined
    return runAction(
      job.id,
      () => API.post(`/admin/placements/jobs/${job.id}/reject`, { reason }),
      `"${job.title}" rejected.`,
    )
  }

  const partnerAction = (partner: CorporatePartnerItem, action: string) =>
    runAction(
      partner.id,
      () => API.post(`/admin/placements/partners/${partner.id}/${action}`),
      `Partner ${action}d.`,
    )

  const setApplicationStatus = (app: AdminApplicationItem, status: string) =>
    runAction(
      app.id,
      () => API.put(`/admin/placements/applications/${app.id}/status`, { status }),
      `Application marked ${status.replace(/_/g, ' ')}.`,
    )

  return (
    <div className="min-h-screen bg-slate-50">
      {/* Exactly one main landmark for this route. */}
      <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <header className="mb-6">
          <p className="text-sm font-semibold uppercase tracking-wide text-purple-700">
            Placement Advisor
          </p>
          <h1 className="mt-1 text-3xl font-bold text-slate-900">Placement Operations Desk</h1>
          <p className="mt-2 max-w-3xl text-sm text-slate-600">
            Review corporate partnerships and company-submitted vacancies. Approval is the
            authorization boundary that publishes a vacancy to students; a company can never
            publish its own vacancy.
          </p>
        </header>

        {errorMsg && (
          <div role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">
            {errorMsg}
          </div>
        )}
        {notice && (
          <div role="status" className="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">
            {notice}
          </div>
        )}

        {loading ? (
          <p role="status" className="text-slate-600">Loading placement operations...</p>
        ) : (
          <div className="space-y-8">
            <section aria-labelledby="kpi-heading">
              <h2 id="kpi-heading" className="sr-only">
                Placement overview
              </h2>
              <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                <StatCard label="Opportunities" value={stats?.total_opportunities ?? 0} />
                <StatCard label="Applications" value={stats?.total_applications ?? 0} />
                <StatCard label="Shortlisted" value={stats?.shortlisted ?? 0} />
                <StatCard label="Interviews" value={stats?.interview_scheduled ?? 0} />
                <StatCard label="Selected" value={stats?.selected ?? 0} />
              </div>
            </section>

            <section aria-labelledby="pending-heading">
              <h2 id="pending-heading" className="text-lg font-semibold text-slate-900">
                Vacancies awaiting review
              </h2>
              <p className="mt-1 text-sm text-slate-600">
                Submitted by companies in <code>pending_approval</code>. Approving publishes the
                vacancy live to eligible students.
              </p>
              {pendingJobs.length === 0 ? (
                <p className="mt-3 rounded-lg border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                  No vacancies are awaiting review.
                </p>
              ) : (
                <ul className="mt-3 space-y-3">
                  {pendingJobs.map((job) => (
                    <li
                      key={job.id}
                      className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                      <div>
                        <p className="font-semibold text-slate-900">{job.title}</p>
                        <p className="text-sm text-slate-600">
                          {job.company_name ?? 'Unknown company'} · {job.location ?? '—'}
                        </p>
                        <StatusBadge status={job.status ?? 'pending_approval'} />
                      </div>
                      <div className="flex shrink-0 gap-2">
                        <button
                          type="button"
                          disabled={busyId === job.id}
                          onClick={() => approveJob(job)}
                          className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60"
                        >
                          Approve &amp; publish
                        </button>
                        <button
                          type="button"
                          disabled={busyId === job.id}
                          onClick={() => rejectJob(job)}
                          className="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-700 disabled:opacity-60"
                        >
                          Reject
                        </button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section aria-labelledby="partners-heading">
              <h2 id="partners-heading" className="text-lg font-semibold text-slate-900">
                Corporate partners
              </h2>
              {partners.length === 0 ? (
                <p className="mt-3 rounded-lg border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                  No corporate partners yet.
                </p>
              ) : (
                <ul className="mt-3 grid gap-3 md:grid-cols-2">
                  {partners.map((partner) => (
                    <li
                      key={partner.id}
                      className="rounded-xl border border-slate-200 bg-white p-4"
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-semibold text-slate-900">{partner.name}</p>
                          <p className="text-sm text-slate-600">
                            {partner.industry ?? '—'} · {partner.location ?? '—'}
                          </p>
                        </div>
                        <StatusBadge status={partner.status ?? 'pending'} />
                      </div>
                      <div className="mt-3 flex flex-wrap gap-2">
                        {partner.status === 'pending' && (
                          <>
                            <button
                              type="button"
                              disabled={busyId === partner.id}
                              onClick={() => partnerAction(partner, 'approve')}
                              className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
                            >
                              Approve
                            </button>
                            <button
                              type="button"
                              disabled={busyId === partner.id}
                              onClick={() => partnerAction(partner, 'reject')}
                              className="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 disabled:opacity-60"
                            >
                              Reject
                            </button>
                          </>
                        )}
                        {partner.status === 'approved' && (
                          <button
                            type="button"
                            disabled={busyId === partner.id}
                            onClick={() => partnerAction(partner, 'suspend')}
                            className="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-800 disabled:opacity-60"
                          >
                            Suspend
                          </button>
                        )}
                        {(partner.status === 'suspended' || partner.status === 'rejected') && (
                          <button
                            type="button"
                            disabled={busyId === partner.id}
                            onClick={() => partnerAction(partner, 'reactivate')}
                            className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:opacity-60"
                          >
                            Reactivate
                          </button>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section aria-labelledby="applications-heading">
              <h2 id="applications-heading" className="text-lg font-semibold text-slate-900">
                Candidate pipeline
              </h2>
              {applications.length === 0 ? (
                <p className="mt-3 rounded-lg border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                  No placement applications yet.
                </p>
              ) : (
                <div className="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                  <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <caption className="sr-only">Student placement applications</caption>
                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th scope="col" className="px-4 py-3">Student</th>
                        <th scope="col" className="px-4 py-3">Opportunity</th>
                        <th scope="col" className="px-4 py-3">Status</th>
                        <th scope="col" className="px-4 py-3">Action</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {applications.map((app) => (
                        <tr key={app.id}>
                          <td className="px-4 py-3 font-medium text-slate-900">
                            {app.student_name ?? '—'}
                          </td>
                          <td className="px-4 py-3 text-slate-600">
                            {app.opportunity?.title ?? app.course_title ?? '—'}
                          </td>
                          <td className="px-4 py-3">
                            <StatusBadge status={app.status ?? 'applied'} />
                          </td>
                          <td className="px-4 py-3">
                            {app.status === 'applied' || app.status === 'under_review' ? (
                              <button
                                type="button"
                                disabled={busyId === app.id}
                                onClick={() => setApplicationStatus(app, 'shortlisted')}
                                className="rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
                              >
                                Shortlist
                              </button>
                            ) : (
                              <span className="text-xs text-slate-400">—</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          </div>
        )}
      </main>
    </div>
  )
}