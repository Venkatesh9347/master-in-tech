import { useState, useEffect, useCallback } from 'react'
import API from '../../../services/api'
import type {
  AdminMockInterviewItem,
  AdminMockInterviewerItem,
  AdminMockSlotItem,
  AdminMockStats,
} from '../../../types/mockInterview'
import EvaluationModal from './EvaluationModal'

const statusStyles: Record<string, string> = {
  booked: 'bg-blue-950/80 text-blue-300 border-blue-700',
  confirmed: 'bg-cyan-950/80 text-cyan-300 border-cyan-700',
  completed: 'bg-emerald-950/80 text-emerald-300 border-emerald-600',
  cancelled: 'bg-rose-950/80 text-rose-300 border-rose-800',
  rescheduled: 'bg-amber-950/80 text-amber-300 border-amber-700',
  no_show: 'bg-slate-900 text-slate-400 border-slate-700',
}

export default function MockInterviewsTab() {
  const [stats, setStats] = useState<AdminMockStats | null>(null)
  const [bookings, setBookings] = useState<AdminMockInterviewItem[]>([])
  const [interviewers, setInterviewers] = useState<AdminMockInterviewerItem[]>([])
  const [availableSlots, setAvailableSlots] = useState<AdminMockSlotItem[]>([])
  const [loading, setLoading] = useState(true)

  // Filters
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [interviewerFilter, setInterviewerFilter] = useState('all')
  const [dateFilter, setDateFilter] = useState('')

  // Modals & Active actions
  const [evaluatingInterview, setEvaluatingInterview] = useState<AdminMockInterviewItem | null>(null)
  const [reassigningInterview, setReassigningInterview] = useState<AdminMockInterviewItem | null>(null)
  const [selectedNewInterviewerId, setSelectedNewInterviewerId] = useState<number | ''>('')
  const [reschedulingInterview, setReschedulingInterview] = useState<AdminMockInterviewItem | null>(null)
  const [selectedNewSlotId, setSelectedNewSlotId] = useState<number | ''>('')
  const [rescheduleReason, setRescheduleReason] = useState('')
  const [cancellingInterview, setCancellingInterview] = useState<AdminMockInterviewItem | null>(null)
  const [cancelReason, setCancelReason] = useState('')

  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<{ text: string; type: 'success' | 'error' } | null>(null)

  const showMsg = (text: string, type: 'success' | 'error' = 'success') => {
    setMsg({ text, type })
    setTimeout(() => setMsg(null), 4500)
  }

  const fetchStats = useCallback(async () => {
    try {
      const res = await API.get<AdminMockStats>('/admin/mock-interviews/stats')
      setStats(res.data)
    } catch {
      // ignore
    }
  }, [])

  const fetchInterviewers = useCallback(async () => {
    try {
      const res = await API.get<{ data?: AdminMockInterviewerItem[] } | AdminMockInterviewerItem[]>(
        '/admin/mock-interviews/interviewers?per_page=100'
      )
      const list = Array.isArray(res.data) ? res.data : res.data?.data || []
      setInterviewers(list)
    } catch {
      // ignore
    }
  }, [])

  const fetchBookings = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      if (search.trim()) params.append('search', search.trim())
      if (statusFilter !== 'all') params.append('status', statusFilter)
      if (interviewerFilter !== 'all') params.append('interviewer_id', interviewerFilter)
      if (dateFilter) params.append('date', dateFilter)

      const res = await API.get<{ data?: AdminMockInterviewItem[] } | AdminMockInterviewItem[]>(
        `/admin/mock-interviews/bookings?${params.toString()}`
      )
      const list = Array.isArray(res.data) ? res.data : res.data?.data || []
      setBookings(list)
    } catch {
      showMsg('Failed to load mock interview bookings.', 'error')
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter, interviewerFilter, dateFilter])

  const fetchSlotsForReschedule = async () => {
    try {
      const res = await API.get<{ data?: AdminMockSlotItem[] } | AdminMockSlotItem[]>(
        '/admin/mock-interviews/slots?status=available&per_page=100'
      )
      const list = Array.isArray(res.data) ? res.data : res.data?.data || []
      setAvailableSlots(list)
    } catch {
      // ignore
    }
  }

  useEffect(() => {
    fetchStats()
    fetchInterviewers()
    fetchBookings()
  }, [fetchStats, fetchInterviewers, fetchBookings])

  const handleUpdateStatus = async (interviewId: number, newStatus: string) => {
    try {
      await API.put(`/admin/mock-interviews/bookings/${interviewId}/status`, {
        status: newStatus,
      })
      showMsg(`Status updated to ${newStatus.toUpperCase()}`)
      fetchBookings()
      fetchStats()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to update status.', 'error')
    }
  }

  const handleReassign = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!reassigningInterview || !selectedNewInterviewerId) return
    setSaving(true)
    try {
      await API.post(`/admin/mock-interviews/bookings/${reassigningInterview.id}/reassign`, {
        interviewer_id: selectedNewInterviewerId,
      })
      showMsg('Interviewer reassigned successfully.')
      setReassigningInterview(null)
      setSelectedNewInterviewerId('')
      fetchBookings()
      fetchStats()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to reassign interviewer.', 'error')
    } finally {
      setSaving(false)
    }
  }

  const handleReschedule = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!reschedulingInterview || !selectedNewSlotId) return
    setSaving(true)
    try {
      await API.post(`/admin/mock-interviews/bookings/${reschedulingInterview.id}/reschedule`, {
        slot_id: selectedNewSlotId,
        reason: rescheduleReason.trim() || undefined,
      })
      showMsg('Mock interview rescheduled successfully.')
      setReschedulingInterview(null)
      setSelectedNewSlotId('')
      setRescheduleReason('')
      fetchBookings()
      fetchStats()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to reschedule mock interview.', 'error')
    } finally {
      setSaving(false)
    }
  }

  const handleCancel = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!cancellingInterview || !cancelReason.trim()) return
    setSaving(true)
    try {
      await API.post(`/admin/mock-interviews/bookings/${cancellingInterview.id}/cancel`, {
        reason: cancelReason.trim(),
      })
      showMsg('Mock interview booking cancelled.')
      setCancellingInterview(null)
      setCancelReason('')
      fetchBookings()
      fetchStats()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to cancel booking.', 'error')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      {msg && (
        <div
          className={`px-5 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg ${
            msg.type === 'success'
              ? 'bg-emerald-950/80 border border-emerald-800 text-emerald-200'
              : 'bg-rose-950/80 border border-rose-800 text-rose-200'
          }`}
        >
          <span>{msg.text}</span>
          <button type="button" onClick={() => setMsg(null)} className="opacity-80 hover:opacity-100">
            ✕
          </button>
        </div>
      )}

      {/* KPI Mini-bar */}
      <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Total Bookings</span>
          <p className="text-2xl font-black text-white mt-1">{stats?.total_bookings ?? '...'}</p>
          <span className="text-[10px] font-semibold text-purple-400 mt-0.5 block">{stats?.scheduled_bookings ?? 0} Active</span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Completed</span>
          <p className="text-2xl font-black text-emerald-400 mt-1">{stats?.completed_bookings ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Interviews Held</span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Placement Ready</span>
          <p className="text-2xl font-black text-cyan-400 mt-1">{stats?.ready_for_placement ?? '...'}</p>
          <span className="text-[10px] font-semibold text-emerald-400 mt-0.5 block">Cleared Gateway</span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Needs Practice</span>
          <p className="text-2xl font-black text-amber-400 mt-1">{stats?.needs_improvement ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Improvement Plan</span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Cancelled / No-show</span>
          <p className="text-2xl font-black text-rose-400 mt-1">{(stats?.cancelled_bookings ?? 0) + (stats?.no_show_bookings ?? 0)}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Exceptions</span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Available Slots</span>
          <p className="text-2xl font-black text-indigo-400 mt-1">{stats?.available_slots ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Open to Book</span>
        </div>
      </div>

      {/* Filter Bar */}
      <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 flex flex-col md:flex-row items-center justify-between gap-3">
        <div className="w-full md:w-80 relative">
          <span className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs">🔍</span>
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search student, booking code, interviewer..."
            className="w-full bg-slate-900 border border-slate-800 rounded-xl pl-9 pr-3 py-2 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
          />
        </div>

        <div className="flex items-center gap-2.5 w-full md:w-auto flex-wrap">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="bg-slate-900 border border-slate-800 text-slate-300 text-xs font-semibold rounded-xl px-3 py-2 focus:outline-none focus:border-purple-500"
          >
            <option value="all">All Statuses</option>
            <option value="booked">Booked</option>
            <option value="confirmed">Confirmed</option>
            <option value="completed">Completed</option>
            <option value="rescheduled">Rescheduled</option>
            <option value="cancelled">Cancelled</option>
            <option value="no_show">No Show</option>
          </select>

          <select
            value={interviewerFilter}
            onChange={(e) => setInterviewerFilter(e.target.value)}
            className="bg-slate-900 border border-slate-800 text-slate-300 text-xs font-semibold rounded-xl px-3 py-2 focus:outline-none focus:border-purple-500"
          >
            <option value="all">All Interviewers</option>
            {interviewers.map((i) => (
              <option key={i.id} value={i.id}>
                {i.name} ({i.company})
              </option>
            ))}
          </select>

          <input
            type="date"
            value={dateFilter}
            onChange={(e) => setDateFilter(e.target.value)}
            className="bg-slate-900 border border-slate-800 text-slate-300 text-xs font-semibold rounded-xl px-3 py-2 focus:outline-none focus:border-purple-500"
          />

          {(search || statusFilter !== 'all' || interviewerFilter !== 'all' || dateFilter) && (
            <button
              type="button"
              onClick={() => {
                setSearch('')
                setStatusFilter('all')
                setInterviewerFilter('all')
                setDateFilter('')
              }}
              className="text-xs text-purple-400 hover:text-purple-300 font-bold px-2 py-1"
            >
              Reset
            </button>
          )}
        </div>
      </div>

      {/* Bookings Table */}
      <div className="bg-slate-950 border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
        {loading ? (
          <div className="py-20 text-center text-slate-400 space-y-3">
            <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin mx-auto" />
            <p className="text-xs font-semibold">Loading mock interview bookings...</p>
          </div>
        ) : bookings.length === 0 ? (
          <div className="py-20 text-center text-slate-400 space-y-2">
            <span className="text-3xl block">📋</span>
            <p className="text-sm font-bold text-white">No Mock Interview Bookings Found</p>
            <p className="text-xs text-slate-500">Student bookings and scheduled interview sessions will appear here.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead>
                <tr className="border-b border-slate-800 bg-slate-900/60 text-[10px] font-black uppercase tracking-wider text-slate-400">
                  <th className="py-3.5 px-4">Booking Code</th>
                  <th className="py-3.5 px-4">Student Candidate</th>
                  <th className="py-3.5 px-4">Professional Interviewer</th>
                  <th className="py-3.5 px-4">Scheduled Date & Time</th>
                  <th className="py-3.5 px-4">Status</th>
                  <th className="py-3.5 px-4">Scorecard / Result</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-850">
                {bookings.map((item) => {
                  const evalItem = item.evaluation
                  const statusStyle = statusStyles[item.status] || 'bg-slate-900 text-slate-400 border-slate-700'

                  return (
                    <tr key={item.id} className="hover:bg-slate-900/40 transition">
                      <td className="py-3.5 px-4 font-mono font-bold text-purple-300">
                        {item.booking_code}
                      </td>

                      <td className="py-3.5 px-4">
                        <div className="font-bold text-white">{item.student?.name || 'Unknown Student'}</div>
                        <div className="text-[11px] text-slate-400">{item.student?.email}</div>
                        {item.batch && (
                          <span className="inline-block mt-0.5 text-[9px] font-extrabold uppercase px-1.5 py-0.2 rounded bg-slate-800 text-slate-300">
                            {item.batch.code}
                          </span>
                        )}
                      </td>

                      <td className="py-3.5 px-4">
                        <div className="font-bold text-slate-200">{item.interviewer?.name || 'Unassigned'}</div>
                        <div className="text-[11px] text-purple-400 font-semibold">
                          {item.interviewer?.designation} • {item.interviewer?.company}
                        </div>
                      </td>

                      <td className="py-3.5 px-4">
                        <div className="font-bold text-slate-200">
                          {item.scheduled_at ? new Date(item.scheduled_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : 'N/A'}
                        </div>
                        <div className="text-[11px] text-slate-400 font-mono">
                          {item.scheduled_at ? new Date(item.scheduled_at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : ''}
                        </div>
                        {item.slot?.meeting_link && (
                          <a
                            href={item.slot.meeting_link}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-[10px] text-blue-400 hover:text-blue-300 underline font-semibold flex items-center gap-1 mt-0.5"
                          >
                            <span>🔗</span> Join Meeting
                          </a>
                        )}
                      </td>

                      <td className="py-3.5 px-4">
                        <span className={`inline-block px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${statusStyle}`}>
                          {item.status.replace('_', ' ')}
                        </span>
                      </td>

                      <td className="py-3.5 px-4">
                        {evalItem ? (
                          <div className="space-y-0.5">
                            <span
                              className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-black border ${
                                evalItem.recommendation === 'Ready for Placement'
                                  ? 'bg-emerald-950 text-emerald-300 border-emerald-600'
                                  : evalItem.recommendation === 'Needs Improvement'
                                  ? 'bg-amber-950 text-amber-300 border-amber-600'
                                  : 'bg-rose-950 text-rose-300 border-rose-600'
                              }`}
                            >
                              {evalItem.recommendation}
                            </span>
                            <div className="text-[11px] font-extrabold text-slate-300">
                              ⭐ {evalItem.overall_rating} / 10
                            </div>
                          </div>
                        ) : (
                          <span className="text-[11px] text-slate-500 italic">Pending Evaluation</span>
                        )}
                      </td>

                      <td className="py-3.5 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5 flex-wrap">
                          <button
                            type="button"
                            onClick={() => setEvaluatingInterview(item)}
                            className="px-2.5 py-1 rounded-lg text-[11px] font-bold text-white bg-purple-600 hover:bg-purple-500 transition shadow-sm"
                          >
                            {evalItem ? '📝 Edit Scorecard' : '✍️ Evaluate'}
                          </button>

                          {item.status === 'booked' && (
                            <button
                              type="button"
                              onClick={() => handleUpdateStatus(item.id, 'confirmed')}
                              className="px-2 py-1 rounded-lg text-[11px] font-semibold text-cyan-300 bg-cyan-950/60 hover:bg-cyan-900/80 border border-cyan-800 transition"
                              title="Confirm Session"
                            >
                              ✓ Confirm
                            </button>
                          )}

                          {item.status !== 'completed' && item.status !== 'cancelled' && (
                            <>
                              <button
                                type="button"
                                onClick={() => {
                                  setReassigningInterview(item)
                                  setSelectedNewInterviewerId(item.interviewer_id || '')
                                }}
                                className="px-2 py-1 rounded-lg text-[11px] font-semibold text-slate-300 bg-slate-800 hover:bg-slate-700 transition"
                                title="Reassign Interviewer"
                              >
                                👤 Reassign
                              </button>

                              <button
                                type="button"
                                onClick={() => {
                                  setReschedulingInterview(item)
                                  fetchSlotsForReschedule()
                                }}
                                className="px-2 py-1 rounded-lg text-[11px] font-semibold text-slate-300 bg-slate-800 hover:bg-slate-700 transition"
                                title="Reschedule Slot"
                              >
                                🔄 Reschedule
                              </button>

                              <button
                                type="button"
                                onClick={() => setCancellingInterview(item)}
                                className="px-2 py-1 rounded-lg text-[11px] font-semibold text-rose-400 hover:text-rose-300 bg-rose-950/40 hover:bg-rose-950/80 border border-rose-850 transition"
                                title="Cancel Booking"
                              >
                                ✕ Cancel
                              </button>
                            </>
                          )}
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

      {/* Evaluation Scorecard Modal */}
      {evaluatingInterview && (
        <EvaluationModal
          interview={evaluatingInterview}
          existingEvaluation={evaluatingInterview.evaluation}
          onClose={() => setEvaluatingInterview(null)}
          onSuccess={() => {
            showMsg('Scorecard submitted and placement eligibility updated!')
            setEvaluatingInterview(null)
            fetchBookings()
            fetchStats()
          }}
        />
      )}

      {/* Reassign Interviewer Modal */}
      {reassigningInterview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 className="text-base font-black text-white flex items-center gap-2">
              <span>👤</span> Reassign Interviewer
            </h3>
            <p className="text-xs text-slate-400">
              Select a new professional interviewer for candidate <span className="text-white font-bold">{reassigningInterview.student?.name}</span>.
            </p>

            <form onSubmit={handleReassign} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Select Interviewer</label>
                <select
                  required
                  value={selectedNewInterviewerId}
                  onChange={(e) => setSelectedNewInterviewerId(Number(e.target.value))}
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                >
                  <option value="">-- Choose Interviewer --</option>
                  {interviewers.filter((i) => i.is_active).map((i) => (
                    <option key={i.id} value={i.id}>
                      {i.name} — {i.designation} ({i.company})
                    </option>
                  ))}
                </select>
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setReassigningInterview(null)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !selectedNewInterviewerId}
                  className="px-4 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition"
                >
                  {saving ? 'Saving...' : 'Confirm Reassign'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Reschedule Slot Modal */}
      {reschedulingInterview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-4">
            <h3 className="text-base font-black text-white flex items-center gap-2">
              <span>🔄</span> Reschedule Mock Interview
            </h3>
            <p className="text-xs text-slate-400">
              Pick a new available slot for candidate <span className="text-white font-bold">{reschedulingInterview.student?.name}</span>.
            </p>

            <form onSubmit={handleReschedule} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Available Slots</label>
                {availableSlots.length === 0 ? (
                  <p className="text-xs text-amber-400 bg-amber-950/40 p-3 rounded-xl border border-amber-800">
                    No available open slots found. Please create a new slot in the Slots tab first.
                  </p>
                ) : (
                  <select
                    required
                    value={selectedNewSlotId}
                    onChange={(e) => setSelectedNewSlotId(Number(e.target.value))}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  >
                    <option value="">-- Choose New Slot --</option>
                    {availableSlots.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.slot_date} at {s.start_time} ({s.duration_minutes}m) — {s.interviewer?.name} ({s.interviewer?.company})
                      </option>
                    ))}
                  </select>
                )}
              </div>

              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Reschedule Reason (Optional)</label>
                <input
                  type="text"
                  value={rescheduleReason}
                  onChange={(e) => setRescheduleReason(e.target.value)}
                  placeholder="e.g. Student requested time change"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setReschedulingInterview(null)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !selectedNewSlotId}
                  className="px-4 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition"
                >
                  {saving ? 'Rescheduling...' : 'Confirm Reschedule'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Cancel Booking Modal */}
      {cancellingInterview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 className="text-base font-black text-rose-400 flex items-center gap-2">
              <span>✕</span> Cancel Mock Interview Booking
            </h3>
            <p className="text-xs text-slate-400">
              Are you sure you want to cancel booking <span className="font-mono text-purple-300 font-bold">{cancellingInterview.booking_code}</span> for <span className="text-white font-bold">{cancellingInterview.student?.name}</span>? The slot will be restored to available.
            </p>

            <form onSubmit={handleCancel} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">
                  Cancellation Reason <span className="text-rose-400">*</span>
                </label>
                <textarea
                  rows={2}
                  required
                  value={cancelReason}
                  onChange={(e) => setCancelReason(e.target.value)}
                  placeholder="State the reason for cancellation..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-rose-500"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setCancellingInterview(null)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Keep Booking
                </button>
                <button
                  type="submit"
                  disabled={saving || !cancelReason.trim()}
                  className="px-4 py-2 rounded-xl text-xs font-extrabold text-white bg-rose-600 hover:bg-rose-500 transition"
                >
                  {saving ? 'Cancelling...' : 'Confirm Cancellation'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
