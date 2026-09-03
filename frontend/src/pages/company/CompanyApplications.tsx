import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

export type PlacementStatus =
  | 'applied'
  | 'under_review'
  | 'shortlisted'
  | 'interview_scheduled'
  | 'selected'
  | 'rejected'
  | 'joined'

export interface CompanyApplicationItem {
  id: number
  placement_opportunity_id: number
  user_id: number
  batch_id: number
  batch_code: string
  student_name: string
  email: string
  phone: string
  course_id?: number | null
  course_title?: string | null
  resume_url: string
  cover_note?: string | null
  status: PlacementStatus
  interview_date?: string | null
  interview_notes?: string | null
  admin_notes?: string | null
  applied_at: string
  opportunity?: {
    id: number
    title: string
    location: string
    salary_package?: string | null
    status: string
  } | null
  batch?: { id: number; name: string; code: string; start_date?: string } | null
  course?: { id: number; title: string; code?: string } | null
}

const statusBadgeStyles: Record<PlacementStatus, string> = {
  applied: 'bg-blue-950 text-blue-300 border-blue-700',
  under_review: 'bg-amber-950 text-amber-300 border-amber-700',
  shortlisted: 'bg-purple-950 text-purple-300 border-purple-700',
  interview_scheduled: 'bg-cyan-950 text-cyan-300 border-cyan-700',
  selected: 'bg-emerald-950 text-emerald-300 border-emerald-600',
  rejected: 'bg-rose-950 text-rose-300 border-rose-800',
  joined: 'bg-teal-950 text-teal-300 border-teal-600',
}

export default function CompanyApplications() {
  const [applications, setApplications] = useState<CompanyApplicationItem[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  // Schedule Interview Modal
  const [schedulingApp, setSchedulingApp] = useState<CompanyApplicationItem | null>(null)
  const [interviewDate, setInterviewDate] = useState('')
  const [interviewType, setInterviewType] = useState<'online' | 'offline' | 'phone'>('online')
  const [meetingLink, setMeetingLink] = useState('https://meet.google.com/')
  const [interviewLocation, setInterviewLocation] = useState('')
  const [interviewInstructions, setInterviewInstructions] = useState('Technical interview coding round.')
  const [scheduling, setScheduling] = useState(false)

  const fetchApplications = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      if (search.trim()) params.append('search', search.trim())
      if (statusFilter !== 'all') params.append('status', statusFilter)

      const res = await API.get<{ data?: CompanyApplicationItem[] } | CompanyApplicationItem[]>(
        `/company/applications?${params.toString()}`
      )
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setApplications(items)
    } catch {
      setErrorMsg('Failed to load candidate applications.')
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter])

  useEffect(() => {
    fetchApplications()
  }, [fetchApplications])

  const handleUpdateStatus = async (appId: number, newStatus: PlacementStatus) => {
    try {
      await API.put(`/company/applications/${appId}/status`, {
        status: newStatus,
      })
      setSuccessMsg(`✓ Candidate status updated to ${newStatus.toUpperCase()}!`)
      fetchApplications()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to update candidate status.')
    }
  }

  const handleScheduleInterview = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!schedulingApp || !interviewDate) return

    setScheduling(true)
    setErrorMsg('')
    try {
      await API.post('/company/interviews', {
        placement_application_id: schedulingApp.id,
        interview_date: interviewDate,
        interview_type: interviewType,
        meeting_link: interviewType === 'online' ? meetingLink : undefined,
        location: interviewType === 'offline' ? interviewLocation : undefined,
        instructions: interviewInstructions.trim() || undefined,
      })
      setSuccessMsg(`✓ Interview scheduled for ${schedulingApp.student_name}!`)
      setSchedulingApp(null)
      fetchApplications()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to schedule interview.')
    } finally {
      setScheduling(false)
    }
  }

  return (
    <div className="space-y-6">
      {/* Header Banner */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white flex items-center gap-2">
            <span>👥</span> Candidate Applications ({applications.length})
          </h1>
          <p className="text-xs text-slate-400 mt-1">
            Review verified student candidates who have applied for your company job openings.
          </p>
        </div>

        <button
          type="button"
          onClick={fetchApplications}
          className="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-300 bg-slate-950 hover:bg-slate-900 border border-slate-800 hover:text-white transition flex items-center gap-1.5 self-start sm:self-auto"
        >
          <span>🔄</span>
          <span>Refresh</span>
        </button>
      </div>

      {/* Alerts */}
      {successMsg && (
        <div className="bg-emerald-950/80 border border-emerald-800 text-emerald-200 px-5 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg">
          <span>{successMsg}</span>
          <button type="button" onClick={() => setSuccessMsg('')} className="text-emerald-400 hover:text-white">✕</button>
        </div>
      )}

      {errorMsg && (
        <div className="bg-rose-950/80 border border-rose-800 text-rose-200 px-5 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg">
          <span>{errorMsg}</span>
          <button type="button" onClick={() => setErrorMsg('')} className="text-rose-400 hover:text-white">✕</button>
        </div>
      )}

      {/* Filters Bar */}
      <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-5 shadow-xl">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">
          <div className="lg:col-span-8 relative">
            <span className="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500 text-xs">
              🔍
            </span>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search candidate name, batch cohort code (RIT...), course..."
              className="w-full pl-9 pr-8 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
            />
            {search && (
              <button
                type="button"
                onClick={() => setSearch('')}
                className="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-white text-xs"
              >
                ✕
              </button>
            )}
          </div>

          <div className="lg:col-span-4">
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
            >
              <option value="all">All Candidate Stages</option>
              <option value="applied">Applied</option>
              <option value="under_review">Under Review</option>
              <option value="shortlisted">Shortlisted</option>
              <option value="interview_scheduled">Interview Scheduled</option>
              <option value="selected">Selected</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
        </div>
      </div>

      {/* Candidate Applications Table */}
      {loading ? (
        <div className="py-20 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
          <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
          <span>Loading applications...</span>
        </div>
      ) : applications.length === 0 ? (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-3">
          <span className="text-4xl block">📭</span>
          <h3 className="font-extrabold text-base text-slate-300">No Candidate Applications Found</h3>
          <p className="text-xs text-slate-400 max-w-md mx-auto">
            No applications match your selected filters. When students apply to your approved jobs, their profiles will appear here.
          </p>
        </div>
      ) : (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                <tr>
                  <th className="py-3.5 px-4">Candidate Profile</th>
                  <th className="py-3.5 px-4">Job Role</th>
                  <th className="py-3.5 px-4">Batch Cohort</th>
                  <th className="py-3.5 px-4">Resume</th>
                  <th className="py-3.5 px-4">Stage</th>
                  <th className="py-3.5 px-4 text-right">Recruitment Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/70">
                {applications.map((app) => (
                  <tr key={app.id} className="hover:bg-slate-900/50 transition">
                    <td className="py-3.5 px-4">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-xl bg-purple-950 border border-purple-800 flex items-center justify-center font-bold text-xs text-purple-300 uppercase shrink-0">
                          {app.student_name.charAt(0)}
                        </div>
                        <div>
                          <p className="font-bold text-white leading-tight">{app.student_name}</p>
                          <p className="text-[11px] text-slate-400 mt-0.5 font-mono">{app.email}</p>
                        </div>
                      </div>
                    </td>

                    <td className="py-3.5 px-4">
                      <p className="font-semibold text-slate-200">{app.opportunity?.title || 'Placement Job'}</p>
                      <span className="text-[10px] text-slate-400 font-mono">
                        Applied: {new Date(app.applied_at).toLocaleDateString()}
                      </span>
                    </td>

                    <td className="py-3.5 px-4">
                      <span className="font-mono font-bold text-purple-300 px-2 py-0.5 rounded bg-purple-950/80 border border-purple-800">
                        {app.batch_code}
                      </span>
                    </td>

                    <td className="py-3.5 px-4">
                      <a
                        href={app.resume_url}
                        target="_blank"
                        rel="noreferrer"
                        className="px-2.5 py-1 rounded-lg text-[11px] font-bold text-purple-300 bg-purple-950/60 hover:bg-purple-900 border border-purple-800 transition inline-flex items-center gap-1"
                      >
                        <span>📄</span>
                        <span>View CV</span>
                      </a>
                    </td>

                    <td className="py-3.5 px-4">
                      <select
                        value={app.status}
                        onChange={(e) => handleUpdateStatus(app.id, e.target.value as PlacementStatus)}
                        className={`px-2.5 py-1 rounded-xl text-[10px] font-extrabold uppercase border focus:outline-none transition cursor-pointer ${
                          statusBadgeStyles[app.status] || 'bg-slate-900 text-slate-400 border-slate-800'
                        }`}
                      >
                        <option value="applied">Applied</option>
                        <option value="under_review">Under Review</option>
                        <option value="shortlisted">Shortlisted</option>
                        <option value="interview_scheduled">Interview Scheduled</option>
                        <option value="selected">Selected</option>
                        <option value="rejected">Rejected</option>
                      </select>
                    </td>

                    <td className="py-3.5 px-4 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          type="button"
                          onClick={() => {
                            setSchedulingApp(app)
                            setInterviewDate(new Date(Date.now() + 86400000).toISOString().slice(0, 16))
                          }}
                          className="px-2.5 py-1 rounded-lg text-xs font-bold text-cyan-300 bg-cyan-950/80 hover:bg-cyan-900 border border-cyan-800 transition flex items-center gap-1"
                          title="Schedule Interview"
                        >
                          <span>📅</span>
                          <span>Interview</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* MODAL: SCHEDULE INTERVIEW */}
      {schedulingApp && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-cyan-800/80 rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-base text-white flex items-center gap-2">
                <span>📅</span> Schedule Technical Interview
              </h3>
              <button
                type="button"
                onClick={() => setSchedulingApp(null)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleScheduleInterview} className="space-y-3.5 text-xs">
              <div className="bg-slate-950 p-3 rounded-2xl border border-slate-800 space-y-1">
                <p className="text-slate-400">Candidate: <strong className="text-white">{schedulingApp.student_name}</strong></p>
                <p className="text-slate-400">Role: <strong className="text-purple-300">{schedulingApp.opportunity?.title}</strong></p>
                <p className="text-slate-400">Batch Code: <strong className="text-emerald-400 font-mono">{schedulingApp.batch_code}</strong></p>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Interview Date & Time *
                </label>
                <input
                  type="datetime-local"
                  value={interviewDate}
                  onChange={(e) => setInterviewDate(e.target.value)}
                  required
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Interview Mode *
                </label>
                <select
                  value={interviewType}
                  onChange={(e) => setInterviewType(e.target.value as 'online' | 'offline' | 'phone')}
                  className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                >
                  <option value="online">Online Video Round (Google Meet / Teams / Zoom)</option>
                  <option value="offline">In-Person / Office Premises</option>
                  <option value="phone">Telephonic Screening</option>
                </select>
              </div>

              {interviewType === 'online' && (
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Meeting URL / Video Link *
                  </label>
                  <input
                    type="url"
                    value={meetingLink}
                    onChange={(e) => setMeetingLink(e.target.value)}
                    required
                    placeholder="https://meet.google.com/..."
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
              )}

              {interviewType === 'offline' && (
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Office Location / Venue Address *
                  </label>
                  <input
                    type="text"
                    value={interviewLocation}
                    onChange={(e) => setInterviewLocation(e.target.value)}
                    required
                    placeholder="Building 4, Cyber City, Hitec City, Hyderabad"
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
              )}

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Candidate Instructions / Round Agenda
                </label>
                <textarea
                  rows={2}
                  value={interviewInstructions}
                  onChange={(e) => setInterviewInstructions(e.target.value)}
                  placeholder="e.g. Please be prepared with your IDE for a 45-minute live coding session."
                  className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setSchedulingApp(null)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={scheduling || !interviewDate}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-cyan-600 hover:bg-cyan-500 transition shadow-md shadow-cyan-600/30"
                >
                  {scheduling ? 'Scheduling...' : 'Confirm Schedule'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
