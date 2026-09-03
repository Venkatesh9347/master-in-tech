import { useEffect, useState, useCallback, useMemo } from 'react'
import { Link } from 'react-router-dom'
import API from '../../services/api'
import type { ClassSession } from '../../types/classSession'
import type { Course } from '../../types/course'
import type { User } from '../../context/auth-context'

interface BatchOption {
  id: number
  code: string
  name?: string | null
  course_id: number
}

interface HistoryCounts {
  total: number
  expired: number
  completed: number
  cancelled: number
}

export default function AdminClassHistory() {
  const [history, setHistory] = useState<ClassSession[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [tutors, setTutors] = useState<User[]>([])
  const [batches, setBatches] = useState<BatchOption[]>([])
  const [counts, setCounts] = useState<HistoryCounts>({ total: 0, expired: 0, completed: 0, cancelled: 0 })
  const [loading, setLoading] = useState(true)
  const [errorMsg, setErrorMsg] = useState('')

  // Filters
  const [search, setSearch] = useState('')
  const [filterCourse, setFilterCourse] = useState('all')
  const [filterBatch, setFilterBatch] = useState('all')
  const [filterTutor, setFilterTutor] = useState('all')
  const [filterPlatform, setFilterPlatform] = useState('all')
  const [filterStatus, setFilterStatus] = useState('all')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')

  // Details Modal
  const [selectedSession, setSelectedSession] = useState<ClassSession | null>(null)
  const [detailsModalOpen, setDetailsModalOpen] = useState(false)
  const [detailsLoading, setDetailsLoading] = useState(false)

  const loadHistory = useCallback(() => {
    setLoading(true)
    setErrorMsg('')
    const params: Record<string, string> = {}
    if (filterCourse !== 'all') params.course_id = filterCourse
    if (filterBatch !== 'all') params.batch_id = filterBatch
    if (filterTutor !== 'all') params.tutor_id = filterTutor
    if (filterPlatform !== 'all') params.platform = filterPlatform
    if (filterStatus !== 'all') params.status = filterStatus
    if (fromDate) params.from_date = fromDate
    if (toDate) params.to_date = toDate
    if (search.trim()) params.search = search.trim()

    API.get<{
      history: ClassSession[]
      counts: HistoryCounts
      courses: Course[]
      tutors: User[]
      batches: BatchOption[]
    }>('/admin/class-history', { params })
      .then((res) => {
        setHistory(res.data.history || [])
        if (res.data.counts) setCounts(res.data.counts)
        if (res.data.courses) setCourses(res.data.courses)
        if (res.data.tutors) setTutors(res.data.tutors)
        if (res.data.batches) setBatches(res.data.batches)
      })
      .catch(() => setErrorMsg('Failed to load class history. Please try again.'))
      .finally(() => setLoading(false))
  }, [filterCourse, filterBatch, filterTutor, filterPlatform, filterStatus, fromDate, toDate, search])

  useEffect(() => {
    loadHistory()
  }, [loadHistory])

  const handleOpenDetails = (session: ClassSession) => {
    setSelectedSession(session)
    setDetailsModalOpen(true)
    setDetailsLoading(true)
    API.get<ClassSession>(`/admin/class-history/${session.id}`)
      .then((res) => {
        setSelectedSession(res.data)
      })
      .catch(() => {
        // Fallback to existing session data
      })
      .finally(() => setDetailsLoading(false))
  }

  const handleResetFilters = () => {
    setSearch('')
    setFilterCourse('all')
    setFilterBatch('all')
    setFilterTutor('all')
    setFilterPlatform('all')
    setFilterStatus('all')
    setFromDate('')
    setToDate('')
  }

  const hasActiveFilters = useMemo(() => {
    return (
      search.trim() !== '' ||
      filterCourse !== 'all' ||
      filterBatch !== 'all' ||
      filterTutor !== 'all' ||
      filterPlatform !== 'all' ||
      filterStatus !== 'all' ||
      fromDate !== '' ||
      toDate !== ''
    )
  }, [search, filterCourse, filterBatch, filterTutor, filterPlatform, filterStatus, fromDate, toDate])

  const getStatusBadge = (status: string) => {
    const st = (status || '').toLowerCase()
    if (st === 'expired') {
      return (
        <span className="px-2.5 py-1 rounded-full text-[11px] font-black uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-500/40">
          ⏳ EXPIRED
        </span>
      )
    }
    if (st === 'completed') {
      return (
        <span className="px-2.5 py-1 rounded-full text-[11px] font-black uppercase tracking-wider bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
          ✅ COMPLETED
        </span>
      )
    }
    if (st === 'cancelled') {
      return (
        <span className="px-2.5 py-1 rounded-full text-[11px] font-black uppercase tracking-wider bg-rose-500/20 text-rose-300 border border-rose-500/40">
          ❌ CANCELLED
        </span>
      )
    }
    return (
      <span className="px-2.5 py-1 rounded-full text-[11px] font-black uppercase tracking-wider bg-slate-700 text-slate-300 border border-slate-600">
        {status.toUpperCase()}
      </span>
    )
  }

  return (
    <div className="space-y-6">
      {/* Top Header & Tab Navigation */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-5">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-black text-white tracking-tight flex items-center gap-2">
              <span>📜</span> Class History & Archives
            </h1>
            <span className="px-3 py-0.5 rounded-full text-xs font-bold bg-purple-950 text-purple-300 border border-purple-800">
              Admin C-Panel
            </span>
          </div>
          <p className="text-xs sm:text-sm text-slate-400 mt-1">
            Complete historical audit trail of all expired, completed, and cancelled live class sessions.
          </p>
        </div>

        {/* View Switcher Tabs */}
        <div className="flex items-center gap-2 bg-slate-950 p-1.5 rounded-2xl border border-slate-800 self-start sm:self-center">
          <Link
            to="/admin/class-sessions"
            className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-800 transition flex items-center gap-1.5"
          >
            <span>🎥</span> Current Live Classes
          </Link>
          <div className="px-4 py-2 rounded-xl text-xs font-black text-white bg-purple-600 shadow-md shadow-purple-600/30 flex items-center gap-1.5">
            <span>📜</span> Class History ({counts.total})
          </div>
        </div>
      </div>

      {errorMsg && (
        <div className="p-4 rounded-2xl bg-rose-950/80 border border-rose-800 text-rose-200 text-xs flex items-center justify-between">
          <span>⚠️ {errorMsg}</span>
          <button
            type="button"
            onClick={loadHistory}
            className="underline font-bold hover:text-white"
          >
            Retry
          </button>
        </div>
      )}

      {/* KPI Metric Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div className="p-4 rounded-2xl bg-slate-950 border border-slate-800 flex items-center justify-between">
          <div>
            <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">All History</p>
            <p className="text-2xl font-black text-white font-mono mt-0.5">{counts.total}</p>
          </div>
          <span className="text-2xl p-2.5 rounded-xl bg-purple-950/50 border border-purple-800 text-purple-300">
            📜
          </span>
        </div>

        <div className="p-4 rounded-2xl bg-slate-950 border border-slate-800 flex items-center justify-between">
          <div>
            <p className="text-[11px] font-bold text-amber-400 uppercase tracking-wider">Expired</p>
            <p className="text-2xl font-black text-amber-300 font-mono mt-0.5">{counts.expired}</p>
          </div>
          <span className="text-2xl p-2.5 rounded-xl bg-amber-950/50 border border-amber-800 text-amber-300">
            ⏳
          </span>
        </div>

        <div className="p-4 rounded-2xl bg-slate-950 border border-slate-800 flex items-center justify-between">
          <div>
            <p className="text-[11px] font-bold text-emerald-400 uppercase tracking-wider">Completed</p>
            <p className="text-2xl font-black text-emerald-300 font-mono mt-0.5">{counts.completed}</p>
          </div>
          <span className="text-2xl p-2.5 rounded-xl bg-emerald-950/50 border border-emerald-800 text-emerald-300">
            ✅
          </span>
        </div>

        <div className="p-4 rounded-2xl bg-slate-950 border border-slate-800 flex items-center justify-between">
          <div>
            <p className="text-[11px] font-bold text-rose-400 uppercase tracking-wider">Cancelled</p>
            <p className="text-2xl font-black text-rose-300 font-mono mt-0.5">{counts.cancelled}</p>
          </div>
          <span className="text-2xl p-2.5 rounded-xl bg-rose-950/50 border border-rose-800 text-rose-300">
            ❌
          </span>
        </div>
      </div>

      {/* Advanced Filter Toolbar */}
      <div className="bg-slate-950 p-5 rounded-2xl border border-slate-800 space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-xs font-black uppercase text-slate-400 tracking-wider flex items-center gap-1.5">
            <span>🔍</span> History Filter Desk
          </h2>
          {hasActiveFilters && (
            <button
              type="button"
              onClick={handleResetFilters}
              className="text-xs font-bold text-purple-400 hover:text-purple-300 underline"
            >
              Reset Filters
            </button>
          )}
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
          {/* Search */}
          <div className="lg:col-span-2">
            <label className="block text-[10px] font-extrabold uppercase text-slate-400 mb-1">
              Search Session / Batch
            </label>
            <input
              type="text"
              placeholder="ID, Batch code, Title, Meeting ID..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white placeholder:text-slate-500 focus:outline-none focus:border-purple-500"
            />
          </div>

          {/* Filter Course */}
          <div>
            <label className="block text-[10px] font-extrabold uppercase text-slate-400 mb-1">
              Course
            </label>
            <select
              value={filterCourse}
              onChange={(e) => setFilterCourse(e.target.value)}
              className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500"
            >
              <option value="all">All Courses</option>
              {courses.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.title}
                </option>
              ))}
            </select>
          </div>

          {/* Filter Batch */}
          <div>
            <label className="block text-[10px] font-extrabold uppercase text-slate-400 mb-1">
              Batch
            </label>
            <select
              value={filterBatch}
              onChange={(e) => setFilterBatch(e.target.value)}
              className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500"
            >
              <option value="all">All Batches</option>
              {batches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.code}
                </option>
              ))}
            </select>
          </div>

          {/* Filter Faculty */}
          <div>
            <label className="block text-[10px] font-extrabold uppercase text-slate-400 mb-1">
              Faculty
            </label>
            <select
              value={filterTutor}
              onChange={(e) => setFilterTutor(e.target.value)}
              className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500"
            >
              <option value="all">All Faculty</option>
              {tutors.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </div>

          {/* Filter Platform */}
          <div>
            <label className="block text-[10px] font-extrabold uppercase text-slate-400 mb-1">
              Platform
            </label>
            <select
              value={filterPlatform}
              onChange={(e) => setFilterPlatform(e.target.value)}
              className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500"
            >
              <option value="all">All Platforms</option>
              <option value="zoom">Zoom</option>
              <option value="teams">Teams</option>
            </select>
          </div>

          {/* Filter Status */}
          <div>
            <label className="block text-[10px] font-extrabold uppercase text-slate-400 mb-1">
              Status
            </label>
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500"
            >
              <option value="all">All History</option>
              <option value="expired">Expired Only</option>
              <option value="completed">Completed Only</option>
              <option value="cancelled">Cancelled Only</option>
            </select>
          </div>

          {/* Date Filter Range */}
          <div>
            <label className="block text-[10px] font-extrabold uppercase text-slate-400 mb-1">
              From Date
            </label>
            <input
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
              className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500"
            />
          </div>
        </div>
      </div>

      {/* Class History Table */}
      <div className="bg-slate-950 rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        {loading ? (
          <div className="p-12 text-center text-slate-400 text-xs">
            <div className="inline-block w-8 h-8 border-2 border-purple-500 border-t-transparent rounded-full animate-spin mb-3" />
            <p>Loading Class History archive...</p>
          </div>
        ) : history.length === 0 ? (
          <div className="p-12 text-center space-y-3">
            <span className="text-4xl block">📜</span>
            <h3 className="text-sm font-bold text-white">No historical class records found</h3>
            <p className="text-xs text-slate-400 max-w-md mx-auto">
              {hasActiveFilters
                ? 'No historical class sessions match your selected filter criteria. Try resetting filters.'
                : 'When scheduled class sessions reach their end time, they will automatically appear here as EXPIRED.'}
            </p>
            {hasActiveFilters && (
              <button
                type="button"
                onClick={handleResetFilters}
                className="px-4 py-2 rounded-xl text-xs font-bold bg-slate-800 text-white hover:bg-slate-700 transition"
              >
                Clear All Filters
              </button>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs text-slate-300">
              <thead className="bg-slate-900/80 text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-800 font-extrabold">
                <tr>
                  <th className="py-3.5 px-4">ID</th>
                  <th className="py-3.5 px-4">Batch Number</th>
                  <th className="py-3.5 px-4">Course & Title</th>
                  <th className="py-3.5 px-4">Faculty / Tutor</th>
                  <th className="py-3.5 px-4">Platform</th>
                  <th className="py-3.5 px-4">Class Schedule</th>
                  <th className="py-3.5 px-4">Ended / Expired At</th>
                  <th className="py-3.5 px-4">Status</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60 font-medium">
                {history.map((s) => {
                  const batchCode = s.batch_code || s.batch_number || s.batch?.code || 'RIT(TECH)BC'
                  const isZoom = s.platform?.toLowerCase() === 'zoom'
                  const platformLabel = isZoom ? '📹 Zoom' : '👥 Teams'

                  return (
                    <tr key={`history-row-${s.id}`} className="hover:bg-slate-900/50 transition">
                      {/* Session ID */}
                      <td className="py-3.5 px-4">
                        <span className="font-mono text-[11px] font-bold text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">
                          #{s.id}
                        </span>
                      </td>

                      {/* Authoritative Batch Number */}
                      <td className="py-3.5 px-4">
                        <div className="space-y-0.5">
                          <span className="font-mono font-black text-cyan-300 text-xs tracking-wide block">
                            {batchCode}
                          </span>
                          {s.batch?.name && (
                            <span className="text-[10px] text-slate-500 truncate max-w-[150px] block">
                              {s.batch.name}
                            </span>
                          )}
                        </div>
                      </td>

                      {/* Course & Class Title */}
                      <td className="py-3.5 px-4">
                        <div className="max-w-[220px]">
                          <div className="flex items-center gap-1.5">
                            <span className="font-bold text-white truncate">{s.course?.title || s.course_title}</span>
                            {s.course_code && (
                              <span className="text-[10px] font-mono px-1.5 py-0.2 rounded bg-blue-950 text-blue-300 border border-blue-800">
                                {s.course_code}
                              </span>
                            )}
                          </div>
                          <p className="text-[11px] text-slate-400 truncate mt-0.5">{s.title}</p>
                        </div>
                      </td>

                      {/* Faculty / Tutor */}
                      <td className="py-3.5 px-4">
                        <div className="flex items-center gap-2">
                          <div className="w-6 h-6 rounded-full bg-purple-900 text-purple-200 font-bold flex items-center justify-center text-[10px] shrink-0">
                            {s.tutor?.name?.charAt(0) || 'F'}
                          </div>
                          <div>
                            <p className="font-bold text-slate-200 truncate max-w-[130px]">{s.tutor?.name || 'Faculty'}</p>
                            <p className="text-[10px] text-slate-500 font-mono">ID: #{s.tutor_id}</p>
                          </div>
                        </div>
                      </td>

                      {/* Platform */}
                      <td className="py-3.5 px-4">
                        <span className="inline-block px-2 py-0.5 rounded text-[11px] font-bold bg-slate-900 text-slate-300 border border-slate-800">
                          {platformLabel}
                        </span>
                      </td>

                      {/* Schedule */}
                      <td className="py-3.5 px-4">
                        <div>
                          <p className="text-slate-200 font-semibold">{s.scheduled_date}</p>
                          <p className="text-[10px] font-mono text-cyan-400">
                            {s.start_time} – {s.end_time} IST
                          </p>
                        </div>
                      </td>

                      {/* Expired / Ended At */}
                      <td className="py-3.5 px-4">
                        <span className="text-slate-300 font-mono text-[11px]">
                          {s.expired_at_formatted || (s.end_time ? `${s.end_time} IST` : '—')}
                        </span>
                      </td>

                      {/* Status */}
                      <td className="py-3.5 px-4">
                        {getStatusBadge(s.status)}
                      </td>

                      {/* Actions */}
                      <td className="py-3.5 px-4 text-right">
                        <button
                          type="button"
                          onClick={() => handleOpenDetails(s)}
                          className="px-3.5 py-1.5 rounded-xl text-xs font-bold text-purple-300 hover:text-white bg-purple-950/80 hover:bg-purple-900 border border-purple-800 transition"
                        >
                          Details 👁️
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* READ-ONLY DETAILS MODAL */}
      {detailsModalOpen && selectedSession && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-3xl w-full p-6 sm:p-8 space-y-6 shadow-2xl text-white my-8 max-h-[90vh] overflow-y-auto">
            {/* Modal Header */}
            <div className="flex items-start justify-between gap-4 border-b border-slate-800 pb-4">
              <div>
                <div className="flex items-center gap-2">
                  <span className="font-mono text-xs font-bold text-slate-400 bg-slate-800 px-2.5 py-0.5 rounded border border-slate-700">
                    Session ID: #{selectedSession.id}
                  </span>
                  {getStatusBadge(selectedSession.status)}
                </div>
                <h3 className="text-2xl font-black font-mono text-cyan-300 mt-2">
                  {selectedSession.batch_code || selectedSession.batch_number || selectedSession.batch?.code || 'RIT(TECH)BC'}
                </h3>
                <p className="text-xs text-slate-400 mt-0.5">
                  Course: <strong className="text-white">{selectedSession.course?.title || selectedSession.course_title}</strong>{' '}
                  {selectedSession.course_code && `(${selectedSession.course_code})`}
                </p>
              </div>

              <button
                type="button"
                onClick={() => setDetailsModalOpen(false)}
                className="p-2 rounded-xl text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
              >
                ✕
              </button>
            </div>

            {detailsLoading ? (
              <div className="p-8 text-center text-slate-400 text-xs">
                <div className="inline-block w-6 h-6 border-2 border-purple-500 border-t-transparent rounded-full animate-spin mb-2" />
                <p>Loading full history audit record...</p>
              </div>
            ) : (
              <div className="space-y-6 text-xs">
                {/* Session Information Grid */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-slate-950 p-5 rounded-2xl border border-slate-800">
                  <div>
                    <span className="text-[10px] font-extrabold uppercase text-slate-500 block">Class Title</span>
                    <p className="text-sm font-bold text-white mt-0.5">{selectedSession.title}</p>
                  </div>

                  <div>
                    <span className="text-[10px] font-extrabold uppercase text-slate-500 block">Assigned Faculty</span>
                    <p className="text-sm font-bold text-white mt-0.5">
                      {selectedSession.tutor?.name || selectedSession.tutor_name || 'Faculty'} (ID: #{selectedSession.tutor_id})
                    </p>
                  </div>

                  <div>
                    <span className="text-[10px] font-extrabold uppercase text-slate-500 block">Platform & Meeting ID</span>
                    <p className="font-mono text-slate-300 mt-0.5 uppercase">
                      {selectedSession.platform} • ID: {selectedSession.meeting_id || 'Embedded / Unspecified'}
                    </p>
                  </div>

                  <div>
                    <span className="text-[10px] font-extrabold uppercase text-slate-500 block">Passcode Security</span>
                    <p className="font-mono text-slate-400 mt-0.5">
                      {selectedSession.masked_password || (selectedSession.meeting_password ? '••••••••' : 'None')}
                    </p>
                  </div>

                  <div>
                    <span className="text-[10px] font-extrabold uppercase text-slate-500 block">Scheduled Date & Time</span>
                    <p className="font-mono text-cyan-300 font-bold mt-0.5">
                      📅 {selectedSession.scheduled_date} • ⏰ {selectedSession.start_time} – {selectedSession.end_time} IST
                    </p>
                  </div>

                  <div>
                    <span className="text-[10px] font-extrabold uppercase text-slate-500 block">Actual Expiration / End Time</span>
                    <p className="font-mono text-amber-300 font-bold mt-0.5">
                      ⏳ {selectedSession.expired_at_formatted || (selectedSession.end_time ? `${selectedSession.end_time} IST` : '—')}
                    </p>
                  </div>
                </div>

                {/* Meeting URL */}
                {selectedSession.meeting_url && (
                  <div className="p-4 rounded-2xl bg-slate-950 border border-slate-800 space-y-1">
                    <span className="text-[10px] font-extrabold uppercase text-slate-500 block">Configured Meeting URL</span>
                    <div className="flex items-center justify-between gap-3 overflow-hidden">
                      <span className="font-mono text-xs text-blue-300 truncate">{selectedSession.meeting_url}</span>
                      <a
                        href={selectedSession.meeting_url}
                        target="_blank"
                        rel="noreferrer"
                        className="px-3 py-1 rounded-lg bg-blue-950 text-blue-300 border border-blue-800 text-[11px] font-bold shrink-0 hover:bg-blue-900"
                      >
                        Open Link ↗
                      </a>
                    </div>
                  </div>
                )}

                {/* Descriptions & Notes */}
                <div className="space-y-4">
                  {selectedSession.description && (
                    <div>
                      <span className="text-[10px] font-extrabold uppercase text-slate-400 block mb-1">Class Description / Agenda</span>
                      <div className="p-4 rounded-xl bg-slate-950 border border-slate-800 text-slate-300 leading-relaxed">
                        {selectedSession.description}
                      </div>
                    </div>
                  )}

                  {selectedSession.admin_notes && (
                    <div>
                      <span className="text-[10px] font-extrabold uppercase text-slate-400 block mb-1">Internal Admin Notes</span>
                      <div className="p-4 rounded-xl bg-slate-950 border border-amber-900/40 text-amber-200/90 leading-relaxed">
                        {selectedSession.admin_notes}
                      </div>
                    </div>
                  )}
                </div>

                {/* Audit Lifecycle Metadata */}
                <div className="pt-4 border-t border-slate-800 grid grid-cols-1 sm:grid-cols-2 gap-3 text-[11px] text-slate-400">
                  <div>
                    <span className="text-slate-500">Created by:</span>{' '}
                    <strong className="text-slate-300">{selectedSession.created_by_name || 'System Admin'}</strong>
                    {selectedSession.created_at && (
                      <span className="block text-[10px] text-slate-500 font-mono mt-0.5">
                        {new Date(selectedSession.created_at).toLocaleString()}
                      </span>
                    )}
                  </div>

                  <div>
                    <span className="text-slate-500">Last updated by:</span>{' '}
                    <strong className="text-slate-300">{selectedSession.updated_by_name || 'Admin'}</strong>
                    {selectedSession.updated_at && (
                      <span className="block text-[10px] text-slate-500 font-mono mt-0.5">
                        {new Date(selectedSession.updated_at).toLocaleString()}
                      </span>
                    )}
                  </div>
                </div>
              </div>
            )}

            {/* Modal Footer */}
            <div className="pt-4 border-t border-slate-800 flex justify-end">
              <button
                type="button"
                onClick={() => setDetailsModalOpen(false)}
                className="px-6 py-2.5 rounded-xl font-bold text-xs bg-slate-800 hover:bg-slate-700 text-white transition"
              >
                Close View
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
