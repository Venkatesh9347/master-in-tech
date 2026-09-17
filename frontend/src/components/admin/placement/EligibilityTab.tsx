import { useState, useEffect, useCallback } from 'react'
import API from '../../../services/api'
import type { StudentEligibilityListItem } from '../../../types/mockInterview'

export default function EligibilityTab() {
  const [students, setStudents] = useState<StudentEligibilityListItem[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | 'ENABLED' | 'ELIGIBLE' | 'SUSPENDED' | 'DISABLED'>('all')
  const [page, setPage] = useState(1)
  const [pagination, setPagination] = useState<{
    current_page: number
    last_page: number
    total: number
    per_page: number
  } | null>(null)

  // Dashboard Status Action Modal
  const [statusModalStudent, setStatusModalStudent] = useState<StudentEligibilityListItem | null>(null)
  const [statusAction, setStatusAction] = useState<'enable' | 'disable' | 'suspend' | 'reenable'>('enable')
  const [statusReason, setStatusReason] = useState('')
  const [savingStatus, setSavingStatus] = useState(false)

  // Override Modal
  const [overrideStudent, setOverrideStudent] = useState<StudentEligibilityListItem | null>(null)
  const [overrideEligible, setOverrideEligible] = useState(true)
  const [overrideReason, setOverrideReason] = useState('')
  const [overrideNotes, setOverrideNotes] = useState('')
  const [savingOverride, setSavingOverride] = useState(false)

  const [msg, setMsg] = useState<{ text: string; type: 'success' | 'error' } | null>(null)

  const showMsg = (text: string, type: 'success' | 'error' = 'success') => {
    setMsg({ text, type })
    setTimeout(() => setMsg(null), 4500)
  }

  const fetchStudents = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      if (search.trim()) params.append('search', search.trim())
      if (statusFilter !== 'all') params.append('dashboard_status', statusFilter)
      // Server-side pagination: only the requested roster page is loaded.
      params.append('page', String(page))

      const res = await API.get<
        | { data?: StudentEligibilityListItem[]; current_page?: number; last_page?: number; total?: number; per_page?: number }
        | StudentEligibilityListItem[]
      >(
        `/admin/mock-interviews/eligibility?${params.toString()}`
      )
      const list = Array.isArray(res.data) ? res.data : res.data?.data || []
      setStudents(list)
      if (!Array.isArray(res.data) && typeof res.data?.total === 'number') {
        setPagination({
          current_page: res.data.current_page ?? page,
          last_page: res.data.last_page ?? page,
          total: res.data.total ?? list.length,
          per_page: res.data.per_page ?? list.length,
        })
      } else {
        setPagination(null)
      }
    } catch {
      showMsg('Failed to load students placement eligibility list.', 'error')
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter, page])

  useEffect(() => {
    fetchStudents()
  }, [fetchStudents])

  // Open Status Change Modal
  const openStatusModal = (
    item: StudentEligibilityListItem,
    action: 'enable' | 'disable' | 'suspend' | 'reenable'
  ) => {
    setStatusModalStudent(item)
    setStatusAction(action)
    setStatusReason(
      action === 'enable' || action === 'reenable'
        ? 'Satisfied all coursework, mock interview evaluation, and placement criteria.'
        : action === 'suspend'
        ? 'Temporary placement hold pending administrative review.'
        : 'Student requested temporary placement pause.'
    )
  }

  const handleStatusSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!statusModalStudent) return
    setSavingStatus(true)

    try {
      const res = await API.post<{ message?: string }>('/admin/placements/dashboard-control/status', {
        student_id: statusModalStudent.id,
        action: statusAction,
        reason: statusReason.trim() || undefined,
      })

      showMsg(res.data?.message || `Placement Dashboard status updated successfully!`)
      setStatusModalStudent(null)
      fetchStudents()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to update Placement Dashboard status.', 'error')
    } finally {
      setSavingStatus(false)
    }
  }

  // Open Override Modal
  const openOverrideModal = (item: StudentEligibilityListItem) => {
    setOverrideStudent(item)
    setOverrideEligible(!item.eligibility.placement_eligible)
    setOverrideReason(item.eligibility.override_reason || 'Verified student domain knowledge and prior industry experience.')
    setOverrideNotes('')
  }

  const handleOverrideSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!overrideStudent || !overrideReason.trim()) return
    setSavingOverride(true)

    try {
      await API.post('/admin/mock-interviews/eligibility-override', {
        student_id: overrideStudent.id,
        placement_eligible: overrideEligible,
        reason: overrideReason.trim(),
        notes: overrideNotes.trim() || undefined,
      })

      showMsg(`Placement eligibility override for '${overrideStudent.name}' updated successfully!`)
      setOverrideStudent(null)
      fetchStudents()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to update eligibility override.', 'error')
    } finally {
      setSavingOverride(false)
    }
  }

  return (
    <div className="space-y-6">
      {msg && (
        <div
          className={`px-5 py-3.5 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg ${
            msg.type === 'success'
              ? 'bg-emerald-950/90 border border-emerald-800 text-emerald-200'
              : 'bg-rose-950/90 border border-rose-800 text-rose-200'
          }`}
        >
          <span>{msg.text}</span>
          <button type="button" onClick={() => setMsg(null)} className="opacity-80 hover:opacity-100 font-bold ml-4">
            ✕
          </button>
        </div>
      )}

      {/* Control Banner & Dashboard Status Filter */}
      <div className="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-lg">
        <div className="flex flex-col sm:flex-row items-center gap-3 flex-1">
          <div className="w-full sm:w-80 relative">
            <span className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs">🔍</span>
            <input
              type="text"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              placeholder="Search by student name, email, student ID..."
              className="w-full bg-slate-900 border border-slate-800 rounded-xl pl-9 pr-3 py-2 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
            />
          </div>

          <div className="flex items-center gap-2 w-full sm:w-auto">
            <label className="text-[11px] font-bold text-slate-400 whitespace-nowrap">Dashboard Status:</label>
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value as 'all' | 'ENABLED' | 'ELIGIBLE' | 'SUSPENDED' | 'DISABLED')
                setPage(1)
              }}
              className="bg-slate-900 border border-slate-800 text-slate-200 text-xs font-bold rounded-xl px-3 py-2 focus:outline-none focus:border-purple-500"
            >
              <option value="all">All Statuses ({pagination?.total ?? students.length})</option>
              <option value="ENABLED">🚀 Dashboard Enabled</option>
              <option value="ELIGIBLE">⭐ Eligible for Activation</option>
              <option value="SUSPENDED">⛔ Suspended</option>
              <option value="DISABLED">🔒 Ineligible / Disabled</option>
            </select>
          </div>
        </div>

        <button
          type="button"
          onClick={fetchStudents}
          className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 bg-slate-900 hover:bg-slate-850 border border-slate-800 transition flex items-center justify-center gap-1.5"
        >
          <span>🔄</span> Refresh Roster
        </button>
      </div>

      {/* Roster Table */}
      <div className="bg-slate-950 border border-slate-800 rounded-3xl overflow-hidden shadow-2xl">
        {loading ? (
          <div className="py-20 text-center text-slate-400 space-y-3">
            <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin mx-auto" />
            <p className="text-xs font-semibold">Loading student eligibility roster...</p>
          </div>
        ) : students.length === 0 ? (
          <div className="py-20 text-center text-slate-400 space-y-2">
            <span className="text-3xl block">🎓</span>
            <p className="text-sm font-bold text-white">No Students Found</p>
            <p className="text-xs text-slate-500">No student matching the current search / filter criteria was found.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead>
                <tr className="border-b border-slate-800 bg-slate-900/60 text-[10px] font-black uppercase tracking-wider text-slate-400">
                  <th className="py-3.5 px-4">Student & Batch</th>
                  <th className="py-3.5 px-4">Course Curriculum</th>
                  <th className="py-3.5 px-4">Mandatory Mock Interview</th>
                  <th className="py-3.5 px-4">Evaluation Result</th>
                  <th className="py-3.5 px-4">Placement Dashboard Status</th>
                  <th className="py-3.5 px-4 text-right">Admin Activation Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-850">
                {students.map((item) => {
                  const el = item.eligibility
                  const latestEval = el.latest_evaluation
                  const status = el.placement_dashboard_status || 'DISABLED'
                  const isEnabled = status === 'ENABLED'
                  const isSuspended = status === 'SUSPENDED'
                  const isEligible = el.is_eligible_for_activation

                  return (
                    <tr key={item.id} className="hover:bg-slate-900/40 transition">
                      {/* Student Info & Verified Batch */}
                      <td className="py-3.5 px-4">
                        <div className="font-bold text-white text-sm">{item.name}</div>
                        <div className="text-[11px] text-slate-400">{item.email}</div>
                        <div className="text-[10px] text-slate-500 font-mono mt-0.5">{item.phone || 'No phone'}</div>
                        {el.batch && (
                          <div className="mt-1">
                            <span className="inline-block text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-purple-950/80 border border-purple-800/80 text-purple-300 font-mono">
                              Batch: {el.batch.code}
                            </span>
                          </div>
                        )}
                      </td>

                      {/* Course Completion Breakdown */}
                      <td className="py-3.5 px-4">
                        {el.courses.length === 0 ? (
                          <span className="text-slate-500 italic">No Enrolled Courses</span>
                        ) : (
                          <div className="space-y-1.5">
                            {el.courses.map((c, i) => (
                              <div key={i} className="text-[11px]">
                                <div className="font-semibold text-slate-200 flex items-center justify-between gap-2">
                                  <span>{c.course_title}</span>
                                  <span className={`font-mono text-[10px] ${c.progress_percentage >= 100 ? 'text-emerald-400 font-bold' : 'text-purple-400'}`}>
                                    {c.progress_percentage}%
                                  </span>
                                </div>
                                <div className="w-32 bg-slate-900 h-1.5 rounded-full overflow-hidden mt-0.5 border border-slate-800">
                                  <div
                                    className={`h-full rounded-full ${
                                      c.progress_percentage >= 100 ? 'bg-emerald-500' : 'bg-purple-600'
                                    }`}
                                    style={{ width: `${Math.min(100, c.progress_percentage)}%` }}
                                  />
                                </div>
                                <span className="text-[9px] text-slate-500 block">
                                  {c.completed_lessons} / {c.total_lessons} lessons completed
                                </span>
                              </div>
                            ))}
                          </div>
                        )}
                      </td>

                      {/* Mock Interview Status */}
                      <td className="py-3.5 px-4">
                        <span
                          className={`inline-block px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${
                            el.mock_interview_state === 'passed' || el.mock_interview_state === 'completed'
                              ? 'bg-emerald-950 text-emerald-300 border-emerald-600'
                              : el.mock_interview_state === 'booked' || el.mock_interview_state === 'confirmed'
                              ? 'bg-blue-950 text-blue-300 border-blue-700'
                              : el.mock_interview_state === 'reinterview_required'
                              ? 'bg-rose-950 text-rose-300 border-rose-800'
                              : 'bg-slate-900 text-slate-400 border-slate-700'
                          }`}
                        >
                          {el.mock_interview_state.replace('_', ' ')}
                        </span>

                        {el.mock_interview && (
                          <div className="text-[10px] text-slate-400 mt-1 space-y-0.5">
                            {el.mock_interview.interview_date && (
                              <div>📅 {new Date(el.mock_interview.interview_date).toLocaleDateString()}</div>
                            )}
                            {el.mock_interview.interviewer_name && (
                              <div className="text-slate-300 font-semibold">
                                👤 {el.mock_interview.interviewer_name}
                              </div>
                            )}
                          </div>
                        )}
                      </td>

                      {/* Scorecard & Recommendation */}
                      <td className="py-3.5 px-4">
                        {latestEval ? (
                          <div className="space-y-0.5">
                            <span
                              className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-bold border ${
                                latestEval.recommendation === 'Ready for Placement'
                                  ? 'bg-emerald-950 text-emerald-300 border-emerald-600'
                                  : 'bg-amber-950 text-amber-300 border-amber-600'
                              }`}
                            >
                              {latestEval.recommendation}
                            </span>
                            <div className="text-[11px] font-bold text-slate-300">
                              ⭐ {latestEval.overall_rating} / 10
                            </div>
                          </div>
                        ) : (
                          <span className="text-slate-500 italic text-[11px]">No scorecard</span>
                        )}
                      </td>

                      {/* Placement Dashboard Status Badge */}
                      <td className="py-3.5 px-4">
                        <div className="space-y-1">
                          <span
                            className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase border ${
                              isEnabled
                                ? 'bg-emerald-950 text-emerald-300 border-emerald-500 shadow-sm shadow-emerald-950'
                                : isSuspended
                                ? 'bg-rose-950 text-rose-300 border-rose-800'
                                : status === 'ELIGIBLE'
                                ? 'bg-purple-950 text-purple-300 border-purple-600'
                                : 'bg-slate-900 text-slate-500 border-slate-700'
                            }`}
                          >
                            <span>
                              {isEnabled
                                ? '🚀 Dashboard Enabled'
                                : isSuspended
                                ? '⛔ Suspended'
                                : status === 'ELIGIBLE'
                                ? '⭐ Eligible for Activation'
                                : '🔒 Ineligible / Disabled'}
                            </span>
                          </span>

                          {el.is_admin_override && (
                            <span className="block text-[9px] font-extrabold text-amber-400 tracking-tight">
                              ⚡ Admin Override Granted
                            </span>
                          )}

                          {el.dashboard_status_reason && (
                            <p className="text-[9px] text-slate-400 max-w-xs truncate" title={el.dashboard_status_reason}>
                              Note: {el.dashboard_status_reason}
                            </p>
                          )}
                        </div>
                      </td>

                      {/* Admin Activation Actions */}
                      <td className="py-3.5 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5 flex-wrap">
                          {/* Enable / Re-enable Action */}
                          {!isEnabled && !isSuspended && (
                            <button
                              type="button"
                              onClick={() => openStatusModal(item, 'enable')}
                              disabled={!isEligible && !el.is_admin_override}
                              className={`px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1 ${
                                isEligible || el.is_admin_override
                                  ? 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-md shadow-emerald-600/30'
                                  : 'bg-slate-900 border border-slate-800 text-slate-600 cursor-not-allowed'
                              }`}
                              title={
                                isEligible || el.is_admin_override
                                  ? 'Activate student Placement Dashboard'
                                  : 'Requires Course Completion + Mock Interview Evaluation'
                              }
                            >
                              <span>🚀</span> Enable
                            </button>
                          )}

                          {/* Re-enable if Suspended */}
                          {isSuspended && (
                            <button
                              type="button"
                              onClick={() => openStatusModal(item, 'reenable')}
                              className="px-3 py-1.5 rounded-xl text-xs font-bold bg-blue-600 hover:bg-blue-500 text-white shadow-md shadow-blue-600/30 transition flex items-center gap-1"
                            >
                              <span>🔄</span> Re-enable
                            </button>
                          )}

                          {/* Suspend Action */}
                          {isEnabled && (
                            <button
                              type="button"
                              onClick={() => openStatusModal(item, 'suspend')}
                              className="px-2.5 py-1.5 rounded-xl text-xs font-bold text-amber-300 hover:text-amber-100 bg-amber-950/60 hover:bg-amber-900/80 border border-amber-800 transition"
                            >
                              ⛔ Suspend
                            </button>
                          )}

                          {/* Disable Action */}
                          {isEnabled && (
                            <button
                              type="button"
                              onClick={() => openStatusModal(item, 'disable')}
                              className="px-2.5 py-1.5 rounded-xl text-xs font-bold text-rose-300 hover:text-rose-100 bg-rose-950/60 hover:bg-rose-900/80 border border-rose-800 transition"
                            >
                              🔒 Disable
                            </button>
                          )}

                          {/* Override Button */}
                          <button
                            type="button"
                            onClick={() => openOverrideModal(item)}
                            className="px-2 py-1.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white bg-slate-900 hover:bg-slate-800 border border-slate-800 transition"
                            title="Set administrative placement eligibility override"
                          >
                            ⚙️
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Roster Pagination (server-side; prev/next only) */}
      {pagination && pagination.last_page > 1 && (
        <div className="flex items-center justify-between gap-3 bg-slate-950 px-4 py-3 rounded-2xl border border-slate-800">
          <button
            type="button"
            disabled={pagination.current_page <= 1 || loading}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            className="px-4 py-2 rounded-xl text-xs font-bold text-slate-200 bg-slate-900 hover:bg-slate-800 border border-slate-800 transition disabled:opacity-40 disabled:cursor-not-allowed"
          >
            ← Previous
          </button>
          <span className="text-[11px] font-bold text-slate-400">
            Page {pagination.current_page} of {pagination.last_page} · {pagination.total} students
          </span>
          <button
            type="button"
            disabled={pagination.current_page >= pagination.last_page || loading}
            onClick={() => setPage((p) => p + 1)}
            className="px-4 py-2 rounded-xl text-xs font-bold text-slate-200 bg-slate-900 hover:bg-slate-800 border border-slate-800 transition disabled:opacity-40 disabled:cursor-not-allowed"
          >
            Next →
          </button>
        </div>
      )}

      {/* Placement Dashboard Status Modal */}
      {statusModalStudent && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 className="text-base font-black text-white flex items-center gap-2">
              <span>{statusAction === 'enable' || statusAction === 'reenable' ? '🚀' : statusAction === 'suspend' ? '⛔' : '🔒'}</span>
              {statusAction === 'enable'
                ? 'Enable Placement Dashboard'
                : statusAction === 'reenable'
                ? 'Re-enable Placement Dashboard'
                : statusAction === 'suspend'
                ? 'Suspend Placement Dashboard'
                : 'Disable Placement Dashboard'}
            </h3>

            <div className="bg-slate-950 border border-slate-800 rounded-2xl p-3.5 space-y-1 text-xs">
              <div className="font-bold text-white">{statusModalStudent.name}</div>
              <div className="text-slate-400">{statusModalStudent.email}</div>
              {statusModalStudent.eligibility.batch && (
                <div className="text-purple-300 font-mono font-semibold">
                  Batch: {statusModalStudent.eligibility.batch.code}
                </div>
              )}
            </div>

            <form onSubmit={handleStatusSubmit} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">
                  Reason / Administrative Note {statusAction !== 'enable' && <span className="text-rose-400">*</span>}
                </label>
                <textarea
                  rows={3}
                  required={statusAction !== 'enable'}
                  value={statusReason}
                  onChange={(e) => setStatusReason(e.target.value)}
                  placeholder="Enter reason for this status change..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="flex justify-end gap-2.5 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setStatusModalStudent(null)}
                  disabled={savingStatus}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  disabled={savingStatus || (statusAction !== 'enable' && !statusReason.trim())}
                  className={`px-5 py-2 rounded-xl text-xs font-extrabold text-white transition flex items-center gap-1.5 ${
                    statusAction === 'enable' || statusAction === 'reenable'
                      ? 'bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-600/30'
                      : statusAction === 'suspend'
                      ? 'bg-amber-600 hover:bg-amber-500 shadow-md shadow-amber-600/30'
                      : 'bg-rose-600 hover:bg-rose-500 shadow-md shadow-rose-600/30'
                  }`}
                >
                  {savingStatus
                    ? 'Updating...'
                    : statusAction === 'enable'
                    ? '✓ Confirm Activation'
                    : statusAction === 'reenable'
                    ? '✓ Confirm Re-enable'
                    : statusAction === 'suspend'
                    ? '⛔ Confirm Suspension'
                    : '🔒 Confirm Disable'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Admin Override Modal */}
      {overrideStudent && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 className="text-base font-black text-white flex items-center gap-2">
              <span>⚙️</span> Override Placement Eligibility
            </h3>
            <p className="text-xs text-slate-400">
              Update placement eligibility status for student <span className="text-white font-bold">{overrideStudent.name}</span>.
            </p>

            <form onSubmit={handleOverrideSubmit} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Placement Eligibility Status</label>
                <div className="grid grid-cols-2 gap-2">
                  <button
                    type="button"
                    onClick={() => setOverrideEligible(true)}
                    className={`py-2 rounded-xl text-xs font-black border transition ${
                      overrideEligible
                        ? 'bg-emerald-950 border-emerald-500 text-emerald-300 shadow-md'
                        : 'bg-slate-950 border-slate-800 text-slate-400'
                    }`}
                  >
                    ✓ Grant Eligible
                  </button>

                  <button
                    type="button"
                    onClick={() => setOverrideEligible(false)}
                    className={`py-2 rounded-xl text-xs font-black border transition ${
                      !overrideEligible
                        ? 'bg-rose-950 border-rose-500 text-rose-300 shadow-md'
                        : 'bg-slate-950 border-slate-800 text-slate-400'
                    }`}
                  >
                    🔒 Set Ineligible
                  </button>
                </div>
              </div>

              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">
                  Reason for Override <span className="text-rose-400">*</span>
                </label>
                <input
                  type="text"
                  required
                  value={overrideReason}
                  onChange={(e) => setOverrideReason(e.target.value)}
                  placeholder="e.g. Prior industry experience, special clearance"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Internal Administrative Notes (Optional)</label>
                <textarea
                  rows={2}
                  value={overrideNotes}
                  onChange={(e) => setOverrideNotes(e.target.value)}
                  placeholder="Additional audit notes..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="flex justify-end gap-2.5 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setOverrideStudent(null)}
                  disabled={savingOverride}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  disabled={savingOverride || !overrideReason.trim()}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-1.5"
                >
                  {savingOverride ? 'Saving...' : '✓ Confirm Override'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
