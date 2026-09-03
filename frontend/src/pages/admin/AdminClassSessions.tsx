import { useEffect, useState, useMemo, useCallback } from 'react'
import { Link } from 'react-router-dom'
import API from '../../services/api'
import type { ClassSession, ClassMaterial } from '../../types/classSession'
import type { Course } from '../../types/course'
import type { User } from '../../context/auth-context'

interface ClassSessionFormData {
  course_id: number | ''
  tutor_id: number | ''
  title: string
  description: string
  platform: 'livekit' | 'zoom' | 'teams'
  meeting_url: string
  meeting_id: string
  meeting_password: string
  scheduled_date: string
  start_time: string
  end_time: string
  status: 'scheduled' | 'live' | 'completed' | 'cancelled'
  admin_notes: string
  recording_url: string
}

const initialForm: ClassSessionFormData = {
  course_id: '',
  tutor_id: '',
  title: '',
  description: '',
  platform: 'livekit',
  meeting_url: '',
  meeting_id: '',
  meeting_password: '',
  scheduled_date: new Date().toISOString().split('T')[0],
  start_time: '15:00',
  end_time: '16:30',
  status: 'scheduled',
  admin_notes: '',
  recording_url: '',
}

export default function AdminClassSessions() {
  const [sessions, setSessions] = useState<ClassSession[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [tutors, setTutors] = useState<User[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [filterCourse, setFilterCourse] = useState('all')
  const [filterTutor, setFilterTutor] = useState('all')
  const [filterPlatform, setFilterPlatform] = useState('all')
  const [filterStatus, setFilterStatus] = useState('all')
  const [filterDate, setFilterDate] = useState('')

  const [modalOpen, setModalOpen] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [formData, setFormData] = useState<ClassSessionFormData>(initialForm)
  const [formError, setFormError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  // Details Modal
  const [detailsModalOpen, setDetailsModalOpen] = useState(false)
  const [selectedSession, setSelectedSession] = useState<ClassSession | null>(null)

  // Material Upload Modal
  const [materialTitle, setMaterialTitle] = useState('')
  const [materialDescription, setMaterialDescription] = useState('')
  const [materialFile, setMaterialFile] = useState<File | null>(null)
  const [uploadingMaterial, setUploadingMaterial] = useState(false)
  const [materialError, setMaterialError] = useState('')

  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadSessions = useCallback(() => {
    setLoading(true)
    const params: Record<string, string> = {}
    if (filterCourse !== 'all') params.course_id = filterCourse
    if (filterTutor !== 'all') params.tutor_id = filterTutor
    if (filterPlatform !== 'all') params.platform = filterPlatform
    if (filterStatus !== 'all') params.status = filterStatus
    if (filterDate) params.date = filterDate
    if (search.trim()) params.search = search.trim()

    API.get<{ sessions: ClassSession[]; courses: Course[]; tutors: User[] }>('/admin/class-sessions', { params })
      .then((res) => {
        setSessions(res.data.sessions || [])
        if (res.data.courses) setCourses(res.data.courses)
        if (res.data.tutors) setTutors(res.data.tutors)
      })
      .catch(() => setErrorMsg('Failed to load class sessions.'))
      .finally(() => setLoading(false))
  }, [filterCourse, filterTutor, filterPlatform, filterStatus, filterDate, search])

  useEffect(() => {
    loadSessions()
  }, [loadSessions])

  const counts = useMemo(() => {
    return {
      total: sessions.length,
      scheduled: sessions.filter((s) => s.status === 'scheduled').length,
      live: sessions.filter((s) => s.status === 'live').length,
      completed: sessions.filter((s) => s.status === 'completed').length,
      cancelled: sessions.filter((s) => s.status === 'cancelled').length,
      expired: sessions.filter((s) => s.status === 'expired').length,
    }
  }, [sessions])

  // LIVE classes must always appear first, followed by upcoming classes chronologically
  const sortedSessions = useMemo(() => {
    return [...sessions].sort((a, b) => {
      const aIsLive = a.status === 'live' ? 0 : 1
      const bIsLive = b.status === 'live' ? 0 : 1
      if (aIsLive !== bIsLive) return aIsLive - bIsLive
      const dateCmp = (a.scheduled_date || '').localeCompare(b.scheduled_date || '')
      if (dateCmp !== 0) return dateCmp
      return (a.start_time || '').localeCompare(b.start_time || '')
    })
  }, [sessions])

  const handleOpenCreate = () => {
    setEditId(null)
    setFormData({
      ...initialForm,
      course_id: courses[0]?.id || '',
      tutor_id: tutors[0]?.id || '',
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleOpenEdit = (session: ClassSession) => {
    setEditId(session.id)
    setFormData({
      course_id: session.course_id,
      tutor_id: session.tutor_id,
      title: session.title,
      description: session.description || '',
      platform: (session.platform === 'teams' ? 'teams' : session.platform === 'zoom' ? 'zoom' : 'livekit'),
      meeting_url: session.meeting_url || '',
      meeting_id: session.meeting_id || '',
      meeting_password: session.meeting_password || '',
      scheduled_date: session.scheduled_date,
      start_time: session.start_time,
      end_time: session.end_time,
      status: (session.status as 'scheduled' | 'live' | 'completed' | 'cancelled') || 'scheduled',
      admin_notes: session.admin_notes || '',
      recording_url: session.recording_url || '',
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleFormSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setFormError('')
    setSubmitting(true)

    // Validation
    if (!formData.course_id) {
      setFormError('Please select a course.')
      setSubmitting(false)
      return
    }
    if (!formData.tutor_id) {
      setFormError('Please assign a faculty tutor.')
      setSubmitting(false)
      return
    }
    if (!formData.title.trim()) {
      setFormError('Class title is required.')
      setSubmitting(false)
      return
    }
    if (!formData.scheduled_date) {
      setFormError('Scheduled date is required.')
      setSubmitting(false)
      return
    }
    if (!formData.start_time || !formData.end_time) {
      setFormError('Start time and end time are required.')
      setSubmitting(false)
      return
    }
    if (formData.start_time >= formData.end_time) {
      setFormError('End time must be after start time.')
      setSubmitting(false)
      return
    }
    if (formData.meeting_url.trim() && !/^https?:\/\/.+/i.test(formData.meeting_url.trim())) {
      setFormError('Please enter a valid meeting URL (e.g. https://zoom.us/j/... or https://meet.google.com/...)')
      setSubmitting(false)
      return
    }

    try {
      if (editId) {
        await API.put(`/admin/class-sessions/${editId}`, formData)
        setSuccessMsg('Live class session updated successfully.')
      } else {
        await API.post('/admin/class-sessions', formData)
        setSuccessMsg('Live class session scheduled successfully.')
      }
      setModalOpen(false)
      loadSessions()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const errObj = response.response?.data?.errors
      if (errObj) {
        const firstErr = Object.values(errObj)[0]?.[0]
        setFormError(firstErr || 'Validation error occurred.')
      } else {
        setFormError(response.response?.data?.message || 'Failed to save class session.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  const handleCancelSession = async (session: ClassSession) => {
    if (!window.confirm(`Are you sure you want to cancel the session "${session.title}"? Students will be notified.`)) {
      return
    }
    try {
      await API.post(`/admin/class-sessions/${session.id}/cancel`)
      setSuccessMsg(`Session "${session.title}" marked as cancelled.`)
      loadSessions()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setErrorMsg(response.response?.data?.message || 'Failed to cancel session.')
    }
  }

  const handleDeleteSession = async (session: ClassSession) => {
    if (!window.confirm(`Are you sure you want to permanently delete session "${session.title}"?`)) {
      return
    }
    try {
      await API.delete(`/admin/class-sessions/${session.id}`)
      setSuccessMsg(`Session "${session.title}" deleted permanently.`)
      loadSessions()
      if (selectedSession?.id === session.id) {
        setDetailsModalOpen(false)
      }
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setErrorMsg(response.response?.data?.message || 'Failed to delete session.')
    }
  }

  const handleOpenDetails = async (session: ClassSession) => {
    try {
      const res = await API.get<ClassSession>(`/admin/class-sessions/${session.id}`)
      setSelectedSession(res.data)
      setDetailsModalOpen(true)
    } catch {
      setSelectedSession(session)
      setDetailsModalOpen(true)
    }
  }

  const handleUploadMaterial = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedSession || !materialFile || !materialTitle.trim()) {
      setMaterialError('Title and file are required.')
      return
    }
    setUploadingMaterial(true)
    setMaterialError('')

    const data = new FormData()
    data.append('title', materialTitle)
    if (materialDescription.trim()) {
      data.append('description', materialDescription)
    }
    data.append('file', materialFile)

    try {
      const res = await API.post<{ material: ClassMaterial }>(`/admin/class-sessions/${selectedSession.id}/materials`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      if (selectedSession.materials) {
        setSelectedSession({
          ...selectedSession,
          materials: [res.data.material, ...selectedSession.materials],
        })
      } else {
        setSelectedSession({
          ...selectedSession,
          materials: [res.data.material],
        })
      }
      setMaterialTitle('')
      setMaterialDescription('')
      setMaterialFile(null)
      loadSessions()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setMaterialError(response.response?.data?.message || 'Failed to upload material.')
    } finally {
      setUploadingMaterial(false)
    }
  }

  const handleDeleteMaterial = async (materialId: number) => {
    if (!selectedSession || !window.confirm('Delete this class material?')) return
    try {
      await API.delete(`/admin/class-sessions/${selectedSession.id}/materials/${materialId}`)
      setSelectedSession({
        ...selectedSession,
        materials: selectedSession.materials?.filter((m) => m.id !== materialId) || [],
      })
      loadSessions()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      alert(response.response?.data?.message || 'Failed to remove material.')
    }
  }

  return (
    <div className="space-y-6">
      {/* Top Header, Action & Tab Navigation */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-950/70 p-6 rounded-3xl border border-slate-800 shadow-xl backdrop-blur-md">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-xl">🎥</span>
            <h1 className="text-2xl font-black tracking-tight text-white">Live Class Sessions</h1>
            <span className="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-purple-900/60 text-purple-300 border border-purple-700/50">
              Admin Exclusive
            </span>
          </div>
          <p className="text-xs text-slate-400 mt-1">
            Schedule, manage, and monitor active interactive live classrooms. Past sessions automatically transition to Class History.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {/* View Switcher Tabs */}
          <div className="flex items-center gap-1.5 bg-slate-900 p-1.5 rounded-2xl border border-slate-800">
            <div className="px-3.5 py-2 rounded-xl text-xs font-black text-white bg-purple-600 shadow-md shadow-purple-600/30 flex items-center gap-1.5">
              <span>🎥</span> Current Classes ({counts.scheduled + counts.live})
            </div>
            <Link
              to="/admin/class-history"
              className="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-800 transition flex items-center gap-1.5"
            >
              <span>📜</span> Class History
            </Link>
          </div>

          <button
            type="button"
            onClick={handleOpenCreate}
            className="px-5 py-2.5 rounded-xl font-extrabold text-xs bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 active:scale-95 text-white transition flex items-center justify-center gap-2 shadow-lg shadow-purple-600/30 shrink-0"
          >
            <span>+</span> Create Live Class
          </button>
        </div>
      </div>

      {/* Alert Banners */}
      {successMsg && (
        <div className="p-4 rounded-2xl bg-emerald-950/80 border border-emerald-800 text-emerald-300 text-xs font-semibold flex items-center justify-between shadow-md">
          <span>✓ {successMsg}</span>
          <button type="button" onClick={() => setSuccessMsg('')} className="text-emerald-400 hover:text-white text-sm">✕</button>
        </div>
      )}
      {errorMsg && (
        <div className="p-4 rounded-2xl bg-red-950/80 border border-red-800 text-red-300 text-xs font-semibold flex items-center justify-between shadow-md">
          <span>⚠️ {errorMsg}</span>
          <button type="button" onClick={() => setErrorMsg('')} className="text-red-400 hover:text-white text-sm">✕</button>
        </div>
      )}

      {/* Metrics Summary Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div className="bg-slate-950/60 p-4 rounded-2xl border border-slate-800 flex flex-col justify-between">
          <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Active</span>
          <span className="text-2xl font-black text-white mt-1">{counts.scheduled + counts.live}</span>
        </div>
        <div className="bg-slate-950/60 p-4 rounded-2xl border border-blue-900/40 flex flex-col justify-between">
          <span className="text-[11px] font-bold uppercase tracking-wider text-blue-400">Scheduled</span>
          <span className="text-2xl font-black text-blue-300 mt-1">{counts.scheduled}</span>
        </div>
        <div className="bg-slate-950/60 p-4 rounded-2xl border border-emerald-900/40 flex flex-col justify-between">
          <span className="text-[11px] font-bold uppercase tracking-wider text-emerald-400">Live Now</span>
          <span className="text-2xl font-black text-emerald-300 mt-1">{counts.live}</span>
        </div>
        <div className="bg-slate-950/60 p-4 rounded-2xl border border-indigo-900/40 flex flex-col justify-between">
          <span className="text-[11px] font-bold uppercase tracking-wider text-indigo-400">Completed</span>
          <span className="text-2xl font-black text-indigo-300 mt-1">{counts.completed}</span>
        </div>
        <div className="bg-slate-950/60 p-4 rounded-2xl border border-amber-900/40 flex flex-col justify-between">
          <span className="text-[11px] font-bold uppercase tracking-wider text-amber-400">Class History</span>
          <Link to="/admin/class-history" className="text-xs font-bold text-purple-400 hover:text-purple-300 underline mt-1 block">
            View Archives ↗
          </Link>
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div className="bg-slate-950/70 p-4 rounded-2xl border border-slate-800 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
        {/* Search */}
        <div className="lg:col-span-2">
          <input
            type="text"
            placeholder="Search batch code, course, tutor, meeting ID..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs text-slate-200 focus:outline-hidden focus:border-purple-500 placeholder:text-slate-500"
          />
        </div>

        {/* Course Filter */}
        <div>
          <select
            value={filterCourse}
            onChange={(e) => setFilterCourse(e.target.value)}
            aria-label="Filter by course"
            className="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs text-slate-200 focus:outline-hidden focus:border-purple-500"
          >
            <option value="all">All Courses</option>
            {courses.map((c) => (
              <option key={c.id} value={c.id}>
                {c.title}
              </option>
            ))}
          </select>
        </div>

        {/* Tutor Filter */}
        <div>
          <select
            value={filterTutor}
            onChange={(e) => setFilterTutor(e.target.value)}
            aria-label="Filter by faculty tutor"
            className="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs text-slate-200 focus:outline-hidden focus:border-purple-500"
          >
            <option value="all">All Faculty</option>
            {tutors.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>
        </div>

        {/* Platform Filter */}
        <div>
          <select
            value={filterPlatform}
            onChange={(e) => setFilterPlatform(e.target.value)}
            aria-label="Filter by platform"
            className="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs text-slate-200 focus:outline-hidden focus:border-purple-500"
          >
            <option value="all">All Platforms</option>
            <option value="zoom">Zoom</option>
            <option value="teams">Microsoft Teams</option>
          </select>
        </div>

        {/* Status Filter */}
        <div>
          <select
            value={filterStatus}
            onChange={(e) => setFilterStatus(e.target.value)}
            aria-label="Filter by status"
            className="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs text-slate-200 focus:outline-hidden focus:border-purple-500"
          >
            <option value="all">All Active Statuses</option>
            <option value="scheduled">Scheduled</option>
            <option value="live">Live</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
            <option value="expired">Expired</option>
          </select>
        </div>

        {/* Date Filter */}
        <div>
          <input
            type="date"
            value={filterDate}
            onChange={(e) => setFilterDate(e.target.value)}
            aria-label="Filter by date"
            className="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs text-slate-200 focus:outline-hidden focus:border-purple-500"
          />
        </div>
      </div>

      {/* Sessions Table */}
      <div className="bg-slate-950/80 rounded-3xl border border-slate-800 overflow-hidden shadow-xl">
        {loading ? (
          <div className="py-20 text-center text-slate-400 text-xs font-semibold">
            <span className="inline-block animate-spin mr-2">⚡</span> Loading class sessions...
          </div>
        ) : sessions.length === 0 ? (
          <div className="py-20 text-center space-y-3">
            <span className="text-4xl">📅</span>
            <h3 className="text-sm font-bold text-white">No active class sessions found</h3>
            <p className="text-xs text-slate-400 max-w-sm mx-auto">
              No current live classes match your filters. Click &quot;Create Live Class&quot; to schedule your next interactive session or view past archives in Class History.
            </p>
            <div className="pt-2">
              <Link
                to="/admin/class-history"
                className="px-4 py-2 rounded-xl text-xs font-bold bg-purple-950 text-purple-300 border border-purple-800 hover:bg-purple-900 transition"
              >
                Go to Class History ↗
              </Link>
            </div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-900/90 text-slate-400 font-bold border-b border-slate-800 uppercase tracking-wider text-[10px]">
                <tr>
                  <th className="py-3.5 px-4">Batch Number & Course</th>
                  <th className="py-3.5 px-4">Faculty Tutor</th>
                  <th className="py-3.5 px-4">Schedule (IST)</th>
                  <th className="py-3.5 px-4">Platform & ID</th>
                  <th className="py-3.5 px-4">Status</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/80 text-slate-200">
                {sortedSessions.map((session) => {
                  const isZoom = session.platform === 'zoom'
                  const batchCode = session.batch_code || session.batch_number || session.batch?.code || session.title
                  return (
                    <tr key={session.id} className="hover:bg-slate-900/50 transition">
                      <td className="py-4 px-4">
                        <div className="space-y-1">
                          <span className="font-mono text-xs font-black text-cyan-300 bg-cyan-950/80 px-2.5 py-0.5 rounded border border-cyan-800 block w-fit">
                            {batchCode}
                          </span>
                          <p className="text-xs font-bold text-white line-clamp-1">{session.course?.title || `Course #${session.course_id}`}</p>
                          {session.title !== batchCode && (
                            <p className="text-[11px] text-slate-400 line-clamp-1">{session.title}</p>
                          )}
                        </div>
                      </td>
                      <td className="py-4 px-4">
                        <div className="flex items-center gap-2">
                          <div className="w-7 h-7 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-xs text-purple-300">
                            {session.tutor?.name?.charAt(0) || 'T'}
                          </div>
                          <div>
                            <p className="font-bold text-xs text-white">{session.tutor?.name || 'Tutor'}</p>
                            <p className="text-[10px] text-slate-500 font-mono">ID: #{session.tutor_id}</p>
                          </div>
                        </div>
                      </td>
                      <td className="py-4 px-4 whitespace-nowrap">
                        <div>
                          <p className="font-bold text-slate-200">📅 {session.scheduled_date}</p>
                          <p className="text-[11px] text-slate-400 font-mono mt-0.5">
                            ⏰ {session.start_time} – {session.end_time} IST
                          </p>
                        </div>
                      </td>
                      <td className="py-4 px-4 whitespace-nowrap">
                        <div className="space-y-1">
                          {session.platform === 'livekit' || session.livekit_room_name || !session.meeting_url ? (
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-cyan-950/80 text-cyan-300 border border-cyan-800">
                              <span>🎙</span> MasterInTech Live
                            </span>
                          ) : (
                            <span
                              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                                isZoom
                                  ? 'bg-blue-950/80 text-blue-300 border border-blue-800'
                                  : 'bg-indigo-950/80 text-indigo-300 border border-indigo-800'
                              }`}
                            >
                              <span>{isZoom ? '📹' : '👥'}</span>
                              {isZoom ? 'Zoom' : 'Microsoft Teams'}
                            </span>
                          )}
                          <p className="text-[10px] text-slate-400 font-mono">
                            {session.livekit_room_name || session.meeting_id || `masterintech-class-${session.id}`}
                          </p>
                        </div>
                      </td>
                      <td className="py-4 px-4 whitespace-nowrap">
                        <span
                          className={`px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider border ${
                            session.status === 'live'
                              ? 'bg-emerald-950 text-emerald-300 border-emerald-600 animate-pulse'
                              : session.status === 'completed'
                              ? 'bg-slate-800 text-slate-300 border-slate-700'
                              : session.status === 'cancelled'
                              ? 'bg-rose-950 text-rose-300 border-rose-800'
                              : session.status === 'expired'
                              ? 'bg-amber-950 text-amber-300 border-amber-800'
                              : 'bg-blue-950 text-blue-300 border-blue-800'
                          }`}
                        >
                          {session.status}
                        </span>
                      </td>
                      <td className="py-4 px-4 text-right whitespace-nowrap">
                        <div className="flex items-center justify-end gap-1.5">
                          {(session.status === 'scheduled' || session.status === 'live') && (
                            <Link
                              to={`/student/classroom/${session.id}`}
                              className="px-2.5 py-1 rounded-lg text-xs font-black bg-cyan-950 hover:bg-cyan-900 text-cyan-300 border border-cyan-800 transition inline-flex items-center gap-1 shadow-xs"
                              title="Enter Live Classroom as Administrator Host"
                            >
                              <span>🎙</span> Enter
                            </Link>
                          )}
                          <button
                            type="button"
                            onClick={() => handleOpenDetails(session)}
                            className="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 transition"
                            title="View Session & Materials"
                          >
                            Details
                          </button>
                          <button
                            type="button"
                            onClick={() => handleOpenEdit(session)}
                            className="px-2.5 py-1 rounded-lg text-xs font-bold bg-purple-900/60 hover:bg-purple-800 text-purple-200 border border-purple-700/50 transition"
                            title="Edit Session"
                          >
                            Edit
                          </button>
                          {session.status !== 'cancelled' && session.status !== 'completed' && (
                            <button
                              type="button"
                              onClick={() => handleCancelSession(session)}
                              className="px-2.5 py-1 rounded-lg text-xs font-bold bg-amber-950 hover:bg-amber-900 text-amber-300 border border-amber-800 transition"
                              title="Cancel Session"
                            >
                              Cancel
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={() => handleDeleteSession(session)}
                            className="px-2.5 py-1 rounded-lg text-xs font-bold bg-rose-950/60 hover:bg-rose-900 text-rose-300 border border-rose-800 transition"
                            title="Delete Session"
                          >
                            Delete
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

      {/* Create / Edit Modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4 overflow-y-auto backdrop-blur-xs">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl space-y-6 my-8 text-slate-100">
            <div className="flex items-center justify-between border-b border-slate-800 pb-4">
              <div>
                <h2 className="text-xl font-black text-white">
                  {editId ? 'Edit Live Class Session' : 'Create Live Class Session'}
                </h2>
                <p className="text-xs text-slate-400 mt-0.5">
                  Configure live meeting parameters in Indian Standard Time (IST).
                </p>
              </div>
              <button
                type="button"
                onClick={() => setModalOpen(false)}
                className="text-slate-400 hover:text-white text-lg font-bold p-1"
              >
                ✕
              </button>
            </div>

            {formError && (
              <div className="p-3.5 rounded-xl bg-red-950/80 border border-red-800 text-red-300 text-xs font-semibold">
                ⚠️ {formError}
              </div>
            )}

            <form onSubmit={handleFormSubmit} className="space-y-4 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {/* Course Selection */}
                <div>
                  <label htmlFor="modal-course-select" className="block text-slate-300 font-bold mb-1">Course *</label>
                  <select
                    id="modal-course-select"
                    value={formData.course_id}
                    onChange={(e) => setFormData({ ...formData, course_id: Number(e.target.value) || '' })}
                    required
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                  >
                    <option value="">Select Course</option>
                    {courses.map((c) => (
                      <option key={c.id} value={c.id}>{c.title}</option>
                    ))}
                  </select>
                </div>

                {/* Tutor Selection */}
                <div>
                  <label htmlFor="modal-tutor-select" className="block text-slate-300 font-bold mb-1">Assigned Faculty Tutor *</label>
                  <select
                    id="modal-tutor-select"
                    value={formData.tutor_id}
                    onChange={(e) => setFormData({ ...formData, tutor_id: Number(e.target.value) || '' })}
                    required
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                  >
                    <option value="">Select Tutor</option>
                    {tutors.map((t) => (
                      <option key={t.id} value={t.id}>{t.name} ({t.email})</option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Class Title */}
              <div>
                <label className="block text-slate-300 font-bold mb-1">Class Title *</label>
                <input
                  type="text"
                  placeholder="e.g. Masterclass: Advanced State & API Optimization"
                  value={formData.title}
                  onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                  required
                  className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                />
              </div>

              {/* Date & Time (IST) */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-slate-300 font-bold mb-1">Date (IST) *</label>
                  <input
                    type="date"
                    value={formData.scheduled_date}
                    onChange={(e) => setFormData({ ...formData, scheduled_date: e.target.value })}
                    required
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                  />
                </div>
                <div>
                  <label className="block text-slate-300 font-bold mb-1">Start Time (IST) *</label>
                  <input
                    type="time"
                    value={formData.start_time}
                    onChange={(e) => setFormData({ ...formData, start_time: e.target.value })}
                    required
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                  />
                </div>
                <div>
                  <label className="block text-slate-300 font-bold mb-1">End Time (IST) *</label>
                  <input
                    type="time"
                    value={formData.end_time}
                    onChange={(e) => setFormData({ ...formData, end_time: e.target.value })}
                    required
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                  />
                </div>
              </div>

              {/* MasterInTech Internal Live Classroom Provisioning */}
              <div className="p-4 rounded-2xl bg-cyan-950/40 border border-cyan-800/70 flex items-start gap-3">
                <span className="text-2xl">🎙</span>
                <div className="space-y-1 text-xs">
                  <div className="flex items-center gap-2">
                    <p className="font-bold text-cyan-300">MasterInTech Internal Live Classroom (LiveKit WebRTC)</p>
                    <span className="px-2 py-0.5 rounded-full text-[9px] font-black bg-cyan-900 text-cyan-200 border border-cyan-700 uppercase">
                      Automatic Room
                    </span>
                  </div>
                  <p className="text-slate-400 text-[11px] leading-relaxed">
                    No external Zoom or Google Meet links required. A dedicated internal WebRTC room with Host controls, Tutor moderation, Student speaking permissions, raised hand queue, and real-time attendance will be provisioned automatically.
                  </p>
                </div>
              </div>

              {/* Status */}
              <div>
                <label htmlFor="modal-status-select" className="block text-slate-300 font-bold mb-1">Status</label>
                <select
                  id="modal-status-select"
                  value={formData.status}
                  onChange={(e) => setFormData({ ...formData, status: e.target.value as 'scheduled' | 'live' | 'completed' | 'cancelled' })}
                  className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                >
                  <option value="scheduled">Scheduled</option>
                  <option value="live">Live</option>
                  <option value="completed">Completed</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </div>

              {/* Description */}
              <div>
                <label className="block text-slate-300 font-bold mb-1">Class Description</label>
                <textarea
                  rows={2}
                  placeholder="Topics, agenda, prerequisites..."
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                />
              </div>

              {/* Admin Notes */}
              <div>
                <label className="block text-slate-300 font-bold mb-1">Admin Internal Notes</label>
                <textarea
                  rows={2}
                  placeholder="Internal notes for trainers or management..."
                  value={formData.admin_notes}
                  onChange={(e) => setFormData({ ...formData, admin_notes: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:outline-hidden focus:border-purple-500"
                />
              </div>

              {/* Action Buttons */}
              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold bg-slate-800 text-slate-300 hover:bg-slate-700 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="px-6 py-2 rounded-xl text-xs font-extrabold bg-purple-600 hover:bg-purple-500 active:bg-purple-700 text-white transition flex items-center gap-2 shadow-lg shadow-purple-600/30"
                >
                  {submitting ? 'Saving...' : editId ? 'Save Changes' : 'Create Class'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Details & Materials Modal */}
      {detailsModalOpen && selectedSession && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4 overflow-y-auto backdrop-blur-xs">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-3xl w-full p-6 sm:p-8 shadow-2xl space-y-6 my-8 text-slate-100">
            <div className="flex items-center justify-between border-b border-slate-800 pb-4">
              <div>
                <span className="text-[10px] font-black uppercase text-purple-400 bg-purple-950/60 px-2 py-0.5 rounded border border-purple-800">
                  {selectedSession.course?.title}
                </span>
                <h2 className="text-xl font-black text-white mt-1">{selectedSession.title}</h2>
              </div>
              <button
                type="button"
                onClick={() => setDetailsModalOpen(false)}
                className="text-slate-400 hover:text-white text-lg font-bold p-1"
              >
                ✕
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
              <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 space-y-2">
                <p><strong className="text-slate-400">Faculty Trainer:</strong> {selectedSession.tutor?.name} ({selectedSession.tutor?.email})</p>
                <p><strong className="text-slate-400">Date:</strong> {selectedSession.scheduled_date}</p>
                <p><strong className="text-slate-400">Time:</strong> {selectedSession.start_time} – {selectedSession.end_time} IST</p>
                <p><strong className="text-slate-400">Platform:</strong> {selectedSession.platform === 'zoom' ? 'Zoom' : 'Microsoft Teams'}</p>
                <p><strong className="text-slate-400">Meeting ID:</strong> {selectedSession.meeting_id || 'None'}</p>
                <p><strong className="text-slate-400">Passcode:</strong> {selectedSession.meeting_password || 'None'}</p>
                <p><strong className="text-slate-400">Status:</strong> <span className="font-bold uppercase text-purple-300">{selectedSession.status}</span></p>
              </div>

              <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 space-y-3 flex flex-col justify-between">
                <div>
                  <p className="font-bold text-slate-300 mb-1">Meeting Link</p>
                  <a
                    href={selectedSession.meeting_url || '#'}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-blue-400 hover:underline break-all font-mono text-[11px] block bg-slate-900 p-2.5 rounded-xl border border-slate-800"
                  >
                    {selectedSession.meeting_url || 'No URL configured'}
                  </a>
                </div>
                <button
                  type="button"
                  onClick={() => selectedSession.meeting_url && window.open(selectedSession.meeting_url, '_blank', 'noopener,noreferrer')}
                  className="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-extrabold text-xs transition flex items-center justify-center gap-1.5"
                >
                  <span>🚀</span> Launch Real Classroom
                </button>
              </div>
            </div>

            {/* Materials Management */}
            <div className="border-t border-slate-800 pt-5 space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="font-black text-sm text-white flex items-center gap-1.5">
                  <span>📚</span> Class Materials & Handouts ({selectedSession.materials?.length || 0})
                </h3>
              </div>

              {/* Upload Material Form */}
              <form onSubmit={handleUploadMaterial} className="bg-slate-950 p-4 rounded-2xl border border-slate-800 space-y-3 text-xs">
                <p className="font-bold text-slate-300">Upload New Material (PDF, DOC, Slides, Zip)</p>
                {materialError && (
                  <p className="text-red-400 font-semibold">⚠️ {materialError}</p>
                )}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <input
                    type="text"
                    placeholder="Material Title (e.g. Slide Deck PDF)"
                    value={materialTitle}
                    onChange={(e) => setMaterialTitle(e.target.value)}
                    required
                    className="px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-hidden"
                  />
                  <input
                    type="file"
                    accept=".pdf,.doc,.docx,.ppt,.pptx,.txt,.zip"
                    onChange={(e) => setMaterialFile(e.target.files?.[0] || null)}
                    required
                    className="px-3 py-1.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-300 text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-purple-600 file:text-white"
                  />
                </div>
                <div className="flex items-center justify-end">
                  <button
                    type="submit"
                    disabled={uploadingMaterial}
                    className="px-4 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-bold text-xs transition"
                  >
                    {uploadingMaterial ? 'Uploading...' : '+ Attach Material'}
                  </button>
                </div>
              </form>

              {/* Materials List */}
              <div className="space-y-2">
                {selectedSession.materials && selectedSession.materials.length > 0 ? (
                  selectedSession.materials.map((m) => (
                    <div
                      key={m.id}
                      className="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between gap-3 text-xs"
                    >
                      <div className="flex items-center gap-2.5">
                        <span className="text-base">📄</span>
                        <div>
                          <p className="font-bold text-white">{m.title}</p>
                          <p className="text-[10px] text-slate-400">{m.file_name} • {(m.file_size / 1024).toFixed(1)} KB</p>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        <a
                          href={m.file_path}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="px-3 py-1 rounded-lg bg-blue-900/60 hover:bg-blue-800 text-blue-200 font-bold text-xs transition"
                        >
                          Download
                        </a>
                        <button
                          type="button"
                          onClick={() => handleDeleteMaterial(m.id)}
                          className="px-2 py-1 rounded-lg bg-rose-950/60 hover:bg-rose-900 text-rose-300 font-bold text-xs transition"
                        >
                          ✕
                        </button>
                      </div>
                    </div>
                  ))
                ) : (
                  <p className="text-slate-500 text-xs italic">No materials uploaded yet for this session.</p>
                )}
              </div>
            </div>

            {/* Close Button */}
            <div className="flex items-center justify-end pt-4 border-t border-slate-800">
              <button
                type="button"
                onClick={() => setDetailsModalOpen(false)}
                className="px-5 py-2 rounded-xl text-xs font-bold bg-slate-800 text-white hover:bg-slate-700 transition"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
