import { useState, useEffect, useCallback } from 'react'
import API from '../../../services/api'
import type { AdminMockSlotItem, AdminMockInterviewerItem } from '../../../types/mockInterview'

export default function SlotsTab() {
  const [slots, setSlots] = useState<AdminMockSlotItem[]>([])
  const [interviewers, setInterviewers] = useState<AdminMockInterviewerItem[]>([])
  const [loading, setLoading] = useState(true)

  // Filters
  const [interviewerFilter, setInterviewerFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('all')
  const [dateFilter, setDateFilter] = useState('')

  // Create Slot Modal
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [interviewerId, setInterviewerId] = useState<number | ''>('')
  const [slotDate, setSlotDate] = useState('')
  const [startTime, setStartTime] = useState('10:00')
  const [endTime, setEndTime] = useState('10:45')
  const [duration, setDuration] = useState<number>(45)
  const [meetingLink, setMeetingLink] = useState('https://meet.google.com/mit-mock-')
  const [platform, setPlatform] = useState('Google Meet')
  const [instructions, setInstructions] = useState('Please join with camera on and have your code editor / IDE ready.')

  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<{ text: string; type: 'success' | 'error' } | null>(null)

  const showMsg = (text: string, type: 'success' | 'error' = 'success') => {
    setMsg({ text, type })
    setTimeout(() => setMsg(null), 4500)
  }

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

  const fetchSlots = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      if (interviewerFilter !== 'all') params.append('interviewer_id', interviewerFilter)
      if (statusFilter !== 'all') params.append('status', statusFilter)
      if (dateFilter) params.append('slot_date', dateFilter)

      const res = await API.get<{ data?: AdminMockSlotItem[] } | AdminMockSlotItem[]>(
        `/admin/mock-interviews/slots?${params.toString()}`
      )
      const list = Array.isArray(res.data) ? res.data : res.data?.data || []
      setSlots(list)
    } catch {
      showMsg('Failed to load slots.', 'error')
    } finally {
      setLoading(false)
    }
  }, [interviewerFilter, statusFilter, dateFilter])

  useEffect(() => {
    fetchInterviewers()
    fetchSlots()
  }, [fetchInterviewers, fetchSlots])

  const openCreateModal = () => {
    setInterviewerId(interviewers.filter((i) => i.is_active)[0]?.id || '')
    const tomorrow = new Date()
    tomorrow.setDate(tomorrow.getDate() + 1)
    setSlotDate(tomorrow.toISOString().split('T')[0])
    setStartTime('10:00')
    setEndTime('10:45')
    setDuration(45)
    setMeetingLink('https://meet.google.com/mit-mock-' + Math.random().toString(36).substring(2, 6))
    setPlatform('Google Meet')
    setInstructions('Please join with camera on and have your code editor / IDE ready.')
    setIsModalOpen(true)
  }

  const handleStartTimeChange = (val: string) => {
    setStartTime(val)
    const [h, m] = val.split(':').map(Number)
    const endMinutes = h * 60 + m + duration
    const endH = String(Math.floor(endMinutes / 60) % 24).padStart(2, '0')
    const endM = String(endMinutes % 60).padStart(2, '0')
    setEndTime(`${endH}:${endM}`)
  }

  const handleDurationChange = (d: number) => {
    setDuration(d)
    const [h, m] = startTime.split(':').map(Number)
    const endMinutes = h * 60 + m + d
    const endH = String(Math.floor(endMinutes / 60) % 24).padStart(2, '0')
    const endM = String(endMinutes % 60).padStart(2, '0')
    setEndTime(`${endH}:${endM}`)
  }

  const handleCreateSlot = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!interviewerId) {
      showMsg('Please select an interviewer.', 'error')
      return
    }

    setSaving(true)
    try {
      await API.post('/admin/mock-interviews/slots', {
        interviewer_id: Number(interviewerId),
        slot_date: slotDate,
        start_time: startTime,
        end_time: endTime,
        duration_minutes: duration,
        meeting_link: meetingLink.trim() || null,
        platform: platform.trim() || 'Google Meet',
        instructions: instructions.trim() || null,
      })

      showMsg('Interview slot created successfully!')
      setIsModalOpen(false)
      fetchSlots()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to create slot.', 'error')
    } finally {
      setSaving(false)
    }
  }

  const handleDeleteSlot = async (slot: AdminMockSlotItem) => {
    if (!window.confirm(`Delete slot on ${slot.slot_date} at ${slot.start_time}?`)) return
    try {
      await API.delete(`/admin/mock-interviews/slots/${slot.id}`)
      showMsg('Slot deleted successfully.')
      fetchSlots()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to delete slot.', 'error')
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

      {/* Header with Filters & Create Button */}
      <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-950 p-4 rounded-2xl border border-slate-800">
        <div className="flex items-center gap-2.5 w-full sm:w-auto flex-wrap">
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

          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="bg-slate-900 border border-slate-800 text-slate-300 text-xs font-semibold rounded-xl px-3 py-2 focus:outline-none focus:border-purple-500"
          >
            <option value="all">All Status</option>
            <option value="available">Available</option>
            <option value="booked">Booked</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
          </select>

          <input
            type="date"
            value={dateFilter}
            onChange={(e) => setDateFilter(e.target.value)}
            className="bg-slate-900 border border-slate-800 text-slate-300 text-xs font-semibold rounded-xl px-3 py-2 focus:outline-none focus:border-purple-500"
          />

          {(interviewerFilter !== 'all' || statusFilter !== 'all' || dateFilter) && (
            <button
              type="button"
              onClick={() => {
                setInterviewerFilter('all')
                setStatusFilter('all')
                setDateFilter('')
              }}
              className="text-xs text-purple-400 hover:text-purple-300 font-bold px-2 py-1"
            >
              Reset
            </button>
          )}
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="w-full sm:w-auto px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center justify-center gap-2"
        >
          <span>➕</span>
          <span>Create Interview Slot</span>
        </button>
      </div>

      {/* Slots Table */}
      <div className="bg-slate-950 border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
        {loading ? (
          <div className="py-20 text-center text-slate-400 space-y-3">
            <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin mx-auto" />
            <p className="text-xs font-semibold">Loading slots schedule...</p>
          </div>
        ) : slots.length === 0 ? (
          <div className="py-20 text-center text-slate-400 space-y-2">
            <span className="text-3xl block">🕒</span>
            <p className="text-sm font-bold text-white">No Interview Slots Found</p>
            <p className="text-xs text-slate-500">Create time slots for professional interviewers to enable student bookings.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead>
                <tr className="border-b border-slate-800 bg-slate-900/60 text-[10px] font-black uppercase tracking-wider text-slate-400">
                  <th className="py-3.5 px-4">Slot Date & Time</th>
                  <th className="py-3.5 px-4">Interviewer</th>
                  <th className="py-3.5 px-4">Duration & Platform</th>
                  <th className="py-3.5 px-4">Meeting Link</th>
                  <th className="py-3.5 px-4">Status</th>
                  <th className="py-3.5 px-4">Booked Student</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-850">
                {slots.map((slot) => {
                  return (
                    <tr key={slot.id} className="hover:bg-slate-900/40 transition">
                      <td className="py-3.5 px-4 font-semibold text-slate-200">
                        <div className="font-bold text-white">{slot.slot_date}</div>
                        <div className="text-[11px] text-purple-300 font-mono">
                          {slot.start_time} - {slot.end_time}
                        </div>
                      </td>

                      <td className="py-3.5 px-4">
                        <div className="font-bold text-white">{slot.interviewer?.name || 'Unknown'}</div>
                        <div className="text-[11px] text-slate-400">
                          {slot.interviewer?.designation} • {slot.interviewer?.company}
                        </div>
                      </td>

                      <td className="py-3.5 px-4">
                        <div className="text-slate-300 font-semibold">{slot.duration_minutes} Minutes</div>
                        <div className="text-[11px] text-slate-400">{slot.platform}</div>
                      </td>

                      <td className="py-3.5 px-4">
                        {slot.meeting_link ? (
                          <a
                            href={slot.meeting_link}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-blue-400 hover:text-blue-300 underline font-semibold flex items-center gap-1"
                          >
                            <span>🔗</span> {slot.meeting_link.length > 28 ? slot.meeting_link.substring(0, 28) + '...' : slot.meeting_link}
                          </a>
                        ) : (
                          <span className="text-slate-500 italic">No link</span>
                        )}
                      </td>

                      <td className="py-3.5 px-4">
                        <span
                          className={`inline-block px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${
                            slot.status === 'available'
                              ? 'bg-emerald-950/80 text-emerald-300 border-emerald-700'
                              : slot.status === 'booked'
                              ? 'bg-blue-950/80 text-blue-300 border-blue-700'
                              : slot.status === 'completed'
                              ? 'bg-purple-950/80 text-purple-300 border-purple-700'
                              : 'bg-rose-950/80 text-rose-300 border-rose-800'
                          }`}
                        >
                          {slot.status}
                        </span>
                      </td>

                      <td className="py-3.5 px-4">
                        {slot.interview?.student ? (
                          <div>
                            <div className="font-bold text-white">{slot.interview.student.name}</div>
                            <div className="text-[10px] text-purple-300 font-mono">{slot.interview.booking_code}</div>
                          </div>
                        ) : (
                          <span className="text-slate-500 italic">—</span>
                        )}
                      </td>

                      <td className="py-3.5 px-4 text-right">
                        {slot.status === 'available' && (
                          <button
                            type="button"
                            onClick={() => handleDeleteSlot(slot)}
                            className="px-2.5 py-1 rounded-lg text-xs font-semibold text-rose-400 hover:text-rose-300 bg-rose-950/40 hover:bg-rose-950 border border-rose-900/60 transition"
                          >
                            Delete
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Create Slot Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h3 className="text-base font-black text-white flex items-center gap-2">
                <span>➕</span>
                <span>Create Mock Interview Slot</span>
              </h3>
              <button
                type="button"
                onClick={() => setIsModalOpen(false)}
                className="text-slate-400 hover:text-white"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleCreateSlot} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">
                  Assign Professional Interviewer <span className="text-rose-400">*</span>
                </label>
                <select
                  required
                  value={interviewerId}
                  onChange={(e) => setInterviewerId(Number(e.target.value))}
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                >
                  <option value="">-- Choose Active Interviewer --</option>
                  {interviewers.filter((i) => i.is_active).map((i) => (
                    <option key={i.id} value={i.id}>
                      {i.name} — {i.designation} ({i.company})
                    </option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Slot Date <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="date"
                    required
                    value={slotDate}
                    onChange={(e) => setSlotDate(e.target.value)}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>

                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Duration (Minutes) <span className="text-rose-400">*</span>
                  </label>
                  <select
                    value={duration}
                    onChange={(e) => handleDurationChange(Number(e.target.value))}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  >
                    <option value={30}>30 Minutes</option>
                    <option value={45}>45 Minutes (Recommended)</option>
                    <option value={60}>60 Minutes</option>
                    <option value={90}>90 Minutes</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Start Time <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="time"
                    required
                    value={startTime}
                    onChange={(e) => handleStartTimeChange(e.target.value)}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>

                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    End Time <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="time"
                    required
                    value={endTime}
                    onChange={(e) => setEndTime(e.target.value)}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>
              </div>

              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Meeting Link / URL</label>
                <input
                  type="url"
                  value={meetingLink}
                  onChange={(e) => setMeetingLink(e.target.value)}
                  placeholder="https://meet.google.com/xyz-abcd-efg"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Student Instructions</label>
                <input
                  type="text"
                  value={instructions}
                  onChange={(e) => setInstructions(e.target.value)}
                  placeholder="Preparation advice for student..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="flex justify-end gap-2.5 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setIsModalOpen(false)}
                  disabled={saving}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-1.5"
                >
                  {saving ? 'Creating...' : '✓ Save Slot'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
