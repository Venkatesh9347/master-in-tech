import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import type {
  AdminMockInterviewItem,
  AdminMockSlotItem,
  StudentEligibilityData,
} from '../types/mockInterview'

export default function StudentMockInterview() {

  const [eligibility, setEligibility] = useState<StudentEligibilityData | null>(null)
  const [slots, setSlots] = useState<AdminMockSlotItem[]>([])
  const [myInterviews, setMyInterviews] = useState<AdminMockInterviewItem[]>([])
  const [loading, setLoading] = useState(true)
  const [activeTab, setActiveTab] = useState<'my_interview' | 'available_slots' | 'history'>('my_interview')

  // Slot Filtering
  const [selectedDate, setSelectedDate] = useState('')
  const [selectedInterviewer, setSelectedInterviewer] = useState('all')

  // Booking Modal
  const [bookingSlot, setBookingSlot] = useState<AdminMockSlotItem | null>(null)
  const [studentNotes, setStudentNotes] = useState('')
  const [bookingInProgress, setBookingInProgress] = useState(false)

  // Reschedule / Cancel Modal
  const [reschedulingInterview, setReschedulingInterview] = useState<AdminMockInterviewItem | null>(null)
  const [newSlotId, setNewSlotId] = useState<number | ''>('')
  const [rescheduleReason, setRescheduleReason] = useState('')
  const [cancellingInterview, setCancellingInterview] = useState<AdminMockInterviewItem | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const [actionInProgress, setActionInProgress] = useState(false)

  const [msg, setMsg] = useState<{ text: string; type: 'success' | 'error' } | null>(null)

  const showMsg = (text: string, type: 'success' | 'error' = 'success') => {
    setMsg({ text, type })
    setTimeout(() => setMsg(null), 5000)
  }

  const fetchEligibility = useCallback(async () => {
    try {
      const res = await API.get<StudentEligibilityData>('/student/mock-interviews/eligibility')
      setEligibility(res.data)
    } catch {
      // ignore
    }
  }, [])

  const fetchSlots = useCallback(async () => {
    try {
      const params = new URLSearchParams()
      if (selectedDate) params.append('date', selectedDate)
      if (selectedInterviewer !== 'all') params.append('interviewer_id', selectedInterviewer)

      const res = await API.get<AdminMockSlotItem[]>(`/student/mock-interviews/slots?${params.toString()}`)
      setSlots(Array.isArray(res.data) ? res.data : [])
    } catch {
      // ignore
    }
  }, [selectedDate, selectedInterviewer])

  const fetchMyInterviews = useCallback(async () => {
    try {
      const res = await API.get<AdminMockInterviewItem[]>('/student/mock-interviews/my-interviews')
      const list = Array.isArray(res.data) ? res.data : []
      setMyInterviews(list)
    } catch {
      // ignore
    }
  }, [])

  const loadAll = useCallback(async () => {
    setLoading(true)
    await Promise.all([fetchEligibility(), fetchSlots(), fetchMyInterviews()])
    setLoading(false)
  }, [fetchEligibility, fetchSlots, fetchMyInterviews])

  useEffect(() => {
    loadAll()
  }, [loadAll])

  const activeBooking = myInterviews.find(
    (i) => i.status === 'booked' || i.status === 'confirmed' || i.status === 'rescheduled'
  )
  const latestCompleted = myInterviews.find((i) => i.status === 'completed' && i.evaluation)

  const handleBookSlot = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!bookingSlot) return
    setBookingInProgress(true)

    try {
      await API.post('/student/mock-interviews/book', {
        slot_id: bookingSlot.id,
        student_notes: studentNotes.trim() || undefined,
      })

      showMsg('🎉 Your Mock Interview has been booked successfully! Review details below.')
      setBookingSlot(null)
      setStudentNotes('')
      setActiveTab('my_interview')
      loadAll()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to book slot.', 'error')
    } finally {
      setBookingInProgress(false)
    }
  }

  const handleCancelBooking = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!cancellingInterview || !cancelReason.trim()) return
    setActionInProgress(true)

    try {
      await API.post(`/student/mock-interviews/${cancellingInterview.id}/cancel`, {
        reason: cancelReason.trim(),
      })

      showMsg('Your mock interview booking has been cancelled.')
      setCancellingInterview(null)
      setCancelReason('')
      loadAll()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to cancel booking.', 'error')
    } finally {
      setActionInProgress(false)
    }
  }

  const handleRescheduleBooking = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!reschedulingInterview || !newSlotId) return
    setActionInProgress(true)

    try {
      await API.post(`/student/mock-interviews/${reschedulingInterview.id}/reschedule`, {
        slot_id: newSlotId,
        reason: rescheduleReason.trim() || undefined,
      })

      showMsg('Your mock interview has been rescheduled successfully!')
      setReschedulingInterview(null)
      setNewSlotId('')
      setRescheduleReason('')
      loadAll()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to reschedule interview.', 'error')
    } finally {
      setActionInProgress(false)
    }
  }

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100 flex flex-col selection:bg-purple-600 selection:text-white">
      <Navbar />

      <main className="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full space-y-8">
        {/* Top Header Banner */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-r from-slate-950 via-purple-950/40 to-slate-950 border border-purple-900/40 p-6 sm:p-8 rounded-3xl shadow-xl">
          <div>
            <div className="flex items-center gap-2 mb-2">
              <span className="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-purple-950 text-purple-300 border border-purple-800">
                Phase P2 Mandatory Workflow
              </span>
              {eligibility?.placement_eligible && (
                <span className="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-950 text-emerald-300 border border-emerald-700">
                  🌟 Placement Eligible
                </span>
              )}
            </div>
            <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight flex items-center gap-2.5">
              <span>🎙️</span> Mandatory Mock Interview Portal
            </h1>
            <p className="text-slate-400 text-sm mt-1 max-w-2xl">
              1-on-1 industry technical interviews with senior tech leads from top IT companies. Meet the benchmark scorecard to unlock active placement drives.
            </p>
          </div>

          <div className="flex items-center gap-3 self-start md:self-auto flex-wrap">
            <Link
              to="/student"
              className="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-300 bg-slate-900 hover:bg-slate-800 border border-slate-800 transition flex items-center gap-1.5"
            >
              <span>🎓</span> Student Dashboard
            </Link>

            {eligibility?.placement_dashboard_enabled ? (
              <Link
                to="/placements"
                className="px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-600/30 transition flex items-center gap-2"
              >
                <span>🚀</span> Enter Placement Portal
              </Link>
            ) : eligibility?.placement_dashboard_status === 'SUSPENDED' ? (
              <span className="px-4 py-2 rounded-xl text-xs font-bold text-rose-300 bg-rose-950/60 border border-rose-800">
                ⛔ Dashboard Suspended
              </span>
            ) : eligibility?.is_eligible_for_activation ? (
              <span className="px-4 py-2 rounded-xl text-xs font-bold text-amber-300 bg-amber-950/60 border border-amber-800">
                ⏳ Pending Admin Activation
              </span>
            ) : null}
          </div>
        </div>

        {msg && (
          <div
            className={`px-5 py-3.5 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg ${
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

        {/* Step Progression Bar */}
        <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
          <div
            className={`p-4 rounded-2xl border transition ${
              eligibility?.course_completed || eligibility?.is_admin_override
                ? 'bg-emerald-950/40 border-emerald-800/80 text-emerald-300'
                : 'bg-slate-950 border-slate-800 text-slate-400'
            }`}
          >
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold uppercase tracking-wider">Step 1</span>
              <span>{eligibility?.course_completed ? '✓' : '🔒'}</span>
            </div>
            <p className="font-bold text-white text-xs mt-1">100% Course Completed</p>
            <span className="text-[10px] text-slate-400 block mt-0.5">Curriculum milestone</span>
          </div>

          <div
            className={`p-4 rounded-2xl border transition ${
              activeBooking || latestCompleted
                ? 'bg-purple-950/40 border-purple-800/80 text-purple-300'
                : eligibility?.is_eligible
                ? 'bg-slate-950 border-purple-800/50 text-slate-300'
                : 'bg-slate-950 border-slate-800 text-slate-500 opacity-60'
            }`}
          >
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold uppercase tracking-wider">Step 2</span>
              <span>{activeBooking || latestCompleted ? '✓' : '📅'}</span>
            </div>
            <p className="font-bold text-white text-xs mt-1">Book 1-on-1 Interview Slot</p>
            <span className="text-[10px] text-slate-400 block mt-0.5">Select senior interviewer</span>
          </div>

          <div
            className={`p-4 rounded-2xl border transition ${
              latestCompleted
                ? 'bg-cyan-950/40 border-cyan-800/80 text-cyan-300'
                : activeBooking
                ? 'bg-slate-950 border-cyan-800/50 text-slate-300'
                : 'bg-slate-950 border-slate-800 text-slate-500 opacity-60'
            }`}
          >
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold uppercase tracking-wider">Step 3</span>
              <span>{latestCompleted ? '✓' : '🎙️'}</span>
            </div>
            <p className="font-bold text-white text-xs mt-1">Interview Assessment</p>
            <span className="text-[10px] text-slate-400 block mt-0.5">6-factor scoring radar</span>
          </div>

          <div
            className={`p-4 rounded-2xl border transition ${
              eligibility?.placement_eligible
                ? 'bg-emerald-950/60 border-emerald-600 text-emerald-300 shadow-md shadow-emerald-950'
                : 'bg-slate-950 border-slate-800 text-slate-500 opacity-60'
            }`}
          >
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold uppercase tracking-wider">Step 4</span>
              <span>{eligibility?.placement_eligible ? '🌟' : '🔒'}</span>
            </div>
            <p className="font-bold text-white text-xs mt-1">Placement Gateway</p>
            <span className="text-[10px] text-slate-400 block mt-0.5">Ready for Placement</span>
          </div>
        </div>

        {/* Tab Navigation */}
        <div className="flex items-center gap-2 border-b border-slate-800 pb-2">
          <button
            type="button"
            onClick={() => setActiveTab('my_interview')}
            className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
              activeTab === 'my_interview'
                ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                : 'text-slate-400 hover:text-white hover:bg-slate-950'
            }`}
          >
            <span>🎙️</span>
            <span>My Active Interview & Result</span>
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('available_slots')}
            className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
              activeTab === 'available_slots'
                ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                : 'text-slate-400 hover:text-white hover:bg-slate-950'
            }`}
          >
            <span>📅</span>
            <span>Available Interview Slots ({slots.length})</span>
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('history')}
            className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
              activeTab === 'history'
                ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                : 'text-slate-400 hover:text-white hover:bg-slate-950'
            }`}
          >
            <span>📜</span>
            <span>History & Evaluations ({myInterviews.length})</span>
          </button>
        </div>

        {/* TAB 1: MY ACTIVE INTERVIEW & ELIGIBILITY */}
        {activeTab === 'my_interview' && (
          <div className="space-y-6">
            {/* Eligibility Banner / Lock Card */}
            {!eligibility?.is_eligible && (
              <div className="bg-amber-950/40 border border-amber-800 rounded-3xl p-6 sm:p-8 shadow-xl space-y-4">
                <div className="flex items-start gap-4">
                  <span className="text-3xl p-2 rounded-2xl bg-amber-900/40 border border-amber-700 text-amber-300">
                    🔒
                  </span>
                  <div>
                    <h2 className="text-lg font-black text-white">Mock Interview Gateway Locked</h2>
                    <p className="text-xs text-amber-200 mt-1 leading-relaxed">
                      You must complete 100% of your course lessons or earn a course completion certificate before scheduling your mandatory mock interview.
                    </p>
                  </div>
                </div>

                {eligibility?.courses && eligibility.courses.length > 0 && (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                    {eligibility.courses.map((c, idx) => (
                      <div key={idx} className="bg-slate-950/80 p-4 rounded-2xl border border-slate-800 space-y-2">
                        <div className="flex justify-between items-center text-xs">
                          <span className="font-bold text-white">{c.course_title}</span>
                          <span className="font-black text-purple-400">{c.progress_percentage}%</span>
                        </div>
                        <div className="w-full bg-slate-900 h-2 rounded-full overflow-hidden border border-slate-800">
                          <div
                            className="bg-purple-600 h-full rounded-full transition-all"
                            style={{ width: `${Math.min(100, c.progress_percentage)}%` }}
                          />
                        </div>
                        <div className="flex justify-between items-center text-[11px] text-slate-400">
                          <span>{c.completed_lessons} / {c.total_lessons} lessons completed</span>
                          <Link
                            to={`/student/courses/${c.course_id}/lessons`}
                            className="text-purple-400 hover:text-purple-300 font-bold underline"
                          >
                            Continue Learning →
                          </Link>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* Active Booking Card */}
            {activeBooking ? (
              <div className="bg-gradient-to-br from-slate-950 via-slate-900 to-purple-950/40 border border-purple-700/60 rounded-3xl p-6 sm:p-8 shadow-2xl space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-5">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-purple-950 text-purple-300 border border-purple-700">
                        {activeBooking.status.toUpperCase()}
                      </span>
                      <span className="font-mono text-xs text-purple-400 font-bold">
                        Code: {activeBooking.booking_code}
                      </span>
                    </div>
                    <h2 className="text-xl sm:text-2xl font-black text-white mt-1">
                      Upcoming 1-on-1 Mock Interview Session
                    </h2>
                  </div>

                  {activeBooking.slot?.meeting_link && (
                    <a
                      href={activeBooking.slot.meeting_link}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="px-5 py-3 rounded-2xl text-xs font-black text-white bg-purple-600 hover:bg-purple-500 shadow-lg shadow-purple-600/30 transition flex items-center gap-2 shrink-0 self-start sm:self-auto animate-pulse"
                    >
                      <span>📹</span>
                      <span>Join Live Video Meeting</span>
                    </a>
                  )}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  {/* Interviewer Details */}
                  <div className="bg-slate-950/80 p-5 rounded-2xl border border-slate-800 space-y-3">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-purple-400 block">
                      Assigned Professional Interviewer
                    </span>
                    <div>
                      <h3 className="text-base font-black text-white">{activeBooking.interviewer?.name}</h3>
                      <p className="text-xs text-purple-300 font-semibold">{activeBooking.interviewer?.designation}</p>
                      <p className="text-xs text-slate-400">{activeBooking.interviewer?.company} • {activeBooking.interviewer?.years_of_experience} yrs experience</p>
                    </div>

                    {activeBooking.interviewer?.skills && activeBooking.interviewer.skills.length > 0 && (
                      <div className="flex flex-wrap gap-1 pt-1">
                        {activeBooking.interviewer.skills.map((s, idx) => (
                          <span
                            key={idx}
                            className="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-900 text-purple-300 border border-slate-800"
                          >
                            {s}
                          </span>
                        ))}
                      </div>
                    )}
                  </div>

                  {/* Date & Instructions */}
                  <div className="bg-slate-950/80 p-5 rounded-2xl border border-slate-800 space-y-3">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-purple-400 block">
                      Schedule & Instructions
                    </span>
                    <div>
                      <p className="text-sm font-black text-white">
                        📅 {activeBooking.scheduled_at ? new Date(activeBooking.scheduled_at).toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : 'N/A'}
                      </p>
                      <p className="text-xs font-mono text-purple-300 font-bold mt-0.5">
                        🕒 {activeBooking.scheduled_at ? new Date(activeBooking.scheduled_at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : ''} ({activeBooking.slot?.duration_minutes ?? 45} Minutes Duration)
                      </p>
                    </div>

                    <p className="text-xs text-slate-400 leading-relaxed">
                      {activeBooking.slot?.instructions || 'Please test your microphone and webcam 5 minutes prior to session. Keep your IDE or coding environment ready.'}
                    </p>
                  </div>
                </div>

                {/* Actions */}
                <div className="flex items-center justify-between pt-2 border-t border-slate-800 flex-wrap gap-3">
                  <p className="text-xs text-slate-400">
                    Need to modify your appointment? You can reschedule or cancel before the session starts.
                  </p>

                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={() => {
                        setReschedulingInterview(activeBooking)
                        fetchSlots()
                      }}
                      className="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-200 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
                    >
                      🔄 Reschedule Slot
                    </button>

                    <button
                      type="button"
                      onClick={() => setCancellingInterview(activeBooking)}
                      className="px-3.5 py-2 rounded-xl text-xs font-bold text-rose-400 hover:text-rose-300 bg-rose-950/40 hover:bg-rose-950 border border-rose-900/60 transition"
                    >
                      ✕ Cancel Booking
                    </button>
                  </div>
                </div>
              </div>
            ) : null}

            {/* If Eligible and No Active Booking -> Prompt to Book Slot */}
            {eligibility?.is_eligible && !activeBooking && (
              <div className="bg-gradient-to-r from-purple-950/60 to-slate-950 border border-purple-800 rounded-3xl p-6 sm:p-8 shadow-xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                  <span className="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-950 text-emerald-300 border border-emerald-700">
                    ✓ Course Requirement Met
                  </span>
                  <h2 className="text-xl font-black text-white mt-1">You Are Ready to Book Your Mock Interview</h2>
                  <p className="text-xs text-slate-300 mt-1 max-w-xl">
                    Choose from available slots with industry interviewers. Practice live coding, system design, and behavioral questions to unlock full Placement Eligibility.
                  </p>
                </div>

                <button
                  type="button"
                  onClick={() => setActiveTab('available_slots')}
                  className="px-5 py-3 rounded-2xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-2 shrink-0"
                >
                  <span>📅</span>
                  <span>View Available Slots & Book</span>
                </button>
              </div>
            )}

            {/* Latest Evaluation Scorecard */}
            {latestCompleted?.evaluation && (
              <div className="bg-slate-950 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-4">
                  <div>
                    <span className="text-[10px] font-bold uppercase tracking-wider text-purple-400 block">
                      Evaluation Scorecard
                    </span>
                    <h3 className="text-lg font-black text-white">Latest Mock Interview Result</h3>
                  </div>

                  <div className="flex items-center gap-2">
                    <span
                      className={`px-3 py-1 rounded-full text-xs font-black border ${
                        latestCompleted.evaluation.recommendation === 'Ready for Placement'
                          ? 'bg-emerald-950 text-emerald-300 border-emerald-600 shadow-md shadow-emerald-950'
                          : 'bg-amber-950 text-amber-300 border-amber-600'
                      }`}
                    >
                      {latestCompleted.evaluation.recommendation}
                    </span>
                    <span className="text-base font-black text-purple-300 bg-purple-950/80 px-3 py-1 rounded-full border border-purple-800">
                      ⭐ {latestCompleted.evaluation.overall_rating} / 10
                    </span>
                  </div>
                </div>

                {/* 6-factor score cards */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                  <div className="bg-slate-900 p-3 rounded-2xl border border-slate-800 text-center">
                    <span className="text-[10px] text-slate-400 font-bold uppercase block">Technical</span>
                    <p className="text-lg font-black text-purple-300 mt-1">{latestCompleted.evaluation.technical_knowledge}/10</p>
                  </div>
                  <div className="bg-slate-900 p-3 rounded-2xl border border-slate-800 text-center">
                    <span className="text-[10px] text-slate-400 font-bold uppercase block">Coding</span>
                    <p className="text-lg font-black text-purple-300 mt-1">{latestCompleted.evaluation.programming_problem_solving}/10</p>
                  </div>
                  <div className="bg-slate-900 p-3 rounded-2xl border border-slate-800 text-center">
                    <span className="text-[10px] text-slate-400 font-bold uppercase block">Communication</span>
                    <p className="text-lg font-black text-purple-300 mt-1">{latestCompleted.evaluation.communication}/10</p>
                  </div>
                  <div className="bg-slate-900 p-3 rounded-2xl border border-slate-800 text-center">
                    <span className="text-[10px] text-slate-400 font-bold uppercase block">Confidence</span>
                    <p className="text-lg font-black text-purple-300 mt-1">{latestCompleted.evaluation.confidence}/10</p>
                  </div>
                  <div className="bg-slate-900 p-3 rounded-2xl border border-slate-800 text-center">
                    <span className="text-[10px] text-slate-400 font-bold uppercase block">Projects</span>
                    <p className="text-lg font-black text-purple-300 mt-1">{latestCompleted.evaluation.project_knowledge}/10</p>
                  </div>
                  <div className="bg-slate-900 p-3 rounded-2xl border border-slate-800 text-center">
                    <span className="text-[10px] text-slate-400 font-bold uppercase block">Readiness</span>
                    <p className="text-lg font-black text-purple-300 mt-1">{latestCompleted.evaluation.interview_readiness}/10</p>
                  </div>
                </div>

                {/* Qualitative Feedback */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-1">
                    <span className="text-[10px] font-extrabold uppercase tracking-wider text-emerald-400 block">
                      Key Strengths
                    </span>
                    <p className="text-xs text-slate-300 leading-relaxed">{latestCompleted.evaluation.strengths}</p>
                  </div>

                  <div className="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-1">
                    <span className="text-[10px] font-extrabold uppercase tracking-wider text-amber-400 block">
                      Areas to Improve
                    </span>
                    <p className="text-xs text-slate-300 leading-relaxed">{latestCompleted.evaluation.areas_for_improvement}</p>
                  </div>
                </div>

                {latestCompleted.evaluation.interviewer_remarks && (
                  <div className="bg-purple-950/30 p-4 rounded-2xl border border-purple-800/60 text-xs text-purple-200">
                    <span className="font-bold text-purple-300 block mb-0.5">Interviewer Remarks:</span>
                    <p>{latestCompleted.evaluation.interviewer_remarks}</p>
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        {/* TAB 2: AVAILABLE SLOTS & BOOKING */}
        {activeTab === 'available_slots' && (
          <div className="space-y-6">
            {!eligibility?.is_eligible && (
              <div className="bg-amber-950/40 border border-amber-800 p-4 rounded-2xl text-xs text-amber-200 font-semibold">
                🔒 You must complete your enrolled course lessons to book slots. You can browse available slots below in the meantime.
              </div>
            )}

            {/* Slot Filters */}
            <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 flex items-center justify-between gap-3 flex-wrap">
              <div className="flex items-center gap-2.5 flex-wrap">
                <input
                  type="date"
                  value={selectedDate}
                  onChange={(e) => setSelectedDate(e.target.value)}
                  className="bg-slate-900 border border-slate-800 text-slate-300 text-xs font-semibold rounded-xl px-3.5 py-2 focus:outline-none focus:border-purple-500"
                />

                {(selectedDate || selectedInterviewer !== 'all') && (
                  <button
                    type="button"
                    onClick={() => {
                      setSelectedDate('')
                      setSelectedInterviewer('all')
                    }}
                    className="text-xs text-purple-400 hover:text-purple-300 font-bold px-2 py-1"
                  >
                    Reset Filter
                  </button>
                )}
              </div>

              <span className="text-xs text-slate-400 font-semibold">
                Showing {slots.length} open slot(s)
              </span>
            </div>

            {/* Slots Grid */}
            {loading ? (
              <div className="py-20 text-center text-slate-400 space-y-3">
                <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin mx-auto" />
                <p className="text-xs font-semibold">Loading available interview slots...</p>
              </div>
            ) : slots.length === 0 ? (
              <div className="py-20 text-center text-slate-400 space-y-2 bg-slate-950 border border-slate-800 rounded-3xl p-8">
                <span className="text-3xl block">🕒</span>
                <p className="text-sm font-bold text-white">No Open Slots Available</p>
                <p className="text-xs text-slate-500">
                  New interview slots are posted weekly by MasterInTech career cell. Check back shortly.
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {slots.map((slot) => {
                  const interviewer = slot.interviewer

                  return (
                    <div
                      key={slot.id}
                      className="bg-slate-950 border border-slate-800 hover:border-purple-800/80 rounded-3xl p-5 space-y-4 shadow-lg transition flex flex-col justify-between"
                    >
                      <div className="space-y-3">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <span className="font-mono text-purple-400 font-bold text-xs">
                              📅 {slot.slot_date}
                            </span>
                            <h3 className="font-black text-white text-base mt-0.5">
                              {slot.start_time} - {slot.end_time}
                            </h3>
                            <span className="text-[11px] text-slate-400 font-medium">
                              {slot.duration_minutes} Mins • {slot.platform}
                            </span>
                          </div>
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-black uppercase bg-emerald-950 text-emerald-300 border border-emerald-700">
                            Available
                          </span>
                        </div>

                        {/* Interviewer Details */}
                        {interviewer && (
                          <div className="bg-slate-900/80 p-3.5 rounded-2xl border border-slate-800 space-y-1.5">
                            <div className="flex justify-between items-center">
                              <span className="font-bold text-white text-xs">{interviewer.name}</span>
                              <span className="text-[10px] text-slate-400 font-semibold">{interviewer.years_of_experience} yrs exp</span>
                            </div>
                            <p className="text-[11px] text-purple-300 font-semibold">{interviewer.designation} at {interviewer.company}</p>

                            {interviewer.skills && (
                              <div className="flex flex-wrap gap-1 pt-1">
                                {interviewer.skills.slice(0, 4).map((s, idx) => (
                                  <span
                                    key={idx}
                                    className="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-slate-950 text-slate-300 border border-slate-800"
                                  >
                                    {s}
                                  </span>
                                ))}
                              </div>
                            )}
                          </div>
                        )}
                      </div>

                      <button
                        type="button"
                        disabled={!eligibility?.is_eligible || Boolean(activeBooking)}
                        onClick={() => setBookingSlot(slot)}
                        className={`w-full py-2.5 rounded-xl text-xs font-extrabold transition flex items-center justify-center gap-1.5 ${
                          !eligibility?.is_eligible
                            ? 'bg-slate-800 text-slate-500 cursor-not-allowed'
                            : activeBooking
                            ? 'bg-slate-800 text-slate-500 cursor-not-allowed'
                            : 'bg-purple-600 hover:bg-purple-500 text-white shadow-md shadow-purple-600/30'
                        }`}
                      >
                        {!eligibility?.is_eligible
                          ? '🔒 Complete Course to Book'
                          : activeBooking
                          ? 'Active Booking In Progress'
                          : '⚡ Select & Confirm Booking'}
                      </button>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )}

        {/* TAB 3: HISTORY */}
        {activeTab === 'history' && (
          <div className="bg-slate-950 border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
            {myInterviews.length === 0 ? (
              <div className="py-20 text-center text-slate-400 space-y-2">
                <span className="text-3xl block">📜</span>
                <p className="text-sm font-bold text-white">No Mock Interview History Yet</p>
                <p className="text-xs text-slate-500">Your booked and completed mock sessions will appear here.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="border-b border-slate-800 bg-slate-900/60 text-[10px] font-black uppercase tracking-wider text-slate-400">
                      <th className="py-3.5 px-4">Booking Code</th>
                      <th className="py-3.5 px-4">Interviewer & Company</th>
                      <th className="py-3.5 px-4">Date & Time</th>
                      <th className="py-3.5 px-4">Status</th>
                      <th className="py-3.5 px-4">Result / Score</th>
                      <th className="py-3.5 px-4">Recommendation</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-850">
                    {myInterviews.map((item) => (
                      <tr key={item.id} className="hover:bg-slate-900/40 transition">
                        <td className="py-3.5 px-4 font-mono font-bold text-purple-300">{item.booking_code}</td>
                        <td className="py-3.5 px-4">
                          <div className="font-bold text-white">{item.interviewer?.name}</div>
                          <div className="text-[11px] text-slate-400">{item.interviewer?.designation} • {item.interviewer?.company}</div>
                        </td>
                        <td className="py-3.5 px-4 font-mono text-slate-300">
                          {item.scheduled_at ? new Date(item.scheduled_at).toLocaleString('en-IN') : 'N/A'}
                        </td>
                        <td className="py-3.5 px-4">
                          <span className="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-slate-900 border border-slate-700 text-slate-300">
                            {item.status.replace('_', ' ')}
                          </span>
                        </td>
                        <td className="py-3.5 px-4 font-extrabold text-purple-300">
                          {item.evaluation ? `⭐ ${item.evaluation.overall_rating} / 10` : '—'}
                        </td>
                        <td className="py-3.5 px-4">
                          {item.evaluation ? (
                            <span
                              className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-bold border ${
                                item.evaluation.recommendation === 'Ready for Placement'
                                  ? 'bg-emerald-950 text-emerald-300 border-emerald-600'
                                  : 'bg-amber-950 text-amber-300 border-amber-600'
                              }`}
                            >
                              {item.evaluation.recommendation}
                            </span>
                          ) : (
                            <span className="text-slate-500 italic">—</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </main>

      {/* Booking Confirmation Modal */}
      {bookingSlot && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 className="text-base font-black text-white flex items-center gap-2">
              <span>📅</span> Confirm Mock Interview Booking
            </h3>

            <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 space-y-2 text-xs">
              <div className="flex justify-between">
                <span className="text-slate-400">Interviewer:</span>
                <span className="font-bold text-white">{bookingSlot.interviewer?.name}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Company & Role:</span>
                <span className="text-purple-300 font-semibold">{bookingSlot.interviewer?.company}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Date:</span>
                <span className="font-bold text-white">{bookingSlot.slot_date}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Time:</span>
                <span className="font-mono text-purple-300 font-bold">{bookingSlot.start_time} - {bookingSlot.end_time}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Platform:</span>
                <span className="text-slate-200">{bookingSlot.platform}</span>
              </div>
            </div>

            <form onSubmit={handleBookSlot} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">
                  Focus Topics / Notes for Interviewer (Optional)
                </label>
                <textarea
                  rows={2}
                  value={studentNotes}
                  onChange={(e) => setStudentNotes(e.target.value)}
                  placeholder="e.g. Focus on Kubernetes, React hooks, or System Design..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="flex justify-end gap-2.5 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setBookingSlot(null)}
                  disabled={bookingInProgress}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  disabled={bookingInProgress}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-1.5"
                >
                  {bookingInProgress ? 'Booking...' : '✓ Confirm Slot Booking'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Reschedule Modal */}
      {reschedulingInterview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 className="text-base font-black text-white flex items-center gap-2">
              <span>🔄</span> Reschedule Mock Interview
            </h3>
            <p className="text-xs text-slate-400">
              Select a new available open slot for your session.
            </p>

            <form onSubmit={handleRescheduleBooking} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">New Available Slot</label>
                {slots.length === 0 ? (
                  <p className="text-xs text-amber-400 bg-amber-950/40 p-3 rounded-xl border border-amber-800">
                    No other open slots are currently available. Check back soon.
                  </p>
                ) : (
                  <select
                    required
                    value={newSlotId}
                    onChange={(e) => setNewSlotId(Number(e.target.value))}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  >
                    <option value="">-- Choose New Slot --</option>
                    {slots.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.slot_date} at {s.start_time} — {s.interviewer?.name} ({s.interviewer?.company})
                      </option>
                    ))}
                  </select>
                )}
              </div>

              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Reason for Rescheduling</label>
                <input
                  type="text"
                  value={rescheduleReason}
                  onChange={(e) => setRescheduleReason(e.target.value)}
                  placeholder="e.g. Schedule clash with college exam"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="flex justify-end gap-2.5 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setReschedulingInterview(null)}
                  disabled={actionInProgress}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Keep Existing
                </button>

                <button
                  type="submit"
                  disabled={actionInProgress || !newSlotId}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition"
                >
                  {actionInProgress ? 'Rescheduling...' : 'Confirm Reschedule'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Cancel Modal */}
      {cancellingInterview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 className="text-base font-black text-rose-400 flex items-center gap-2">
              <span>✕</span> Cancel Mock Interview Booking
            </h3>
            <p className="text-xs text-slate-400">
              Are you sure you want to cancel your booked interview? The slot will be released so other students can book it.
            </p>

            <form onSubmit={handleCancelBooking} className="space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">
                  Reason for Cancellation <span className="text-rose-400">*</span>
                </label>
                <textarea
                  rows={2}
                  required
                  value={cancelReason}
                  onChange={(e) => setCancelReason(e.target.value)}
                  placeholder="State your reason..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-rose-500"
                />
              </div>

              <div className="flex justify-end gap-2.5 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setCancellingInterview(null)}
                  disabled={actionInProgress}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Keep Booking
                </button>

                <button
                  type="submit"
                  disabled={actionInProgress || !cancelReason.trim()}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-rose-600 hover:bg-rose-500 transition"
                >
                  {actionInProgress ? 'Cancelling...' : 'Confirm Cancel'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <Footer />
    </div>
  )
}
