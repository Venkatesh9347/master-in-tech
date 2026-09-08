import { useEffect, useState, useMemo, useCallback, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import type { Course } from '../types/course'
import type { ClassSession } from '../types/classSession'

interface EnrollmentItem {
  id: number
  course_id: number
  status: string
  progress_percentage: number | string
  enrolled_at: string
  completed_lessons?: number
  total_lessons?: number
  is_course_completed?: boolean
  last_accessed_lesson?: {
    id: number
    title: string
    slug: string
  } | null
  course: Course & {
    sections_count?: number
    lessons_count?: number
    thumbnail?: string
  }
}

// In-memory cache with 60-second TTL to avoid redundant requests on frequent tab navigation
interface CacheEntry<T> {
  data: T
  timestamp: number
}
const studentDashboardCache: {
  enrollments?: CacheEntry<EnrollmentItem[]>
  sessions?: Record<string, CacheEntry<{
    today: ClassSession[]
    upcoming: ClassSession[]
    previous: ClassSession[]
    all: ClassSession[]
  }>>
} = {}

const CACHE_TTL_MS = 60000 // 60 seconds

/**
 * Get current Indian Standard Time (IST, Asia/Kolkata) date string (YYYY-MM-DD) and time (HH:mm).
 */
function getIstDateTime(now: Date = new Date()): { todayIst: string; timeIst: string } {
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Kolkata',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })

  const parts = formatter.formatToParts(now)
  const partMap: Record<string, string> = {}
  parts.forEach((p) => {
    partMap[p.type] = p.value
  })

  const todayIst = `${partMap.year}-${partMap.month}-${partMap.day}`
  const timeIst = `${partMap.hour}:${partMap.minute}`
  return { todayIst, timeIst }
}

/**
 * Evaluates whether a session is currently LIVE NOW based strictly on IST.
 */
function isCurrentlyLiveSession(session: ClassSession, atDate: Date = new Date()): boolean {
  if (session.status === 'cancelled' || session.status === 'completed' || session.status === 'expired') return false
  if (!session.scheduled_date || !session.start_time || !session.end_time) return false

  const hasUrl = Boolean(session.meeting_url && session.meeting_url.trim().length > 0)
  const isInternalLiveKit = session.platform === 'livekit' || Boolean(session.livekit_room_name) || session.status === 'live'
  if (!hasUrl && !isInternalLiveKit) return false

  const { todayIst, timeIst } = getIstDateTime(atDate)
  const sessionDate = session.scheduled_date.split('T')[0]

  // Must match today's date in IST
  if (sessionDate !== todayIst && session.status !== 'live') {
    return false
  }

  const start = session.start_time.slice(0, 5)
  const end = session.end_time.slice(0, 5)

  // Strict IST evaluation: start_time <= current_time < end_time (or status explicitly marked live)
  return (timeIst >= start && timeIst < end) || session.status === 'live'
}

export default function StudentDashboard() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [currentTime, setCurrentTime] = useState<Date>(() => new Date())

  // Real-time IST clock ticker: re-evaluates live class dashboard mode every 10 seconds
  useEffect(() => {
    const timer = setInterval(() => {
      setCurrentTime(new Date())
    }, 10000)
    return () => clearInterval(timer)
  }, [])

  // Data States
  const [enrollments, setEnrollments] = useState<EnrollmentItem[]>(() => {
    if (studentDashboardCache.enrollments && Date.now() - studentDashboardCache.enrollments.timestamp < CACHE_TTL_MS) {
      return studentDashboardCache.enrollments.data
    }
    return []
  })
  const [todaySessions, setTodaySessions] = useState<ClassSession[]>([])
  const [upcomingSessions, setUpcomingSessions] = useState<ClassSession[]>([])
  const [previousSessions, setPreviousSessions] = useState<ClassSession[]>([])
  const [allSessions, setAllSessions] = useState<ClassSession[]>([])

  // Section Loading States for Progressive Rendering
  const [loadingCourses, setLoadingCourses] = useState<boolean>(!studentDashboardCache.enrollments)
  const [loadingToday, setLoadingToday] = useState<boolean>(true)
  const [loadingUpcoming, setLoadingUpcoming] = useState<boolean>(true)
  const [loadingPrevious, setLoadingPrevious] = useState<boolean>(true)

  // Section Error States
  const [errorCourses, setErrorCourses] = useState<string | null>(null)
  const [errorToday, setErrorToday] = useState<string | null>(null)
  const [errorUpcoming, setErrorUpcoming] = useState<string | null>(null)
  const [errorPrevious, setErrorPrevious] = useState<string | null>(null)

  // Filters & Tabs
  const [selectedCourseFilter, setSelectedCourseFilter] = useState<string>('all')
  const [classFilterTab, setClassFilterTab] = useState<'all' | 'today' | 'upcoming' | 'previous'>('all')
  const [mainTab, setMainTab] = useState<'learning_hub' | 'courses'>('learning_hub')
  const [placementDashboardEnabled, setPlacementDashboardEnabled] = useState(false)

  useEffect(() => {
    API.get<{ placement_dashboard_enabled?: boolean }>('/student/placement-dashboard/status')
      .then((res) => {
        if (res.data?.placement_dashboard_enabled) {
          setPlacementDashboardEnabled(true)
        }
      })
      .catch(() => {})
  }, [])

  const isMountedRef = useRef(true)
  useEffect(() => {
    isMountedRef.current = true
    return () => {
      isMountedRef.current = false
    }
  }, [])

  // 1. Fetch Enrolled Courses independently
  const fetchEnrolledCourses = useCallback(async (bypassCache = false) => {
    if (!bypassCache && studentDashboardCache.enrollments && Date.now() - studentDashboardCache.enrollments.timestamp < CACHE_TTL_MS) {
      setEnrollments(studentDashboardCache.enrollments.data)
      setLoadingCourses(false)
      return
    }

    setLoadingCourses(true)
    setErrorCourses(null)
    try {
      const res = await API.get('/my-courses')
      const raw = res.data as unknown
      const data: EnrollmentItem[] = Array.isArray(raw)
        ? (raw as EnrollmentItem[])
        : Array.isArray((raw as { data?: EnrollmentItem[] })?.data)
        ? (raw as { data: EnrollmentItem[] }).data
        : []

      if (isMountedRef.current) {
        setEnrollments(data)
        studentDashboardCache.enrollments = { data, timestamp: Date.now() }
      }
    } catch {
      if (isMountedRef.current) {
        setErrorCourses('Unable to load enrolled courses.')
      }
    } finally {
      if (isMountedRef.current) {
        setLoadingCourses(false)
      }
    }
  }, [])

  // 2. Fetch Class Sessions Concurrently with Progressive Updates
  const fetchClassSessions = useCallback(async (courseFilter: string) => {
    const cacheKey = `filter_${courseFilter}`
    const cached = studentDashboardCache.sessions?.[cacheKey]
    if (cached && Date.now() - cached.timestamp < CACHE_TTL_MS) {
      setTodaySessions(cached.data.today)
      setUpcomingSessions(cached.data.upcoming)
      setPreviousSessions(cached.data.previous)
      setAllSessions(cached.data.all)
      setLoadingToday(false)
      setLoadingUpcoming(false)
      setLoadingPrevious(false)
      return
    }

    setLoadingToday(true)
    setLoadingUpcoming(true)
    setLoadingPrevious(true)
    setErrorToday(null)
    setErrorUpcoming(null)
    setErrorPrevious(null)

    const params: Record<string, string> = {}
    if (courseFilter !== 'all') {
      params.course_id = courseFilter
    }

    // Fire all 4 requests concurrently using Promise.allSettled
    const [todayRes, upcomingRes, prevRes, allRes] = await Promise.allSettled([
      API.get<ClassSession[]>('/student/class-sessions/today', { params }),
      API.get<ClassSession[]>('/student/class-sessions/upcoming', { params }),
      API.get<ClassSession[]>('/student/class-sessions/previous', { params }),
      API.get<ClassSession[]>('/student/class-sessions', { params }),
    ])

    if (!isMountedRef.current) return

    let resolvedToday: ClassSession[] = []
    let resolvedUpcoming: ClassSession[] = []
    let resolvedPrevious: ClassSession[] = []
    let resolvedAll: ClassSession[] = []

    if (todayRes.status === 'fulfilled') {
      resolvedToday = Array.isArray(todayRes.value.data) ? todayRes.value.data : []
      setTodaySessions(resolvedToday)
    } else {
      setErrorToday("Unable to load today's class schedule.")
    }
    setLoadingToday(false)

    if (upcomingRes.status === 'fulfilled') {
      resolvedUpcoming = Array.isArray(upcomingRes.value.data) ? upcomingRes.value.data : []
      setUpcomingSessions(resolvedUpcoming)
    } else {
      setErrorUpcoming('Unable to load upcoming classes.')
    }
    setLoadingUpcoming(false)

    if (prevRes.status === 'fulfilled') {
      resolvedPrevious = Array.isArray(prevRes.value.data) ? prevRes.value.data : []
      setPreviousSessions(resolvedPrevious)
    } else {
      setErrorPrevious('Unable to load previous classes.')
    }
    setLoadingPrevious(false)

    if (allRes.status === 'fulfilled') {
      resolvedAll = Array.isArray(allRes.value.data) ? allRes.value.data : []
      setAllSessions(resolvedAll)
    }

    // Update Cache
    if (!studentDashboardCache.sessions) studentDashboardCache.sessions = {}
    studentDashboardCache.sessions[cacheKey] = {
      data: {
        today: resolvedToday,
        upcoming: resolvedUpcoming,
        previous: resolvedPrevious,
        all: resolvedAll,
      },
      timestamp: Date.now(),
    }
  }, [])

  useEffect(() => {
    fetchEnrolledCourses()
  }, [fetchEnrolledCourses, user?.id])

  useEffect(() => {
    fetchClassSessions(selectedCourseFilter)
  }, [fetchClassSessions, selectedCourseFilter])

  const handleJoinClass = async (sessionId: number) => {
    const session =
      allSessions.find((s) => s.id === sessionId) ||
      todaySessions.find((s) => s.id === sessionId) ||
      upcomingSessions.find((s) => s.id === sessionId)

    if (session?.platform === 'livekit' || session?.livekit_room_name) {
      navigate(`/student/classroom/${sessionId}`)
      return
    }

    try {
      const res = await API.post<{ meeting_url: string }>(`/student/class-sessions/${sessionId}/join`)
      if (res.data.meeting_url) {
        if (res.data.meeting_url.startsWith('/')) {
          navigate(res.data.meeting_url)
        } else {
          window.open(res.data.meeting_url, '_blank', 'noopener,noreferrer')
        }
      } else {
        navigate(`/student/classroom/${sessionId}`)
      }
      fetchClassSessions(selectedCourseFilter)
    } catch {
      navigate(`/student/classroom/${sessionId}`)
    }
  }

  const todayFormattedDate = useMemo(() => {
    return new Intl.DateTimeFormat('en-GB', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(new Date())
  }, [])

  const notifications = useMemo(() => {
    const list: { id: string; type: 'live' | 'material' | 'quiz' | 'cancelled'; message: string; link?: string }[] = []

    if (todaySessions.length > 0) {
      list.push({
        id: 'notif-today',
        type: 'live',
        message: `You have ${todaySessions.length} live interactive class session scheduled for today.`,
      })
    }

    const recentWithMaterials = previousSessions.find((s) => (s.materials_count || 0) > 0)
    if (recentWithMaterials) {
      list.push({
        id: 'notif-mat',
        type: 'material',
        message: `New class materials (${recentWithMaterials.materials_count} files) shared in ${recentWithMaterials.course_title || 'your course'}.`,
        link: `/student/class-sessions/${recentWithMaterials.id}`,
      })
    }

    const availableQuiz = previousSessions.find((s) => s.quiz_status === 'Available')
    if (availableQuiz) {
      list.push({
        id: 'notif-quiz',
        type: 'quiz',
        message: `A module checkpoint quiz is available for ${availableQuiz.title}.`,
        link: `/student/class-sessions/${availableQuiz.id}`,
      })
    }

    const cancelledSession = allSessions.find((s) => s.status === 'cancelled')
    if (cancelledSession) {
      list.push({
        id: 'notif-cancelled',
        type: 'cancelled',
        message: `Session "${cancelledSession.title}" has been cancelled by administration.`,
      })
    }

    return list
  }, [todaySessions, previousSessions, allSessions])

  const liveSessionsWithUrl = useMemo(() => {
    const candidates = [...todaySessions, ...upcomingSessions]
    const seen = new Set<number>()
    const validSessions: ClassSession[] = []

    for (const s of candidates) {
      if (!seen.has(s.id)) {
        seen.add(s.id)
        if (isCurrentlyLiveSession(s, currentTime)) {
          validSessions.push(s)
        }
      }
    }
    // Live classes must always be sorted by start time ascending
    return validSessions.sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''))
  }, [todaySessions, upcomingSessions, currentTime])

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <Navbar />

      <main className="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
        {/* Welcome Header */}
        {liveSessionsWithUrl.length === 0 && (
          <section className="bg-gradient-to-r from-blue-700 via-indigo-700 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl mb-8 flex flex-col md:flex-row items-start md:items-center justify-between gap-6 relative overflow-hidden">
            <div className="space-y-2 relative z-10">
              <div className="flex items-center gap-2">
                <span className="bg-blue-500/30 text-blue-200 border border-blue-400/30 text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full">
                  Student Learning Portal
                </span>
                <span className="bg-slate-800/80 text-slate-200 text-[10px] font-bold px-2.5 py-0.5 rounded-full border border-slate-700">
                  📅 {todayFormattedDate}
                </span>
              </div>
              <h1 className="text-2xl sm:text-3xl font-black tracking-tight">
                Welcome back, {user?.name}! 🎓
              </h1>
              <p className="text-xs sm:text-sm text-blue-100 max-w-xl leading-relaxed">
                Track today&apos;s live classes, review previous lectures, download study handouts, take module quizzes, and advance your technology career.
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-3 relative z-10">
              <button
                type="button"
                onClick={() => setMainTab('learning_hub')}
                className={`px-5 py-2.5 rounded-xl font-bold text-xs transition shadow-md flex items-center gap-1.5 ${
                  mainTab === 'learning_hub'
                    ? 'bg-white text-blue-700 hover:bg-blue-50'
                    : 'bg-blue-600/80 hover:bg-blue-600 border border-blue-400/30 text-white'
                }`}
              >
                <span>🎥</span> Live & Previous Classes ({allSessions.length})
              </button>
              <button
                type="button"
                onClick={() => setMainTab('courses')}
                className={`px-5 py-2.5 rounded-xl font-bold text-xs transition flex items-center gap-1.5 ${
                  mainTab === 'courses'
                    ? 'bg-white text-blue-700 hover:bg-blue-50'
                    : 'bg-blue-600/80 hover:bg-blue-600 border border-blue-400/30 text-white'
                }`}
              >
                <span>📚</span> My Enrolled Courses ({enrollments.length})
              </button>

              <Link
                to="/student/mock-interview"
                className="px-5 py-2.5 rounded-xl font-extrabold text-xs transition flex items-center gap-1.5 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white shadow-md shadow-purple-900/30"
              >
                <span>🎙️</span> Mock Interview Gateway
              </Link>

              <Link
                to="/courses"
                className="px-5 py-2.5 rounded-xl font-extrabold text-xs transition flex items-center gap-1.5 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-white shadow-md shadow-amber-900/30"
              >
                <span>🛒</span> Buy a Course
              </Link>

              {placementDashboardEnabled && (
                <Link
                  to="/placements"
                  className="px-5 py-2.5 rounded-xl font-extrabold text-xs transition flex items-center gap-1.5 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-md shadow-emerald-900/30"
                >
                  <span>🚀</span> Placement Portal
                </Link>
              )}
            </div>

            <div className="absolute top-0 right-0 -mr-16 -mt-16 w-64 h-64 bg-blue-500/10 rounded-full blur-3xl pointer-events-none" />
          </section>
        )}

        {/* TOP PRIORITY: YOUTUBE-STYLE 16:9 LIVE CLASSROOM THEATER (Rendered at top replacing Welcome hero when live class is active) */}
        {liveSessionsWithUrl.length > 0 && (
          <section className="mb-10 space-y-6" aria-label="Active Live Classrooms">
            <div className="space-y-6">
              {liveSessionsWithUrl.map((s) => {
                const isZoom = s.platform?.toLowerCase() === 'zoom'
                const isGoogleMeet = s.meeting_url?.includes('meet.google.com')
                const platformLabel = isGoogleMeet ? '🌐 Google Meet' : isZoom ? '📹 Zoom Live Stream' : '👥 Teams'
                const batchCode = s.batch_code || s.batch_number || s.batch?.code || s.title

                return (
                  <div
                    key={`top-live-${s.id}`}
                    className="bg-slate-900 rounded-3xl border-2 border-red-500/40 shadow-2xl overflow-hidden"
                  >
                    {/* 16:9 THEATER VIDEO-PLAYER SCREEN */}
                    <div className="relative w-full aspect-video min-h-[300px] sm:min-h-[380px] md:min-h-[460px] max-h-[580px] bg-radial from-slate-900 via-slate-950 to-black flex flex-col justify-between p-4 sm:p-6 md:p-8 overflow-hidden select-none">
                      {/* Ambient Broadcast Glow & Stage Grid */}
                      <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_var(--tw-gradient-stops))] from-red-600/15 via-transparent to-black pointer-events-none" />
                      <div className="absolute inset-0 opacity-10 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:24px_24px] pointer-events-none" />

                      {/* Top Player Overlay */}
                      <div className="flex items-center justify-between gap-3 relative z-10">
                        <div className="flex items-center gap-2 sm:gap-3">
                          <span className="flex items-center gap-2 bg-red-600 text-white font-black text-[11px] sm:text-xs uppercase tracking-wider px-3.5 py-1 rounded-full shadow-lg shadow-red-600/40 animate-pulse">
                            <span className="w-2 h-2 rounded-full bg-white animate-ping inline-block" />
                            🔴 LIVE
                          </span>
                          <span className="hidden sm:inline-flex items-center gap-1 bg-white/10 text-white font-bold text-[10px] sm:text-[11px] uppercase tracking-wider px-2.5 py-1 rounded-full border border-white/15 backdrop-blur-md">
                            <span className="w-1.5 h-1.5 rounded-full bg-red-500" />
                            BROADCASTING
                          </span>
                        </div>

                        <div className="flex items-center gap-2">
                          <span className="font-mono text-[11px] sm:text-xs font-black text-cyan-300 bg-cyan-950/80 px-3 py-1 rounded-full border border-cyan-500/40 shadow-sm backdrop-blur-md">
                            {batchCode}
                          </span>
                          <span className="text-[10px] sm:text-xs font-bold text-white bg-slate-800/80 px-3 py-1 rounded-full border border-slate-700 backdrop-blur-md">
                            {platformLabel}
                          </span>
                        </div>
                      </div>

                      {/* Center Stage: High-Impact Video Platform Call-To-Action */}
                      <div className="flex flex-col items-center justify-center text-center space-y-4 my-auto relative z-10 py-6">
                        {/* Radar Wave Live Icon */}
                        <div className="relative flex items-center justify-center">
                          <div className="absolute w-24 h-24 sm:w-28 sm:h-28 rounded-full bg-red-500/20 animate-ping opacity-60 pointer-events-none" />
                          <div className="absolute w-16 h-16 sm:w-20 sm:h-20 rounded-full bg-red-500/30 animate-pulse pointer-events-none" />
                          <div className="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-gradient-to-tr from-red-600 to-rose-500 text-white flex items-center justify-center shadow-xl shadow-red-600/50 border border-red-400/40">
                            <span className="text-2xl sm:text-3xl ml-0.5">▶</span>
                          </div>
                        </div>

                        <div className="space-y-1 max-w-lg">
                          <h2 className="text-xl sm:text-3xl font-black text-white tracking-tight drop-shadow-md">
                            Interactive Live Classroom in Session
                          </h2>
                          <p className="text-xs sm:text-sm text-slate-300">
                            Authorized batch lecture hosted by <span className="font-bold text-white">{s.tutor_name || s.tutor?.name || 'Lead Faculty'}</span>
                          </p>
                        </div>

                        {/* Grand Primary Join Button */}
                        <button
                          type="button"
                          onClick={() => handleJoinClass(s.id)}
                          className="mt-2 px-8 sm:px-12 py-3.5 sm:py-4 rounded-2xl bg-gradient-to-r from-red-600 via-rose-600 to-red-600 hover:from-red-500 hover:via-rose-500 hover:to-red-500 text-white font-black text-sm sm:text-base tracking-wider uppercase shadow-2xl shadow-red-600/60 hover:shadow-red-600/80 active:scale-95 transition-all flex items-center gap-3 border border-red-400/40"
                        >
                          <span className="text-lg">🚀</span> JOIN LIVE CLASS
                        </button>
                      </div>

                      {/* Bottom Player Overlay Bar (YouTube / Video Player Timeline Controls) */}
                      <div className="space-y-2 relative z-10">
                        {/* Red Live Timeline */}
                        <div className="w-full h-1 sm:h-1.5 bg-slate-800/80 rounded-full overflow-hidden backdrop-blur-xs">
                          <div className="w-full h-full bg-gradient-to-r from-red-600 via-rose-500 to-red-600 animate-pulse" />
                        </div>

                        <div className="flex items-center justify-between text-[11px] sm:text-xs text-slate-400 pt-1">
                          <div className="flex items-center gap-2 text-slate-300 font-semibold">
                            <span className="w-2 h-2 rounded-full bg-red-500 inline-block" />
                            <span>LIVE STREAM</span>
                            <span className="text-slate-600">•</span>
                            <span className="font-mono text-cyan-300">⏰ {s.start_time} – {s.end_time} IST</span>
                          </div>

                          <div className="flex items-center gap-3">
                            <span className="hidden sm:inline-block px-2 py-0.5 rounded bg-slate-800 text-[10px] font-bold uppercase text-slate-400 border border-slate-700">
                              HD 1080p
                            </span>
                            <span className="text-slate-300 font-bold">
                              🔒 Active Student Feed
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>

                    {/* PLAYER METADATA BAR (Stream info, Batch Number, Course & Instructor) */}
                    <div className="p-6 sm:p-7 bg-slate-900/95 border-t border-slate-800 text-white flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                      <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                          <h1 className="text-2xl sm:text-3xl font-black font-mono tracking-tight text-white">
                            {batchCode}
                          </h1>
                          <span className="px-3 py-1 rounded-full text-xs font-extrabold uppercase bg-blue-500/20 text-blue-300 border border-blue-400/30">
                            {s.course_title || s.course?.title}
                          </span>
                        </div>

                        {s.description && (
                          <p className="text-xs sm:text-sm text-slate-300 max-w-2xl leading-relaxed">
                            {s.description}
                          </p>
                        )}

                        <div className="pt-2 flex flex-wrap items-center gap-4 text-xs text-slate-400">
                          <div className="flex items-center gap-2">
                            <div className="w-7 h-7 rounded-full bg-blue-600 text-white font-bold flex items-center justify-center text-xs">
                              {s.tutor_name?.charAt(0) || s.tutor?.name?.charAt(0) || 'F'}
                            </div>
                            <span>Faculty: <strong className="text-white">{s.tutor_name || s.tutor?.name || 'Lead Faculty'}</strong></span>
                          </div>
                          <span className="text-slate-600">•</span>
                          <span>📅 Scheduled: <strong className="text-slate-200">{s.scheduled_date}</strong></span>
                        </div>
                      </div>

                      <div className="flex items-center gap-3 self-start lg:self-center">
                        <Link
                          to={`/student/class-sessions/${s.id}`}
                          className="px-5 py-3 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition border border-slate-700"
                        >
                          View Details & Notes
                        </Link>
                        <button
                          type="button"
                          onClick={() => handleJoinClass(s.id)}
                          className="px-7 py-3 rounded-xl text-xs font-black text-white bg-gradient-to-r from-red-600 to-rose-600 hover:from-red-500 hover:to-rose-500 active:scale-95 transition shadow-lg shadow-red-600/40 flex items-center gap-2"
                        >
                          <span>🚀</span> JOIN LIVE CLASS
                        </button>
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          </section>
        )}

        {/* Status & Notification Strip */}
        {notifications.length > 0 && (
          <section className="mb-8 space-y-2" aria-label="System Notifications">
            {notifications.map((n) => (
              <div
                key={n.id}
                className={`p-3.5 px-4 rounded-2xl text-xs font-semibold flex items-center justify-between gap-3 border shadow-xs transition ${
                  n.type === 'live'
                    ? 'bg-blue-50 border-blue-200 text-blue-900'
                    : n.type === 'material'
                    ? 'bg-indigo-50 border-indigo-200 text-indigo-900'
                    : n.type === 'quiz'
                    ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                    : 'bg-rose-50 border-rose-200 text-rose-900'
                }`}
              >
                <div className="flex items-center gap-2.5">
                  <span className="text-base">
                    {n.type === 'live' ? '🔴' : n.type === 'material' ? '📄' : n.type === 'quiz' ? '📝' : '⚠️'}
                  </span>
                  <span>{n.message}</span>
                </div>
                {n.link && (
                  <Link
                    to={n.link}
                    className="underline text-[11px] font-bold shrink-0 hover:text-blue-600"
                  >
                    View Details →
                  </Link>
                )}
              </div>
            ))}
          </section>
        )}

        {/* Tab 1: Live & Previous Classes Learning Hub (Default View) */}
        {mainTab === 'learning_hub' && (
          <div className="space-y-10">
            {/* Filter and Course Selection Bar */}
            <div className="bg-white p-4 rounded-3xl border border-slate-200/90 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              {/* Category Filter Tabs */}
              <div className="flex items-center gap-2 overflow-x-auto pb-1 sm:pb-0">
                <button
                  type="button"
                  onClick={() => setClassFilterTab('all')}
                  className={`px-4 py-2 rounded-xl text-xs font-bold transition ${
                    classFilterTab === 'all'
                      ? 'bg-blue-600 text-white shadow-xs'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  All Classes ({allSessions.length})
                </button>
                <button
                  type="button"
                  onClick={() => setClassFilterTab('today')}
                  className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
                    classFilterTab === 'today'
                      ? 'bg-blue-600 text-white shadow-xs'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  {todaySessions.length > 0 && <span className="w-2 h-2 rounded-full bg-red-500 animate-pulse" />}
                  Today ({todaySessions.length})
                </button>
                <button
                  type="button"
                  onClick={() => setClassFilterTab('upcoming')}
                  className={`px-4 py-2 rounded-xl text-xs font-bold transition ${
                    classFilterTab === 'upcoming'
                      ? 'bg-blue-600 text-white shadow-xs'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  Upcoming ({upcomingSessions.length})
                </button>
                <button
                  type="button"
                  onClick={() => setClassFilterTab('previous')}
                  className={`px-4 py-2 rounded-xl text-xs font-bold transition ${
                    classFilterTab === 'previous'
                      ? 'bg-blue-600 text-white shadow-xs'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  Previous ({previousSessions.length})
                </button>
              </div>

              {/* Course Filter Dropdown */}
              <div className="flex items-center gap-2 shrink-0">
                <label htmlFor="course-filter-select" className="text-xs font-bold text-slate-500 whitespace-nowrap">Filter Course:</label>
                <select
                  id="course-filter-select"
                  value={selectedCourseFilter}
                  onChange={(e) => setSelectedCourseFilter(e.target.value)}
                  className="px-3.5 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-semibold text-slate-700 focus:outline-hidden focus:border-blue-500"
                >
                  <option value="all">All Enrolled Courses</option>
                  {enrollments.map((e) => (
                    <option key={e.course_id} value={e.course_id}>
                      {e.course?.title || `Course #${e.course_id}`}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {/* 1. TODAY'S LEARNING (Progressively loaded) */}
            {(classFilterTab === 'all' || classFilterTab === 'today') && (
              <section className="space-y-4">
                <div className="flex items-center justify-between border-b border-slate-200 pb-2">
                  <div className="flex items-center gap-2">
                    <span className="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse" />
                    <h2 className="text-lg font-black tracking-tight text-slate-900">TODAY&apos;S LEARNING</h2>
                  </div>
                  <span className="text-xs font-bold text-slate-500">{todayFormattedDate}</span>
                </div>

                {loadingToday ? (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-5 animate-pulse">
                    <div className="bg-white rounded-3xl p-6 border-2 border-slate-200 space-y-4 shadow-xs">
                      <div className="flex items-center justify-between">
                        <div className="h-4 w-28 bg-slate-200 rounded-full" />
                        <div className="h-4 w-16 bg-slate-200 rounded-full" />
                      </div>
                      <div className="h-6 w-3/4 bg-slate-200 rounded-md" />
                      <div className="h-4 w-full bg-slate-100 rounded-md" />
                      <div className="h-10 w-full bg-slate-100 rounded-xl" />
                    </div>
                    <div className="bg-white rounded-3xl p-6 border-2 border-slate-200 space-y-4 shadow-xs hidden md:block">
                      <div className="flex items-center justify-between">
                        <div className="h-4 w-28 bg-slate-200 rounded-full" />
                        <div className="h-4 w-16 bg-slate-200 rounded-full" />
                      </div>
                      <div className="h-6 w-3/4 bg-slate-200 rounded-md" />
                      <div className="h-4 w-full bg-slate-100 rounded-md" />
                      <div className="h-10 w-full bg-slate-100 rounded-xl" />
                    </div>
                  </div>
                ) : errorToday ? (
                  <div className="p-6 rounded-3xl bg-red-50 border border-red-200 text-center space-y-3">
                    <p className="text-xs font-bold text-red-700">{errorToday}</p>
                    <button
                      type="button"
                      onClick={() => fetchClassSessions(selectedCourseFilter)}
                      className="px-4 py-1.5 rounded-xl bg-red-600 text-white text-xs font-bold hover:bg-red-700 transition"
                    >
                      ↻ Retry Today&apos;s Schedule
                    </button>
                  </div>
                ) : todaySessions.length === 0 ? (
                  <div className="p-8 rounded-3xl bg-white border border-slate-200/80 text-center space-y-2 shadow-xs">
                    <span className="text-3xl">☕</span>
                    <h3 className="text-sm font-bold text-slate-800">No live classes scheduled for today.</h3>
                    <p className="text-xs text-slate-500 max-w-md mx-auto">
                      Use today to review your previous class recordings, download lesson slide decks, or take pending checkpoint quizzes below.
                    </p>
                  </div>
                ) : (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                    {todaySessions.map((s) => {
                      const isZoom = s.platform?.toLowerCase() === 'zoom'
                      const isCancelled = s.status === 'cancelled'
                      const isCompleted = s.status === 'completed'

                      return (
                        <div
                          key={s.id}
                          className="bg-white rounded-3xl p-6 border-2 border-blue-500/30 shadow-md hover:shadow-lg transition flex flex-col justify-between space-y-5"
                        >
                          <div className="space-y-3">
                            <div className="flex items-center justify-between gap-2">
                              <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200 truncate max-w-[200px]">
                                {s.course_title || s.course?.title}
                              </span>
                              <div className="flex items-center gap-2">
                                <span
                                  className={`px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider border ${
                                    s.status === 'live'
                                      ? 'bg-emerald-50 text-emerald-700 border-emerald-300 animate-pulse'
                                      : isCompleted
                                      ? 'bg-slate-100 text-slate-600 border-slate-200'
                                      : isCancelled
                                      ? 'bg-rose-50 text-rose-700 border-rose-200'
                                      : 'bg-blue-50 text-blue-700 border-blue-200'
                                  }`}
                                >
                                  {s.status}
                                </span>
                                <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 border border-slate-200">
                                  {isZoom ? '📹 Zoom' : '👥 Teams'}
                                </span>
                              </div>
                            </div>

                            <div>
                              <h3 className="text-base font-black text-slate-900 font-mono leading-snug">{s.batch_code || s.batch_number || s.batch?.code || s.title}</h3>
                              {s.description && (
                                <p className="text-xs text-slate-500 mt-1 line-clamp-2">{s.description}</p>
                              )}
                            </div>

                            <div className="pt-2 border-t border-slate-100 flex flex-wrap items-center gap-4 text-xs text-slate-600">
                              <p>Trainer: <strong className="text-slate-900">{s.tutor_name || s.tutor?.name || 'Faculty'}</strong></p>
                              <p className="font-mono text-blue-600 font-bold">
                                ⏰ {s.start_time} – {s.end_time} IST
                              </p>
                            </div>
                          </div>

                          <div className="pt-3 border-t border-slate-100 flex items-center justify-between gap-3">
                            <Link
                              to={`/student/class-sessions/${s.id}`}
                              className="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 transition"
                            >
                              View Details
                            </Link>

                            {!isCancelled && !isCompleted && s.meeting_url && s.meeting_url.trim().length > 0 && (
                              <button
                                type="button"
                                onClick={() => handleJoinClass(s.id)}
                                className="px-6 py-2.5 rounded-xl text-xs font-extrabold text-white bg-blue-600 hover:bg-blue-500 active:bg-blue-700 transition flex items-center gap-1.5 shadow-sm shadow-blue-500/25"
                              >
                                <span>🚀</span> JOIN CLASS
                              </button>
                            )}
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )}
              </section>
            )}

            {/* 2. UPCOMING CLASSES (Progressively loaded) */}
            {(classFilterTab === 'all' || classFilterTab === 'upcoming') && (
              <section className="space-y-4">
                <div className="flex items-center justify-between border-b border-slate-200 pb-2">
                  <div className="flex items-center gap-2">
                    <span className="text-lg">📅</span>
                    <h2 className="text-lg font-black tracking-tight text-slate-900">UPCOMING CLASSES</h2>
                  </div>
                  <span className="text-xs font-bold text-slate-500">{upcomingSessions.length} Scheduled</span>
                </div>

                {loadingUpcoming ? (
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 animate-pulse">
                    {[1, 2, 3].map((i) => (
                      <div key={i} className="bg-white rounded-3xl p-5 border border-slate-200 space-y-3 shadow-xs">
                        <div className="h-4 w-24 bg-slate-200 rounded-full" />
                        <div className="h-5 w-3/4 bg-slate-200 rounded-md" />
                        <div className="h-8 bg-slate-100 rounded-xl" />
                        <div className="h-8 bg-slate-200 rounded-xl" />
                      </div>
                    ))}
                  </div>
                ) : errorUpcoming ? (
                  <div className="p-6 rounded-3xl bg-red-50 border border-red-200 text-center space-y-3">
                    <p className="text-xs font-bold text-red-700">{errorUpcoming}</p>
                    <button
                      type="button"
                      onClick={() => fetchClassSessions(selectedCourseFilter)}
                      className="px-4 py-1.5 rounded-xl bg-red-600 text-white text-xs font-bold hover:bg-red-700 transition"
                    >
                      ↻ Retry Upcoming
                    </button>
                  </div>
                ) : upcomingSessions.length === 0 ? (
                  <div className="p-8 rounded-3xl bg-white border border-slate-200/80 text-center space-y-1 shadow-xs">
                    <p className="text-xs font-bold text-slate-700">No upcoming live classes scheduled at this moment.</p>
                    <p className="text-[11px] text-slate-400">Your faculty and administrators will schedule new training sessions soon.</p>
                  </div>
                ) : (
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    {upcomingSessions.map((s) => {
                      const isZoom = s.platform?.toLowerCase() === 'zoom'
                      return (
                        <div
                          key={s.id}
                          className="bg-white rounded-3xl p-5 border border-slate-200/90 shadow-xs hover:shadow-md transition flex flex-col justify-between space-y-4"
                        >
                          <div className="space-y-2">
                            <div className="flex items-center justify-between gap-2">
                              <span className="text-[10px] font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded border border-blue-200 truncate max-w-[150px]">
                                {s.course_title || s.course?.title}
                              </span>
                              <span className="text-[10px] font-bold text-purple-700 bg-purple-50 px-2 py-0.5 rounded">
                                {isZoom ? 'Zoom' : 'Teams'}
                              </span>
                            </div>

                            <h3 className="text-sm font-black text-slate-900 font-mono line-clamp-1">{s.batch_code || s.batch_number || s.batch?.code || s.title}</h3>
                            <p className="text-[11px] text-slate-500">
                              Tutor: <strong className="text-slate-800">{s.tutor_name || s.tutor?.name || 'Faculty'}</strong>
                            </p>

                            <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-xs font-medium text-slate-700">
                              📅 {s.scheduled_date} • ⏰ {s.start_time} IST
                            </div>
                          </div>

                          <div className="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
                            <Link
                              to={`/student/class-sessions/${s.id}`}
                              className="w-full py-2 rounded-xl text-center font-bold text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                            >
                              VIEW DETAILS
                            </Link>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )}
              </section>
            )}

            {/* 3. PREVIOUS CLASSES (Progressively loaded) */}
            {(classFilterTab === 'all' || classFilterTab === 'previous') && (
              <section className="space-y-4">
                <div className="flex items-center justify-between border-b border-slate-200 pb-2">
                  <div className="flex items-center gap-2">
                    <span className="text-lg">📚</span>
                    <h2 className="text-lg font-black tracking-tight text-slate-900">PREVIOUS CLASSES</h2>
                  </div>
                  <span className="text-xs font-bold text-slate-500">{previousSessions.length} Completed</span>
                </div>

                {loadingPrevious ? (
                  <div className="space-y-4 animate-pulse">
                    {[1, 2].map((i) => (
                      <div key={i} className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs space-y-4">
                        <div className="flex items-center gap-2">
                          <div className="h-4 w-20 bg-slate-200 rounded-full" />
                          <div className="h-4 w-32 bg-slate-200 rounded-full" />
                        </div>
                        <div className="h-6 w-1/2 bg-slate-200 rounded-md" />
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                          <div className="h-14 bg-slate-100 rounded-xl" />
                          <div className="h-14 bg-slate-100 rounded-xl" />
                          <div className="h-14 bg-slate-100 rounded-xl" />
                          <div className="h-14 bg-slate-100 rounded-xl" />
                        </div>
                      </div>
                    ))}
                  </div>
                ) : errorPrevious ? (
                  <div className="p-6 rounded-3xl bg-red-50 border border-red-200 text-center space-y-3">
                    <p className="text-xs font-bold text-red-700">{errorPrevious}</p>
                    <button
                      type="button"
                      onClick={() => fetchClassSessions(selectedCourseFilter)}
                      className="px-4 py-1.5 rounded-xl bg-red-600 text-white text-xs font-bold hover:bg-red-700 transition"
                    >
                      ↻ Retry Previous Classes
                    </button>
                  </div>
                ) : previousSessions.length === 0 ? (
                  <div className="p-8 rounded-3xl bg-white border border-slate-200/80 text-center space-y-1 shadow-xs">
                    <p className="text-xs font-bold text-slate-700">No previous classes on record.</p>
                    <p className="text-[11px] text-slate-400">Completed training sessions with shared lecture materials and quizzes will appear here.</p>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {previousSessions.map((item) => (
                      <div
                        key={item.id}
                        className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs hover:shadow-md transition flex flex-col md:flex-row md:items-center justify-between gap-6"
                      >
                        <div className="space-y-3 flex-grow">
                          {/* Top Row: Date, Course Title, Status */}
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="text-xs font-extrabold text-slate-700 bg-slate-100 px-2.5 py-0.5 rounded-full">
                              📅 {item.scheduled_date}
                            </span>
                            <span className="text-xs font-extrabold text-blue-700 bg-blue-50 px-2.5 py-0.5 rounded-full border border-blue-200">
                              {item.course_title || item.course?.title}
                            </span>
                            <span className="text-[10px] font-black uppercase text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                              Status: Completed
                            </span>
                          </div>

                          {/* Class Title / Batch Code */}
                          <h3 className="text-base font-black text-slate-900 font-mono">{item.batch_code || item.batch_number || item.batch?.code || item.title}</h3>

                          {/* Metadata Row: Tutor, Time, Attendance, Materials, Quiz */}
                          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-1 text-xs">
                            <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-100">
                              <p className="text-[10px] font-bold text-slate-400 uppercase">Trainer</p>
                              <p className="font-bold text-slate-800 mt-0.5 truncate">{item.tutor_name || item.tutor?.name || 'Faculty'}</p>
                              <p className="text-[10px] text-slate-500 font-mono mt-0.5">{item.start_time} – {item.end_time} IST</p>
                            </div>

                            <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-100">
                              <p className="text-[10px] font-bold text-slate-400 uppercase">Materials</p>
                              <p className="font-black text-slate-800 mt-0.5">
                                {(item.materials_count || 0) > 0 ? `${item.materials_count} shared` : 'None shared'}
                              </p>
                              <p className="text-[10px] text-blue-600 mt-0.5">
                                {(item.materials_count || 0) > 0 ? 'PDF / Slide decks' : 'No attachments'}
                              </p>
                            </div>

                            <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-100">
                              <p className="text-[10px] font-bold text-slate-400 uppercase">Quiz</p>
                              <p
                                className={`font-black mt-0.5 ${
                                  item.quiz_status === 'Completed'
                                    ? 'text-emerald-600'
                                    : item.quiz_status === 'Available'
                                    ? 'text-blue-600'
                                    : 'text-slate-400'
                                }`}
                              >
                                {item.quiz_status || 'Not assigned'}
                              </p>
                              <p className="text-[10px] text-slate-500 mt-0.5">
                                {item.quiz?.questions_count ? `${item.quiz.questions_count} Questions` : 'Session checkpoint'}
                              </p>
                            </div>

                            <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-100">
                              <p className="text-[10px] font-bold text-slate-400 uppercase">Attendance</p>
                              <p
                                className={`font-black mt-0.5 ${
                                  item.attendance_status === 'Present'
                                    ? 'text-emerald-600'
                                    : item.attendance_status === 'Absent'
                                    ? 'text-rose-600'
                                    : 'text-slate-500'
                                }`}
                              >
                                {item.attendance_status || 'Not Recorded'}
                              </p>
                              <p className="text-[10px] text-slate-500 mt-0.5">
                                {item.attendance_status === 'Present' ? 'Verified session' : 'Live participation'}
                              </p>
                            </div>
                          </div>
                        </div>

                        {/* Action: View Class */}
                        <div className="flex md:flex-col items-center justify-end gap-2 shrink-0">
                          <Link
                            to={`/student/class-sessions/${item.id}`}
                            className="w-full sm:w-auto px-6 py-3 rounded-2xl bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-extrabold text-xs transition text-center shadow-md shadow-blue-500/20"
                          >
                            VIEW CLASS
                          </Link>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </section>
            )}
          </div>
        )}

        {/* Tab 2: My Enrolled Courses (Progressively loaded) */}
        {mainTab === 'courses' && (
          <div className="space-y-6">
            <h2 className="text-lg font-black text-slate-900">My Enrolled Course Curriculums</h2>
            {loadingCourses ? (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 animate-pulse">
                {[1, 2, 3].map((i) => (
                  <div key={i} className="rounded-3xl bg-white border border-slate-200 shadow-sm overflow-hidden space-y-4 p-4">
                    <div className="w-full h-36 bg-slate-200 rounded-2xl" />
                    <div className="h-5 w-3/4 bg-slate-200 rounded-md" />
                    <div className="h-4 w-full bg-slate-100 rounded-md" />
                    <div className="h-10 bg-slate-200 rounded-xl" />
                  </div>
                ))}
              </div>
            ) : errorCourses ? (
              <div className="p-6 rounded-3xl bg-red-50 border border-red-200 text-center space-y-3">
                <p className="text-xs font-bold text-red-700">{errorCourses}</p>
                <button
                  type="button"
                  onClick={() => fetchEnrolledCourses(true)}
                  className="px-4 py-1.5 rounded-xl bg-red-600 text-white text-xs font-bold hover:bg-red-700 transition"
                >
                  ↻ Retry Enrolled Courses
                </button>
              </div>
            ) : enrollments.length === 0 ? (
              <div className="text-center py-14 bg-white rounded-3xl border border-slate-200 space-y-3">
                <span className="text-3xl">🎓</span>
                <h3 className="text-sm font-bold text-slate-900">You are not enrolled in any courses yet</h3>
                <p className="text-xs text-slate-500 max-w-sm mx-auto">
                  Browse our catalog and start your learning journey.
                </p>
                <Link
                  to="/courses"
                  className="px-4 py-2 rounded-xl bg-blue-600 text-white text-xs font-bold hover:bg-blue-500 transition inline-block"
                >
                  Explore Courses
                </Link>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {enrollments.map((item) => {
                  const isComplete = Number(item.progress_percentage) >= 100 || item.status === 'completed'
                  const percent = isComplete ? 100 : Math.round(Number(item.progress_percentage))
                  return (
                    <div
                      key={item.id}
                      className="rounded-3xl bg-white border border-slate-200/90 shadow-sm hover:shadow-md transition overflow-hidden flex flex-col justify-between group"
                    >
                      <div>
                        <div className="w-full h-36 bg-slate-900 relative overflow-hidden">
                          <img
                            src={item.course?.thumbnail || 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=800&q=80'}
                            alt={item.course?.title}
                            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300 opacity-80"
                          />
                          <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/40 to-transparent" />
                          <div className="absolute top-3 left-3 right-3 flex items-center justify-between gap-2">
                            <span className="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-slate-800/90 text-slate-200 border border-slate-700">
                              {item.course?.category || 'Tech Program'}
                            </span>
                            <span className={`text-xs font-black px-2.5 py-1 rounded-full border ${isComplete ? 'text-emerald-300 bg-emerald-950/80 border-emerald-500/30' : 'text-blue-300 bg-slate-900/80 border-blue-500/30'}`}>
                              {percent}% Complete
                            </span>
                          </div>
                        </div>

                        <div className="p-5 pb-2">
                          <h3 className="text-base font-bold text-slate-900 line-clamp-1 mb-1">
                            {item.course?.title}
                          </h3>
                          <p className="text-xs text-slate-500 line-clamp-2 mb-4 leading-relaxed">
                            {item.course?.description}
                          </p>
                        </div>
                      </div>

                      <div className="p-5 pt-0">
                        <Link
                          to={`/student/courses/${item.course_id}/lessons`}
                          className={`w-full py-2.5 px-4 rounded-xl text-xs font-bold text-white text-center transition flex items-center justify-center gap-1.5 shadow-sm ${
                            isComplete ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-blue-600 hover:bg-blue-500'
                          }`}
                        >
                          {isComplete ? '🏆 View Certificate' : '🚀 Continue Learning'}
                        </Link>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )}
      </main>

      <Footer />
    </div>
  )
}
