import { useEffect, useState, useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import API from '../../services/api'
import type { Course } from '../../types/course'

export type LeadStatus =
  | 'new'
  | 'contacted'
  | 'demo_scheduled'
  | 'demo_completed'
  | 'interested'
  | 'follow_up'
  | 'admission_confirmed'
  | 'enrolled'
  | 'not_interested'
  | 'no_response'

export interface EnquiryNoteItem {
  id: number
  enquiry_id: number
  user_id?: number | null
  user_name: string
  note: string
  created_at: string
}

export interface EnquiryItem {
  id: number
  user_id?: number | null
  name: string
  email: string
  phone: string
  course_id?: number | null
  course_title?: string | null
  preferred_time?: string | null
  message?: string | null
  status: LeadStatus
  demo_date?: string | null
  demo_time?: string | null
  demo_outcome?: string | null
  assigned_agent?: string | null
  enrolled_user_id?: number | null
  enrolled_at?: string | null
  created_at: string
  updated_at: string
  notes?: EnquiryNoteItem[]
  course?: { id: number; title: string; category?: string } | null
  user?: { id: number; name: string; email: string; role?: string; phone?: string } | null
  enrolled_user?: { id: number; name: string; email: string } | null
}

interface PipelineStats {
  total: number
  new: number
  contacted: number
  demo_scheduled: number
  demo_completed: number
  interested: number
  follow_up: number
  admission_confirmed: number
  enrolled: number
  not_interested: number
  no_response: number
}

interface BatchOption {
  id: number
  code: string
  name?: string | null
  course_id: number
  status?: string
  start_date?: string
}

const statusBadgeStyles: Record<LeadStatus, string> = {
  new: 'bg-blue-950/80 text-blue-300 border-blue-700',
  contacted: 'bg-amber-950/80 text-amber-300 border-amber-700',
  demo_scheduled: 'bg-purple-950/80 text-purple-300 border-purple-700',
  demo_completed: 'bg-indigo-950/80 text-indigo-300 border-indigo-700',
  interested: 'bg-cyan-950/80 text-cyan-300 border-cyan-700',
  follow_up: 'bg-orange-950/80 text-orange-300 border-orange-700',
  admission_confirmed: 'bg-teal-950/80 text-teal-300 border-teal-600',
  enrolled: 'bg-emerald-950/80 text-emerald-300 border-emerald-600',
  not_interested: 'bg-red-950/80 text-red-400 border-red-800',
  no_response: 'bg-slate-800 text-slate-400 border-slate-700',
}

const statusDisplayLabels: Record<LeadStatus, string> = {
  new: 'New Lead',
  contacted: 'Contacted',
  demo_scheduled: 'Demo Scheduled',
  demo_completed: 'Demo Completed',
  interested: 'Interested',
  follow_up: 'Follow-up Needed',
  admission_confirmed: 'Admission Confirmed',
  enrolled: 'Enrolled in LMS',
  not_interested: 'Not Interested',
  no_response: 'No Response',
}

export default function AdminEnquiries() {
  const [searchParams] = useSearchParams()
  const [enquiries, setEnquiries] = useState<EnquiryItem[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [stats, setStats] = useState<PipelineStats>({
    total: 0,
    new: 0,
    contacted: 0,
    demo_scheduled: 0,
    demo_completed: 0,
    interested: 0,
    follow_up: 0,
    admission_confirmed: 0,
    enrolled: 0,
    not_interested: 0,
    no_response: 0,
  })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>(() => searchParams.get('status') || 'all')
  const [courseFilter, setCourseFilter] = useState<string>('all')

  const [updatingId, setUpdatingId] = useState<number | null>(null)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  // Detail Modal / Drawer State
  const [selectedEnquiry, setSelectedEnquiry] = useState<EnquiryItem | null>(null)
  const [newNote, setNewNote] = useState('')
  const [addingNote, setAddingNote] = useState(false)

  // Demo Scheduling in Drawer
  const [scheduleDemoDate, setScheduleDemoDate] = useState('')
  const [scheduleDemoTime, setScheduleDemoTime] = useState('')
  const [demoOutcome, setDemoOutcome] = useState('')
  const [assignedAgent, setAssignedAgent] = useState('')

  // Enrollment Confirmation Modal State
  const [enrollModalEnquiry, setEnrollModalEnquiry] = useState<EnquiryItem | null>(null)
  const [enrollSelectedCourseId, setEnrollSelectedCourseId] = useState<number | string>('')
  const [enrollSelectedBatchId, setEnrollSelectedBatchId] = useState<number | string>('')
  const [enrollBatches, setEnrollBatches] = useState<BatchOption[]>([])
  const [enrollStudentPassword, setEnrollStudentPassword] = useState('')
  const [enrolling, setEnrolling] = useState(false)

  // Fetch Available Courses
  useEffect(() => {
    API.get('/courses')
      .then((res) => {
        const c = Array.isArray(res.data) ? res.data : res.data.data || []
        setCourses(c)
      })
      .catch(() => {})
  }, [])

  // Fetch available cohort batches for the enrollment modal (read-only list, counsellor-safe)
  useEffect(() => {
    API.get<BatchOption[]>('/admin/crm/batches')
      .then((res) => setEnrollBatches(Array.isArray(res.data) ? res.data : []))
      .catch(() => {})
  }, [])

  // Reset batch selection whenever the chosen course changes
  useEffect(() => {
    setEnrollSelectedBatchId('')
  }, [enrollSelectedCourseId])

  const batchesForSelectedCourse = enrollBatches.filter(
    (b) => b.course_id === Number(enrollSelectedCourseId)
  )

  const loadPipeline = useCallback(() => {
    setLoading(true)
    let url = '/admin/enquiries'
    const params = new URLSearchParams()
    if (statusFilter !== 'all') params.append('status', statusFilter)
    if (courseFilter !== 'all') params.append('course_id', courseFilter)
    if (search.trim()) params.append('search', search.trim())

    if (params.toString()) {
      url += `?${params.toString()}`
    }

    Promise.all([
      API.get<EnquiryItem[]>(url),
      API.get<PipelineStats>('/admin/enquiries/stats').catch(() => ({ data: null })),
    ])
      .then(([listRes, statsRes]) => {
        const list = Array.isArray(listRes.data) ? listRes.data : []
        setEnquiries(list)
        if (statsRes.data) {
          setStats(statsRes.data)
        }

        // If a lead is currently open in modal, refresh its view
        if (selectedEnquiry) {
          const fresh = list.find((e) => e.id === selectedEnquiry.id)
          if (fresh) setSelectedEnquiry(fresh)
        }
      })
      .catch(() => setErrorMsg('Failed to load lead pipeline.'))
      .finally(() => setLoading(false))
  }, [statusFilter, courseFilter, search, selectedEnquiry])

  useEffect(() => {
    loadPipeline()
  }, [loadPipeline])

  const handleStatusChange = async (enquiryId: number, newStatus: LeadStatus) => {
    setUpdatingId(enquiryId)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      await API.put(`/admin/enquiries/${enquiryId}`, { status: newStatus })
      setSuccessMsg(`Status updated to ${statusDisplayLabels[newStatus]}.`)
      loadPipeline()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to update lead status.')
    } finally {
      setUpdatingId(null)
    }
  }

  const handleOpenDetail = (enquiry: EnquiryItem) => {
    setSelectedEnquiry(enquiry)
    setScheduleDemoDate(enquiry.demo_date ? enquiry.demo_date.split('T')[0] : '')
    setScheduleDemoTime(enquiry.demo_time || '')
    setDemoOutcome(enquiry.demo_outcome || '')
    setAssignedAgent(enquiry.assigned_agent || '')
    setNewNote('')
  }

  const handleSaveDemoSchedule = async () => {
    if (!selectedEnquiry) return
    setUpdatingId(selectedEnquiry.id)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      const res = await API.put<{ enquiry: EnquiryItem }>(`/admin/enquiries/${selectedEnquiry.id}`, {
        status: scheduleDemoDate ? 'demo_scheduled' : selectedEnquiry.status,
        demo_date: scheduleDemoDate || null,
        demo_time: scheduleDemoTime || null,
        demo_outcome: demoOutcome || null,
        assigned_agent: assignedAgent || null,
        note: scheduleDemoDate
          ? `Demo scheduled for ${scheduleDemoDate} at ${scheduleDemoTime || 'scheduled slot'} (Agent: ${assignedAgent || 'Admissions'}).`
          : undefined,
      })
      setSelectedEnquiry(res.data.enquiry)
      setSuccessMsg('Demo schedule & outcome updated successfully.')
      loadPipeline()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to save demo details.')
    } finally {
      setUpdatingId(null)
    }
  }

  const handleAddNote = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedEnquiry || !newNote.trim()) return

    setAddingNote(true)
    setErrorMsg('')
    try {
      const res = await API.post<{ enquiry: EnquiryItem }>(
        `/admin/enquiries/${selectedEnquiry.id}/notes`,
        { note: newNote.trim() }
      )
      setSelectedEnquiry(res.data.enquiry)
      setNewNote('')
      setSuccessMsg('Follow-up note logged.')
      loadPipeline()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to add note.')
    } finally {
      setAddingNote(false)
    }
  }

  const handleConfirmAdmission = async (enquiry: EnquiryItem) => {
    setUpdatingId(enquiry.id)
    setErrorMsg('')
    try {
      const res = await API.put<{ enquiry: EnquiryItem }>(`/admin/enquiries/${enquiry.id}`, {
        status: 'admission_confirmed',
        note: `Admission officially confirmed by Admissions Team for ${enquiry.course_title || 'program'}. Ready for LMS enrollment.`,
      })
      if (selectedEnquiry?.id === enquiry.id) {
        setSelectedEnquiry(res.data.enquiry)
      }
      setSuccessMsg(`Admission confirmed for ${enquiry.name}.`)
      loadPipeline()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to confirm admission.')
    } finally {
      setUpdatingId(null)
    }
  }

  const handleOpenEnrollModal = (enquiry: EnquiryItem) => {
    setEnrollModalEnquiry(enquiry)
    setEnrollSelectedCourseId(enquiry.course_id || (courses[0]?.id ?? ''))
    setEnrollSelectedBatchId('')
    setEnrollStudentPassword('')
  }

  const handleConfirmEnrollment = async () => {
    if (!enrollModalEnquiry) return
    setEnrolling(true)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      await API.post(`/admin/enquiries/${enrollModalEnquiry.id}/enroll`, {
        course_id: enrollSelectedCourseId,
        batch_id: enrollSelectedBatchId ? Number(enrollSelectedBatchId) : null,
        email: enrollModalEnquiry.email,
        name: enrollModalEnquiry.name,
        password: enrollStudentPassword,
      })
      setSuccessMsg(
        `✓ Student ${enrollModalEnquiry.name} (${enrollModalEnquiry.email}) has been enrolled with active LMS access!`
      )
      setEnrollModalEnquiry(null)
      loadPipeline()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to enroll student.')
    } finally {
      setEnrolling(false)
    }
  }

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Leads & Enquiries</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Public demo/enquiry submissions land here. Telecallers work the pipeline, then administration creates the student, enrolls a course, and assigns a batch.
          </p>
        </div>
      </div>

      {successMsg && (
        <div className="p-4 bg-emerald-950/80 border border-emerald-800 text-emerald-300 rounded-2xl text-xs font-bold flex items-center justify-between">
          <span>{successMsg}</span>
          <button type="button" onClick={() => setSuccessMsg('')} className="text-emerald-400 hover:text-white">
            ×
          </button>
        </div>
      )}

      {errorMsg && (
        <div className="p-4 bg-red-950/80 border border-red-800 text-red-300 rounded-2xl text-xs font-bold flex items-center justify-between">
          <span>⚠️ {errorMsg}</span>
          <button type="button" onClick={() => setErrorMsg('')} className="text-red-400 hover:text-white">
            ×
          </button>
        </div>
      )}

      {/* 1. PIPELINE METRICS CARDS */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        {[
          { label: 'Total Inquiries', count: stats.total, status: 'all', color: 'text-white' },
          { label: 'New Leads', count: stats.new, status: 'new', color: 'text-blue-400' },
          { label: 'Contacted', count: stats.contacted, status: 'contacted', color: 'text-amber-400' },
          { label: 'Demo Scheduled', count: stats.demo_scheduled, status: 'demo_scheduled', color: 'text-purple-400' },
          { label: 'Demo Completed', count: stats.demo_completed, status: 'demo_completed', color: 'text-indigo-400' },
          { label: 'Interested', count: stats.interested, status: 'interested', color: 'text-cyan-400' },
          { label: 'Follow-up Needed', count: stats.follow_up, status: 'follow_up', color: 'text-orange-400' },
          { label: 'Admission Confirmed', count: stats.admission_confirmed, status: 'admission_confirmed', color: 'text-teal-400' },
          { label: 'Enrolled in LMS', count: stats.enrolled, status: 'enrolled', color: 'text-emerald-400' },
          { label: 'Not Interested / Lost', count: stats.not_interested + stats.no_response, status: 'not_interested', color: 'text-slate-400' },
        ].map((item) => (
          <button
            key={item.label}
            type="button"
            onClick={() => setStatusFilter(item.status)}
            className={`p-3.5 rounded-2xl text-left border transition ${
              statusFilter === item.status
                ? 'bg-slate-900 border-purple-500 shadow-md ring-1 ring-purple-500/50'
                : 'bg-slate-950 border-slate-800/80 hover:bg-slate-900/60'
            }`}
          >
            <p className="text-[11px] font-bold text-slate-400">{item.label}</p>
            <p className={`text-xl font-black mt-1 ${item.color}`}>{item.count}</p>
          </button>
        ))}
      </div>

      {/* 2. SEARCH & FILTERS BAR */}
      <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 flex flex-col lg:flex-row items-center justify-between gap-4">
        <div className="w-full lg:max-w-md relative">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by student name, email, phone, or program..."
            className="w-full pl-9 pr-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-xs text-white placeholder:text-slate-500 focus:ring-2 focus:ring-purple-500 outline-none"
          />
          <span className="absolute left-3 top-3 text-slate-400 text-xs">🔍</span>
        </div>

        <div className="flex flex-wrap items-center gap-3 w-full lg:w-auto">
          {/* Status Filter */}
          <div className="flex items-center gap-1.5 text-xs">
            <span className="text-slate-400 font-bold">Status:</span>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs font-semibold text-white focus:ring-2 focus:ring-purple-500 outline-none"
            >
              <option value="all">All Statuses ({stats.total})</option>
              <option value="new">New ({stats.new})</option>
              <option value="contacted">Contacted ({stats.contacted})</option>
              <option value="demo_scheduled">Demo Scheduled ({stats.demo_scheduled})</option>
              <option value="demo_completed">Demo Completed ({stats.demo_completed})</option>
              <option value="interested">Interested ({stats.interested})</option>
              <option value="follow_up">Follow-up ({stats.follow_up})</option>
              <option value="admission_confirmed">Admission Confirmed ({stats.admission_confirmed})</option>
              <option value="enrolled">Enrolled ({stats.enrolled})</option>
              <option value="not_interested">Not Interested ({stats.not_interested})</option>
              <option value="no_response">No Response ({stats.no_response})</option>
            </select>
          </div>

          {/* Program Filter */}
          <div className="flex items-center gap-1.5 text-xs">
            <span className="text-slate-400 font-bold">Program:</span>
            <select
              value={courseFilter}
              onChange={(e) => setCourseFilter(e.target.value)}
              className="px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs font-semibold text-white focus:ring-2 focus:ring-purple-500 outline-none max-w-xs truncate"
            >
              <option value="all">All Programs</option>
              {courses.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.title}
                </option>
              ))}
            </select>
          </div>

          {(statusFilter !== 'all' || courseFilter !== 'all' || search) && (
            <button
              type="button"
              onClick={() => {
                setStatusFilter('all')
                setCourseFilter('all')
                setSearch('')
              }}
              className="text-xs text-purple-400 font-bold hover:underline"
            >
              Reset Filters
            </button>
          )}
        </div>
      </div>

      {/* 3. LEADS TABLE */}
      <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8">
        {loading ? (
          <p className="text-xs text-slate-500 py-12 text-center">Loading admissions pipeline...</p>
        ) : enquiries.length === 0 ? (
          <div className="py-14 text-center text-slate-500 text-xs">
            <span className="text-4xl mb-3 block">📋</span>
            <p className="font-bold text-slate-300 text-sm">No lead records found</p>
            <p className="mt-1">Inquiries submitted on public course pages will appear here in real time.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                  <th className="py-3.5 px-4">Prospective Student</th>
                  <th className="py-3.5 px-4">Source</th>
                  <th className="py-3.5 px-4">Interested Program</th>
                  <th className="py-3.5 px-4">Demo & Slots</th>
                  <th className="py-3.5 px-4">Assigned Agent</th>
                  <th className="py-3.5 px-4">Pipeline Status</th>
                  <th className="py-3.5 px-4 text-right">Actions & Next Step</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60">
                {enquiries.map((e) => {
                  const hasDemo = Boolean(e.demo_date)
                  return (
                    <tr key={e.id} className="hover:bg-slate-900/60 transition">
                      {/* Student Info */}
                      <td className="py-4 px-4">
                        <div className="flex items-center gap-2">
                          <p className="font-bold text-white text-sm">{e.name}</p>
                          {e.user_id ? (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-950/90 text-indigo-300 border border-indigo-700 text-[10px] font-bold">
                              🎓 Student
                            </span>
                          ) : (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-slate-900 text-slate-400 border border-slate-700 text-[10px] font-medium">
                              🌐 Public
                            </span>
                          )}
                        </div>
                        <p className="text-[11px] text-slate-400">{e.email}</p>
                        <p className="text-[11px] text-blue-400 font-semibold mt-0.5">📞 {e.phone}</p>
                        <p className="text-[10px] text-slate-500 mt-1">
                          Inquiry Date: {new Date(e.created_at).toLocaleDateString()}
                        </p>
                      </td>

                      <td className="py-4 px-4">
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-bold">
                          {e.user_id ? 'Student portal' : 'Public website'}
                        </span>
                        <p className="text-[10px] text-slate-500 mt-1">
                          {new Date(e.created_at).toLocaleString()}
                        </p>
                      </td>

                      {/* Program & Message */}
                      <td className="py-4 px-4 max-w-xs">
                        <p className="text-white font-bold">{e.course_title || 'General Advising'}</p>
                        {e.message ? (
                          <p className="text-[11px] text-slate-400 mt-1 line-clamp-2 italic">
                            "{e.message}"
                          </p>
                        ) : (
                          <p className="text-[10px] text-slate-500 mt-1 italic">No message included</p>
                        )}
                        {e.notes && e.notes.length > 0 && (
                          <span className="inline-block mt-1 px-2 py-0.5 rounded bg-slate-900 text-slate-400 text-[10px]">
                            📝 {e.notes.length} follow-up note{e.notes.length > 1 ? 's' : ''}
                          </span>
                        )}
                      </td>

                      {/* Demo info */}
                      <td className="py-4 px-4">
                        {hasDemo ? (
                          <div>
                            <span className="text-xs font-bold text-purple-300 block">
                              📅 {new Date(e.demo_date!).toLocaleDateString()}
                            </span>
                            {e.demo_time && <span className="text-[10px] text-slate-400 block">{e.demo_time}</span>}
                            {e.demo_outcome && (
                              <span className="text-[10px] text-indigo-400 font-medium block mt-0.5">
                                Outcome: {e.demo_outcome}
                              </span>
                            )}
                          </div>
                        ) : (
                          <div>
                            <span className="text-xs text-slate-300 font-medium">
                              Slot: {e.preferred_time || 'Flexible'}
                            </span>
                            <span className="text-[10px] text-slate-500 block">Demo not scheduled</span>
                          </div>
                        )}
                      </td>

                      {/* Assigned Counselor */}
                      <td className="py-4 px-4 text-slate-300">
                        {e.assigned_agent ? (
                          <span className="font-semibold text-slate-200">{e.assigned_agent}</span>
                        ) : (
                          <span className="text-slate-500 italic">Unassigned</span>
                        )}
                      </td>

                      {/* Status */}
                      <td className="py-4 px-4">
                        <span
                          className={`px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase border ${
                            statusBadgeStyles[e.status] || statusBadgeStyles.new
                          }`}
                        >
                          {statusDisplayLabels[e.status] || e.status}
                        </span>
                        {e.status === 'enrolled' && e.enrolled_at && (
                          <span className="text-[10px] text-emerald-400 block mt-1">
                            Enrolled: {new Date(e.enrolled_at).toLocaleDateString()}
                          </span>
                        )}
                      </td>

                      {/* Actions */}
                      <td className="py-4 px-4 text-right space-y-2">
                        <div className="flex flex-col items-end gap-1.5">
                          <div className="flex items-center gap-1.5">
                            <button
                              type="button"
                              onClick={() => handleOpenDetail(e)}
                              className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-bold border border-slate-700 transition"
                            >
                              Details & Notes
                            </button>

                            <select
                              value={e.status}
                              disabled={updatingId === e.id}
                              onChange={(ev) => handleStatusChange(e.id, ev.target.value as LeadStatus)}
                              className="px-2 py-1 rounded-lg bg-slate-900 border border-slate-700 text-white text-[11px] font-semibold focus:ring-1 focus:ring-purple-500 outline-none"
                            >
                              <option value="new">New</option>
                              <option value="contacted">Contacted</option>
                              <option value="demo_scheduled">Demo Scheduled</option>
                              <option value="demo_completed">Demo Completed</option>
                              <option value="interested">Interested</option>
                              <option value="follow_up">Follow-up</option>
                              <option value="admission_confirmed">Admission Confirmed</option>
                              <option value="enrolled">Enrolled</option>
                              <option value="not_interested">Not Interested</option>
                              <option value="no_response">No Response</option>
                            </select>
                          </div>

                          {/* Action Button: Confirm Admission vs Enroll Student */}
                          {e.status === 'admission_confirmed' ? (
                            <button
                              type="button"
                              onClick={() => handleOpenEnrollModal(e)}
                              className="w-full sm:w-auto px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-black shadow-md shadow-emerald-600/30 transition flex items-center justify-center gap-1"
                            >
                              <span>🚀</span> Enroll Student in LMS
                            </button>
                          ) : e.status !== 'enrolled' ? (
                            <button
                              type="button"
                              disabled={updatingId === e.id}
                              onClick={() => handleConfirmAdmission(e)}
                              className="w-full sm:w-auto px-2.5 py-1 rounded-lg bg-teal-950 border border-teal-700 hover:bg-teal-900 text-teal-300 text-[11px] font-bold transition disabled:opacity-50"
                            >
                              ✓ Confirm Admission
                            </button>
                          ) : (
                            <span className="text-[11px] text-emerald-400 font-bold flex items-center gap-1">
                              ✓ Active in LMS
                            </span>
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

      {/* 4. LEAD DETAIL & ACTIVITY DRAWER / MODAL */}
      {selectedEnquiry && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-xs p-4 overflow-y-auto">
          <div className="bg-slate-950 border border-slate-800 rounded-3xl max-w-3xl w-full p-6 sm:p-8 shadow-2xl text-white my-8 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-slate-800 pb-4 mb-6">
              <div>
                <span
                  className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${
                    statusBadgeStyles[selectedEnquiry.status]
                  }`}
                >
                  {statusDisplayLabels[selectedEnquiry.status]}
                </span>
                <div className="flex items-center gap-2 mt-1.5">
                  <h2 className="text-xl font-black text-white">{selectedEnquiry.name}</h2>
                  {selectedEnquiry.user_id ? (
                    <span className="px-2 py-0.5 rounded-full bg-indigo-950 text-indigo-300 border border-indigo-700 text-[10px] font-bold">
                      🎓 Authenticated Student (ID: #{selectedEnquiry.user_id})
                    </span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-full bg-slate-900 text-slate-400 border border-slate-700 text-[10px] font-semibold">
                      🌐 Public Lead
                    </span>
                  )}
                </div>
                <p className="text-xs text-slate-400 mt-0.5">
                  {selectedEnquiry.email} • 📞 {selectedEnquiry.phone}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setSelectedEnquiry(null)}
                className="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center font-bold"
              >
                ✕
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {/* Left Column: Lead Info & Demo Scheduling */}
              <div className="space-y-6">
                {/* Program & Slot Details */}
                <div className="bg-slate-900 p-4 rounded-2xl border border-slate-800 space-y-2">
                  <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">
                    Inquiry Information
                  </h3>
                  <div>
                    <p className="text-xs text-slate-400">Interested Track:</p>
                    <p className="text-sm font-bold text-white">{selectedEnquiry.course_title || 'General'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-slate-400">Preferred Time Slot:</p>
                    <p className="text-xs font-semibold text-slate-200">
                      {selectedEnquiry.preferred_time || 'Flexible'}
                    </p>
                  </div>
                  {selectedEnquiry.message && (
                    <div>
                      <p className="text-xs text-slate-400">Student Questions / Notes:</p>
                      <p className="text-xs text-slate-300 italic bg-slate-950 p-2.5 rounded-xl mt-1 border border-slate-800/80">
                        "{selectedEnquiry.message}"
                      </p>
                    </div>
                  )}
                </div>

                {/* Demo Scheduling Form */}
                <div className="bg-slate-900 p-4 rounded-2xl border border-slate-800 space-y-4">
                  <h3 className="text-xs font-bold uppercase tracking-wider text-purple-400">
                    Live Demo Scheduling & Outcome
                  </h3>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">
                        Demo Date
                      </label>
                      <input
                        type="date"
                        value={scheduleDemoDate}
                        onChange={(e) => setScheduleDemoDate(e.target.value)}
                        className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-xs text-white outline-none"
                      />
                    </div>
                    <div>
                      <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">
                        Demo Time
                      </label>
                      <input
                        type="text"
                        placeholder="e.g. 3:00 PM IST"
                        value={scheduleDemoTime}
                        onChange={(e) => setScheduleDemoTime(e.target.value)}
                        className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-xs text-white outline-none"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">
                      Assigned Counselor / Agent
                    </label>
                    <input
                      type="text"
                      placeholder="e.g. Admissions Advisor"
                      value={assignedAgent}
                      onChange={(e) => setAssignedAgent(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-xs text-white outline-none"
                    />
                  </div>

                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">
                      Demo Outcome / Feedback
                    </label>
                    <input
                      type="text"
                      placeholder="e.g. Attended, Highly positive, Requested weekend batch"
                      value={demoOutcome}
                      onChange={(e) => setDemoOutcome(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-xs text-white outline-none"
                    />
                  </div>

                  <button
                    type="button"
                    onClick={handleSaveDemoSchedule}
                    className="w-full py-2 px-3 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold transition"
                  >
                    Save Demo Details
                  </button>
                </div>
              </div>

              {/* Right Column: Follow-up Timeline & Actions */}
              <div className="space-y-6 flex flex-col justify-between">
                {/* Admissions Action Banner */}
                <div className="bg-slate-900 p-4 rounded-2xl border border-slate-800 space-y-3">
                  <h3 className="text-xs font-bold uppercase tracking-wider text-teal-400">
                    Admissions Progression
                  </h3>

                  {selectedEnquiry.status === 'enrolled' ? (
                    <div className="p-3 bg-emerald-950/80 border border-emerald-700 text-emerald-300 rounded-xl text-xs font-bold">
                      ✓ Student is fully enrolled in {selectedEnquiry.course_title}. LMS classroom access active.
                    </div>
                  ) : selectedEnquiry.status === 'admission_confirmed' ? (
                    <div className="space-y-2">
                      <p className="text-xs text-slate-300">
                        Admission has been confirmed. Ready to create student credentials and grant classroom access.
                      </p>
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedEnquiry(null)
                          handleOpenEnrollModal(selectedEnquiry)
                        }}
                        className="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-black shadow-md transition flex items-center justify-center gap-1.5"
                      >
                        <span>🚀</span> Proceed to LMS Enrollment
                      </button>
                    </div>
                  ) : (
                    <div className="space-y-2">
                      <p className="text-xs text-slate-300">
                        Conduct counseling and confirm admission before creating LMS enrollment.
                      </p>
                      <div className="grid grid-cols-2 gap-2">
                        <button
                          type="button"
                          onClick={() => handleConfirmAdmission(selectedEnquiry)}
                          className="py-2 px-3 rounded-xl bg-teal-950 border border-teal-700 hover:bg-teal-900 text-teal-300 text-xs font-bold transition"
                        >
                          ✓ Confirm Admission
                        </button>
                        <button
                          type="button"
                          onClick={() => handleStatusChange(selectedEnquiry.id, 'interested')}
                          className="py-2 px-3 rounded-xl bg-cyan-950 border border-cyan-700 hover:bg-cyan-900 text-cyan-300 text-xs font-bold transition"
                        >
                          Mark Interested
                        </button>
                      </div>
                    </div>
                  )}
                </div>

                {/* Follow-up Notes Timeline */}
                <div className="bg-slate-900 p-4 rounded-2xl border border-slate-800 flex-grow space-y-4 flex flex-col justify-between">
                  <div>
                    <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">
                      Follow-up History & Counselor Notes
                    </h3>

                    <div className="space-y-2.5 max-h-48 overflow-y-auto pr-1">
                      {selectedEnquiry.notes && selectedEnquiry.notes.length > 0 ? (
                        selectedEnquiry.notes.map((n) => (
                          <div key={n.id} className="p-2.5 bg-slate-950 rounded-xl border border-slate-800 text-xs">
                            <div className="flex items-center justify-between text-[10px] text-slate-500 mb-1">
                              <span className="font-bold text-slate-300">{n.user_name}</span>
                              <span>{new Date(n.created_at).toLocaleString()}</span>
                            </div>
                            <p className="text-slate-300 leading-relaxed">{n.note}</p>
                          </div>
                        ))
                      ) : (
                        <p className="text-xs text-slate-500 italic py-2">No follow-up notes logged yet.</p>
                      )}
                    </div>
                  </div>

                  {/* Add Note Form */}
                  <form onSubmit={handleAddNote} className="pt-2 border-t border-slate-800 flex gap-2">
                    <input
                      type="text"
                      placeholder="Add follow-up note (e.g. called student, callback scheduled)..."
                      value={newNote}
                      onChange={(e) => setNewNote(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-xs text-white outline-none"
                    />
                    <button
                      type="submit"
                      disabled={addingNote || !newNote.trim()}
                      className="px-3 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold transition shrink-0 disabled:opacity-50"
                    >
                      {addingNote ? '...' : 'Log Note'}
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* 5. ENROLLMENT CONFIRMATION MODAL */}
      {enrollModalEnquiry && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-xs p-4">
          <div className="bg-slate-950 border border-slate-800 rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl text-white space-y-6">
            <div className="flex items-center justify-between border-b border-slate-800 pb-4">
              <div>
                <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-emerald-950 text-emerald-300 border border-emerald-700">
                  Admissions Onboarding
                </span>
                <h2 className="text-xl font-black text-white mt-1">Enroll Student in LMS</h2>
              </div>
              <button
                type="button"
                onClick={() => setEnrollModalEnquiry(null)}
                className="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center font-bold"
              >
                ✕
              </button>
            </div>

            {/* Student Lead Summary */}
            <div className="bg-slate-900 p-4 rounded-2xl border border-slate-800 space-y-2 text-xs">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <span className="text-slate-500 font-bold block">Student Name:</span>
                  <span className="text-white font-bold">{enrollModalEnquiry.name}</span>
                </div>
                <div>
                  <span className="text-slate-500 font-bold block">Email:</span>
                  <span className="text-white font-bold truncate block">{enrollModalEnquiry.email}</span>
                </div>
                <div>
                  <span className="text-slate-500 font-bold block">Phone:</span>
                  <span className="text-white font-bold">{enrollModalEnquiry.phone}</span>
                </div>
                <div>
                  <span className="text-slate-500 font-bold block">Target Track:</span>
                  <span className="text-blue-400 font-bold">{enrollModalEnquiry.course_title || 'Select Below'}</span>
                </div>
              </div>
            </div>

            {/* Course Selector & Batch Assignment & Password */}
            <div className="space-y-4">
              <div>
                <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-1.5">
                  Confirm Learning Program
                </label>
                <select
                  value={enrollSelectedCourseId}
                  onChange={(e) => setEnrollSelectedCourseId(e.target.value)}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-xs font-bold text-white focus:ring-2 focus:ring-emerald-500 outline-none"
                >
                  {courses.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.title}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-1.5">
                  Assign Cohort Batch
                </label>
                <select
                  value={enrollSelectedBatchId}
                  onChange={(e) => setEnrollSelectedBatchId(e.target.value)}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-xs font-bold text-white focus:ring-2 focus:ring-emerald-500 outline-none"
                  disabled={!enrollSelectedCourseId || batchesForSelectedCourse.length === 0}
                >
                  <option value="">-- Assign Cohort Batch (Optional) --</option>
                  {batchesForSelectedCourse.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.code} {b.name ? `· ${b.name}` : ''}
                    </option>
                  ))}
                </select>
                <p className="text-[11px] text-slate-500 mt-1">
                  Keeps batch membership consistent with the enrollment. Only batches for the selected course are listed.
                </p>
              </div>

              <div>
                <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-1.5">
                  Initial Account Password
                </label>
                <input
                  type="text"
                  value={enrollStudentPassword}
                  onChange={(e) => setEnrollStudentPassword(e.target.value)}
                  placeholder="e.g. password123"
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-xs font-bold text-white focus:ring-2 focus:ring-emerald-500 outline-none"
                />
                <p className="text-[11px] text-slate-500 mt-1">
                  The student can log in at /login with this email and password to access their classroom.
                </p>
              </div>
            </div>

            <div className="p-3 bg-emerald-950/40 border border-emerald-800/80 rounded-xl text-xs text-emerald-300">
              ⚡ Action: Creates student account (if not already existing), activates LMS classroom enrollment, syncs cohort batch membership, and marks lead as ENROLLED.
            </div>

            <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
              <button
                type="button"
                onClick={() => setEnrollModalEnquiry(null)}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-slate-800 transition"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={enrolling || !enrollSelectedCourseId}
                onClick={handleConfirmEnrollment}
                className="px-6 py-2.5 rounded-xl text-xs font-black text-white bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 shadow-lg shadow-emerald-600/30 transition disabled:opacity-50 flex items-center gap-1.5"
              >
                {enrolling ? (
                  <>
                    <span className="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full" />
                    Enrolling...
                  </>
                ) : (
                  <>
                    <span>🚀</span> Grant LMS Access & Enroll
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
