import { useEffect, useState, useCallback, useRef, useMemo } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'
import API from '../../services/api'
import type { Course } from '../../types/course'
import type { ClassSession } from '../../types/classSession'

interface TutorStats {
  total_courses: number
  total_students: number
  pending_submissions: number
  average_rating: number
}

interface StudentEnrollmentItem {
  id: number
  user_id: number
  course_id: number
  enrolled_at: string
  progress_percentage: number | string
  user?: {
    id: number
    name: string
    email: string
  }
  course?: {
    id: number
    title: string
  }
}

// In-memory cache with 60-second TTL
interface CacheEntry<T> {
  data: T
  timestamp: number
}

const tutorDashboardCache: {
  stats?: CacheEntry<TutorStats>
  materialsCount?: CacheEntry<number>
  quizzesCount?: CacheEntry<number>
  courses?: CacheEntry<Course[]>
  students?: CacheEntry<StudentEnrollmentItem[]>
  classes?: CacheEntry<{
    today: ClassSession[]
    upcoming: ClassSession[]
    previous: ClassSession[]
  }>
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

  // Strict IST evaluation: start_time <= current_time < end_time
  return (timeIst >= start && timeIst < end) || session.status === 'live'
}

export default function TutorDashboard() {
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

  // State
  const [stats, setStats] = useState<TutorStats>(() => {
    if (tutorDashboardCache.stats && Date.now() - tutorDashboardCache.stats.timestamp < CACHE_TTL_MS) {
      return tutorDashboardCache.stats.data
    }
    return {
      total_courses: 0,
      total_students: 0,
      pending_submissions: 0,
      average_rating: 5.0,
    }
  })
  const [materialsCount, setMaterialsCount] = useState<number>(() => {
    if (tutorDashboardCache.materialsCount && Date.now() - tutorDashboardCache.materialsCount.timestamp < CACHE_TTL_MS) {
      return tutorDashboardCache.materialsCount.data
    }
    return 0
  })
  const [quizzesCount, setQuizzesCount] = useState<number>(() => {
    if (tutorDashboardCache.quizzesCount && Date.now() - tutorDashboardCache.quizzesCount.timestamp < CACHE_TTL_MS) {
      return tutorDashboardCache.quizzesCount.data
    }
    return 0
  })
  const [courses, setCourses] = useState<Course[]>(() => {
    if (tutorDashboardCache.courses && Date.now() - tutorDashboardCache.courses.timestamp < CACHE_TTL_MS) {
      return tutorDashboardCache.courses.data
    }
    return []
  })
  const [todayClasses, setTodayClasses] = useState<ClassSession[]>([])
  const [upcomingClasses, setUpcomingClasses] = useState<ClassSession[]>([])
  const [previousClasses, setPreviousClasses] = useState<ClassSession[]>([])
  const [recentStudents, setRecentStudents] = useState<StudentEnrollmentItem[]>(() => {
    if (tutorDashboardCache.students && Date.now() - tutorDashboardCache.students.timestamp < CACHE_TTL_MS) {
      return tutorDashboardCache.students.data.slice(0, 5)
    }
    return []
  })

  // Section Loading States
  const [loadingStats, setLoadingStats] = useState<boolean>(!tutorDashboardCache.stats)
  const [loadingClasses, setLoadingClasses] = useState<boolean>(true)
  const [loadingCourses, setLoadingCourses] = useState<boolean>(!tutorDashboardCache.courses)
  const [loadingStudents, setLoadingStudents] = useState<boolean>(!tutorDashboardCache.students)

  // Section Error States
  const [errorStats, setErrorStats] = useState<string | null>(null)
  const [errorClasses, setErrorClasses] = useState<string | null>(null)
  const [errorCourses, setErrorCourses] = useState<string | null>(null)
  const [errorStudents, setErrorStudents] = useState<string | null>(null)

  const isMountedRef = useRef(true)
  useEffect(() => {
    isMountedRef.current = true
    return () => {
      isMountedRef.current = false
    }
  }, [])

  // 1. Fetch Stats & Aux Counts Concurrently
  const fetchStats = useCallback(async (bypassCache = false) => {
    if (!bypassCache && tutorDashboardCache.stats && Date.now() - tutorDashboardCache.stats.timestamp < CACHE_TTL_MS) {
      setStats(tutorDashboardCache.stats.data)
      if (tutorDashboardCache.materialsCount) setMaterialsCount(tutorDashboardCache.materialsCount.data)
      if (tutorDashboardCache.quizzesCount) setQuizzesCount(tutorDashboardCache.quizzesCount.data)
      setLoadingStats(false)
      return
    }

    setLoadingStats(true)
    setErrorStats(null)

    const [statsRes, matRes, quizRes] = await Promise.allSettled([
      API.get<TutorStats>('/tutor/stats'),
      API.get<unknown[]>('/tutor/materials'),
      API.get<unknown[]>('/tutor/quizzes'),
    ])

    if (!isMountedRef.current) return

    if (statsRes.status === 'fulfilled') {
      const statsData = statsRes.value.data
      setStats(statsData)
      tutorDashboardCache.stats = { data: statsData, timestamp: Date.now() }
    } else {
      setErrorStats('Unable to load instructor metrics.')
    }

    if (matRes.status === 'fulfilled') {
      const count = Array.isArray(matRes.value.data) ? matRes.value.data.length : 0
      setMaterialsCount(count)
      tutorDashboardCache.materialsCount = { data: count, timestamp: Date.now() }
    }

    if (quizRes.status === 'fulfilled') {
      const count = Array.isArray(quizRes.value.data) ? quizRes.value.data.length : 0
      setQuizzesCount(count)
      tutorDashboardCache.quizzesCount = { data: count, timestamp: Date.now() }
    }

    setLoadingStats(false)
  }, [])

  // 2. Fetch Assigned Live Classes Concurrently
  const fetchClasses = useCallback(async (bypassCache = false) => {
    if (!bypassCache && tutorDashboardCache.classes && Date.now() - tutorDashboardCache.classes.timestamp < CACHE_TTL_MS) {
      setTodayClasses(tutorDashboardCache.classes.data.today)
      setUpcomingClasses(tutorDashboardCache.classes.data.upcoming)
      setPreviousClasses(tutorDashboardCache.classes.data.previous)
      setLoadingClasses(false)
      return
    }

    setLoadingClasses(true)
    setErrorClasses(null)

    const [todayRes, upcomingRes, prevRes] = await Promise.allSettled([
      API.get<ClassSession[]>('/tutor/class-sessions/today'),
      API.get<ClassSession[]>('/tutor/class-sessions/upcoming'),
      API.get<ClassSession[]>('/tutor/class-sessions/previous'),
    ])

    if (!isMountedRef.current) return

    let resolvedToday: ClassSession[] = []
    let resolvedUpcoming: ClassSession[] = []
    let resolvedPrevious: ClassSession[] = []

    if (todayRes.status === 'fulfilled') {
      resolvedToday = Array.isArray(todayRes.value.data) ? todayRes.value.data : []
      setTodayClasses(resolvedToday)
    }

    if (upcomingRes.status === 'fulfilled') {
      resolvedUpcoming = Array.isArray(upcomingRes.value.data) ? upcomingRes.value.data : []
      setUpcomingClasses(resolvedUpcoming)
    }

    if (prevRes.status === 'fulfilled') {
      resolvedPrevious = Array.isArray(prevRes.value.data) ? prevRes.value.data : []
      setPreviousClasses(resolvedPrevious)
    }

    if (todayRes.status === 'rejected' && upcomingRes.status === 'rejected' && prevRes.status === 'rejected') {
      setErrorClasses('Unable to load class session schedules.')
    }

    tutorDashboardCache.classes = {
      data: {
        today: resolvedToday,
        upcoming: resolvedUpcoming,
        previous: resolvedPrevious,
      },
      timestamp: Date.now(),
    }

    setLoadingClasses(false)
  }, [])

  // 3. Fetch Assigned Courses
  const fetchCourses = useCallback(async (bypassCache = false) => {
    if (!bypassCache && tutorDashboardCache.courses && Date.now() - tutorDashboardCache.courses.timestamp < CACHE_TTL_MS) {
      setCourses(tutorDashboardCache.courses.data)
      setLoadingCourses(false)
      return
    }

    setLoadingCourses(true)
    setErrorCourses(null)

    try {
      const res = await API.get<Course[]>('/tutor/courses')
      const coursesData = Array.isArray(res.data) ? res.data : []
      if (isMountedRef.current) {
        setCourses(coursesData)
        tutorDashboardCache.courses = { data: coursesData, timestamp: Date.now() }
      }
    } catch {
      if (isMountedRef.current) {
        setErrorCourses('Unable to load assigned courses.')
      }
    } finally {
      if (isMountedRef.current) {
        setLoadingCourses(false)
      }
    }
  }, [])

  // 4. Fetch Enrolled Students
  const fetchStudents = useCallback(async (bypassCache = false) => {
    if (!bypassCache && tutorDashboardCache.students && Date.now() - tutorDashboardCache.students.timestamp < CACHE_TTL_MS) {
      setRecentStudents(tutorDashboardCache.students.data.slice(0, 5))
      setLoadingStudents(false)
      return
    }

    setLoadingStudents(true)
    setErrorStudents(null)

    try {
      const res = await API.get<StudentEnrollmentItem[]>('/tutor/students')
      const studentData = Array.isArray(res.data) ? res.data : []
      if (isMountedRef.current) {
        setRecentStudents(studentData.slice(0, 5))
        tutorDashboardCache.students = { data: studentData, timestamp: Date.now() }
      }
    } catch {
      if (isMountedRef.current) {
        setErrorStudents('Unable to load student roster.')
      }
    } finally {
      if (isMountedRef.current) {
        setLoadingStudents(false)
      }
    }
  }, [])

  // Launch all concurrent independent requests on mount
  useEffect(() => {
    fetchStats()
    fetchClasses()
    fetchCourses()
    fetchStudents()
  }, [fetchStats, fetchClasses, fetchCourses, fetchStudents, user?.id])

  const handleJoinClass = async (sessionId: number) => {
    const session =
      todayClasses.find((s) => s.id === sessionId) ||
      upcomingClasses.find((s) => s.id === sessionId) ||
      previousClasses.find((s) => s.id === sessionId)

    if (session?.platform === 'livekit' || session?.livekit_room_name) {
      navigate(`/tutor/classroom/${sessionId}`)
      return
    }

    try {
      const res = await API.post<{ meeting_url: string }>(`/tutor/class-sessions/${sessionId}/join`)
      if (res.data.meeting_url) {
        if (res.data.meeting_url.startsWith('/')) {
          navigate(res.data.meeting_url)
        } else {
          window.open(res.data.meeting_url, '_blank', 'noopener,noreferrer')
        }
      } else {
        navigate(`/tutor/classroom/${sessionId}`)
      }
    } catch {
      navigate(`/tutor/classroom/${sessionId}`)
    }
  }

  // Find assigned active live sessions that have an Admin-provided meeting URL and are currently live
  const liveSessionsWithUrl = useMemo<ClassSession[]>(() => {
    const candidates = [...todayClasses, ...upcomingClasses]
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
  }, [todayClasses, upcomingClasses, currentTime])

  return (
    <div className="space-y-8">
      {/* TOP PRIORITY: YOUTUBE-STYLE 16:9 LIVE CLASSROOM THEATER (Rendered FIRST and ONLY when active live sessions exist) */}
      {liveSessionsWithUrl.length > 0 && (
        <section className="space-y-6" aria-label="Active Live Classrooms">
          <div className="space-y-6">
            {liveSessionsWithUrl.map((session) => {
              const isZoom = session.platform?.toLowerCase() === 'zoom'
              const isGoogleMeet = session.meeting_url?.includes('meet.google.com')
              const platformLabel = isGoogleMeet ? '🌐 Google Meet' : isZoom ? '📹 Zoom Live Stream' : '👥 Teams'
              const batchCode = session.batch_code || session.batch_number || session.batch?.code || session.title

              return (
                <div
                  key={`tutor-live-${session.id}`}
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
                          FACULTY BROADCAST HOST
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
                          Your Assigned Live Class is Ready to Teach
                        </h2>
                        <p className="text-xs sm:text-sm text-slate-300">
                          Host scheduled lecture, share presentations, and interact with enrolled batch learners.
                        </p>
                      </div>

                      {/* Grand Primary Join Button */}
                      <button
                        type="button"
                        onClick={() => handleJoinClass(session.id)}
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
                          <span>FACULTY LIVE STREAM</span>
                          <span className="text-slate-600">•</span>
                          <span className="font-mono text-cyan-300">⏰ {session.start_time} – {session.end_time} IST</span>
                        </div>

                        <div className="flex items-center gap-3">
                          <span className="hidden sm:inline-block px-2 py-0.5 rounded bg-slate-800 text-[10px] font-bold uppercase text-slate-400 border border-slate-700">
                            HD 1080p
                          </span>
                          <span className="text-slate-300 font-bold">
                            🔒 Authorized Instructor Feed
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* PLAYER METADATA BAR (Stream info, Batch Number, Course & Faculty) */}
                  <div className="p-6 sm:p-7 bg-slate-900/95 border-t border-slate-800 text-white flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                    <div className="space-y-2">
                      <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                        <h1 className="text-2xl sm:text-3xl font-black font-mono tracking-tight text-white">
                          {batchCode}
                        </h1>
                        <span className="px-3 py-1 rounded-full text-xs font-extrabold uppercase bg-blue-500/20 text-blue-300 border border-blue-400/30">
                          {session.course?.title}
                        </span>
                      </div>

                      {session.description && (
                        <p className="text-xs sm:text-sm text-slate-300 max-w-2xl leading-relaxed">
                          {session.description}
                        </p>
                      )}

                      <div className="pt-2 flex flex-wrap items-center gap-4 text-xs text-slate-400">
                        <div className="flex items-center gap-2">
                          <div className="w-7 h-7 rounded-full bg-blue-600 text-white font-bold flex items-center justify-center text-xs">
                            {user?.name?.charAt(0) || 'P'}
                          </div>
                          <span>Instructor: <strong className="text-white">Professor {user?.name}</strong></span>
                        </div>
                        <span className="text-slate-600">•</span>
                        <span>📅 Scheduled: <strong className="text-slate-200">{session.scheduled_date}</strong></span>
                      </div>
                    </div>

                    <div className="flex items-center gap-3 self-start lg:self-center">
                      <Link
                        to="/tutor/materials"
                        className="px-5 py-3 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition border border-slate-700"
                      >
                        Upload Lecture Material
                      </Link>
                      <button
                        type="button"
                        onClick={() => handleJoinClass(session.id)}
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

      {/* Welcome Banner (Rendered ONLY when no active live session exists) */}
      {liveSessionsWithUrl.length === 0 && (
        <section className="bg-gradient-to-r from-blue-700 via-indigo-700 to-slate-900 rounded-3xl p-8 text-white shadow-xl flex flex-col md:flex-row items-start md:items-center justify-between gap-6 relative overflow-hidden">
          <div className="space-y-2 relative z-10">
            <span className="bg-blue-500/30 text-blue-200 border border-blue-400/30 text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full">
              Faculty Teaching Cockpit
            </span>
            <h1 className="text-2xl sm:text-3xl font-black tracking-tight">
              Welcome, Professor {user?.name}! 🎓
            </h1>
            <p className="text-xs sm:text-sm text-blue-100 max-w-xl leading-relaxed">
              Teach assigned curriculums, host live Zoom/Teams classrooms, share lecture materials, and evaluate student submissions.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3 relative z-10">
            <Link
              to="/tutor/materials"
              className="px-5 py-2.5 rounded-xl font-bold text-xs bg-white text-blue-700 hover:bg-blue-50 transition shadow-md flex items-center gap-1.5"
            >
              <span>📚</span> Materials ({materialsCount})
            </Link>
            <Link
              to="/tutor/quizzes"
              className="px-5 py-2.5 rounded-xl font-bold text-xs bg-blue-600/80 hover:bg-blue-600 border border-blue-400/30 text-white transition flex items-center gap-1.5"
            >
              <span>📝</span> Quizzes ({quizzesCount})
            </Link>
            <Link
              to="/tutor/submissions"
              className="px-5 py-2.5 rounded-xl font-bold text-xs bg-blue-600/80 hover:bg-blue-600 border border-blue-400/30 text-white transition flex items-center gap-1.5"
            >
              <span>✍️</span> Submissions ({stats.pending_submissions})
            </Link>
          </div>
        </section>
      )}

      {/* 4 Key Instructor Metrics (Progressively loaded) */}
      <section className="grid grid-cols-2 lg:grid-cols-4 gap-4" aria-label="Faculty Metrics">
        {loadingStats ? (
          [1, 2, 3, 4].map((i) => (
            <div key={i} className="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs animate-pulse space-y-3">
              <div className="h-3 w-20 bg-slate-200 rounded-md" />
              <div className="h-8 w-12 bg-slate-200 rounded-md" />
              <div className="h-2.5 w-24 bg-slate-100 rounded-md" />
            </div>
          ))
        ) : errorStats ? (
          <div className="col-span-full p-4 rounded-2xl bg-red-50 text-red-700 text-xs font-bold border border-red-200 flex items-center justify-between">
            <span>{errorStats}</span>
            <button type="button" onClick={() => fetchStats(true)} className="px-3 py-1 bg-red-600 text-white rounded-lg text-xs">
              ↻ Retry
            </button>
          </div>
        ) : (
          <>
            <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center justify-between">
              <div>
                <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Assigned Courses</p>
                <p className="text-3xl font-black text-slate-900 mt-1">{stats.total_courses}</p>
                <p className="text-[10px] text-slate-500 mt-0.5">Faculty teaching roster</p>
              </div>
              <div className="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl font-bold">
                📚
              </div>
            </div>

            <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center justify-between">
              <div>
                <p className="text-[11px] font-bold text-blue-600 uppercase tracking-wider">Active Students</p>
                <p className="text-3xl font-black text-blue-600 mt-1">{stats.total_students}</p>
                <p className="text-[10px] text-slate-500 mt-0.5">Learners enrolled</p>
              </div>
              <div className="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl font-bold">
                👥
              </div>
            </div>

            <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center justify-between">
              <div>
                <p className="text-[11px] font-bold text-amber-600 uppercase tracking-wider">Upcoming Sessions</p>
                <p className="text-3xl font-black text-amber-600 mt-1">{upcomingClasses.length}</p>
                <p className="text-[10px] text-slate-500 mt-0.5">Live classes scheduled</p>
              </div>
              <div className="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-xl font-bold">
                🎥
              </div>
            </div>

            <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center justify-between">
              <div>
                <p className="text-[11px] font-bold text-emerald-600 uppercase tracking-wider">Shared Materials</p>
                <p className="text-3xl font-black text-emerald-600 mt-1">{materialsCount}</p>
                <p className="text-[10px] text-slate-500 mt-0.5">Handouts & slides</p>
              </div>
              <div className="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold">
                📄
              </div>
            </div>
          </>
        )}
      </section>

      {/* SECTION: TODAY'S CLASSES (If scheduled) */}
      {!loadingClasses && todayClasses.length > 0 && (
        <section className="bg-white rounded-3xl p-6 border-2 border-blue-500/30 shadow-md space-y-4">
          <div className="flex items-center justify-between border-b border-slate-100 pb-2">
            <div className="flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse" />
              <h2 className="text-base font-black text-slate-900">TODAY&apos;S CLASSES</h2>
            </div>
            <span className="text-xs font-bold text-slate-500">{todayClasses.length} Scheduled Today</span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {todayClasses.map((session) => {
              const isZoom = session.platform?.toLowerCase() === 'zoom'
              return (
                <div
                  key={session.id}
                  className="p-5 rounded-2xl bg-blue-50/50 border border-blue-200 flex flex-col justify-between space-y-4"
                >
                  <div className="space-y-1.5">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded bg-blue-100 text-blue-800">
                        {session.course?.title}
                      </span>
                      <span className="text-[10px] font-bold text-slate-700 bg-white px-2 py-0.5 rounded border border-slate-200">
                        {isZoom ? '📹 Zoom' : '👥 Teams'}
                      </span>
                    </div>

                    <h3 className="text-sm font-black text-slate-900 font-mono">{session.batch_code || session.batch_number || session.batch?.code || session.title}</h3>
                    <p className="text-xs font-bold text-blue-700 font-mono">
                      ⏰ {session.start_time} – {session.end_time} IST
                    </p>
                  </div>

                  <div className="pt-3 border-t border-blue-200/80 flex items-center justify-between gap-2">
                    <span className="text-[11px] text-slate-500 font-mono">
                      ID: {session.meeting_id || 'Embedded'}
                    </span>
                    {session.meeting_url && session.meeting_url.trim().length > 0 && (
                      <button
                        type="button"
                        onClick={() => handleJoinClass(session.id)}
                        className="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-extrabold text-xs transition flex items-center gap-1.5 shadow-sm shadow-blue-500/25"
                      >
                        <span>🚀</span> JOIN CLASS
                      </button>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        </section>
      )}

      {/* SECTION: MY UPCOMING CLASSES (Progressively loaded) */}
      <section className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span className="text-xl">🎥</span>
            <h2 className="text-base font-black text-slate-900">UPCOMING CLASSES</h2>
          </div>
          <span className="text-xs font-bold text-slate-500">
            {upcomingClasses.length} live session{upcomingClasses.length === 1 ? '' : 's'} assigned
          </span>
        </div>

        {loadingClasses ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 animate-pulse">
            {[1, 2].map((i) => (
              <div key={i} className="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                <div className="h-3 w-20 bg-slate-200 rounded-md" />
                <div className="h-5 w-3/4 bg-slate-200 rounded-md" />
                <div className="h-8 bg-slate-200 rounded-xl" />
              </div>
            ))}
          </div>
        ) : errorClasses ? (
          <div className="p-4 rounded-2xl bg-red-50 text-red-700 text-xs font-bold border border-red-200 flex items-center justify-between">
            <span>{errorClasses}</span>
            <button type="button" onClick={() => fetchClasses(true)} className="px-3 py-1 bg-red-600 text-white rounded-lg text-xs">
              ↻ Retry
            </button>
          </div>
        ) : upcomingClasses.length === 0 ? (
          <div className="p-8 text-center bg-slate-50 rounded-2xl border border-slate-100 space-y-1">
            <p className="text-xs font-bold text-slate-700">No upcoming live classes scheduled.</p>
            <p className="text-[11px] text-slate-400">Your institution administrator schedules class sessions for your assigned courses.</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {upcomingClasses.map((session) => {
              const isZoom = session.platform?.toLowerCase() === 'zoom'
              return (
                <div
                  key={session.id}
                  className="p-5 rounded-2xl bg-slate-50 border border-slate-200 flex flex-col justify-between space-y-4"
                >
                  <div className="space-y-1.5">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded bg-blue-100 text-blue-800">
                        {session.course?.title}
                      </span>
                      <span className="text-[10px] font-bold text-slate-600 bg-white px-2 py-0.5 rounded border border-slate-200">
                        {isZoom ? '📹 Zoom' : '👥 Teams'}
                      </span>
                    </div>

                    <h3 className="text-sm font-black text-slate-900 font-mono">{session.batch_code || session.batch_number || session.batch?.code || session.title}</h3>
                    <p className="text-xs font-semibold text-slate-700">
                      📅 {session.scheduled_date} • ⏰ {session.start_time} – {session.end_time} IST
                    </p>
                  </div>

                  <div className="pt-3 border-t border-slate-200/80 flex items-center justify-between gap-2">
                    <span className="text-[11px] text-slate-500 font-mono">
                      ID: {session.meeting_id || 'Embedded Link'}
                    </span>
                    {session.meeting_url && session.meeting_url.trim().length > 0 && (
                      <button
                        type="button"
                        onClick={() => handleJoinClass(session.id)}
                        className="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-extrabold text-xs transition flex items-center gap-1 shadow-xs"
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

      {/* SECTION: PREVIOUS CLASSES (Progressively loaded) */}
      <section className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span className="text-xl">📚</span>
            <h2 className="text-base font-black text-slate-900">PREVIOUS CLASSES</h2>
          </div>
          <span className="text-xs font-bold text-slate-500">
            {previousClasses.length} completed
          </span>
        </div>

        {loadingClasses ? (
          <div className="space-y-3 animate-pulse">
            {[1, 2].map((i) => (
              <div key={i} className="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-2">
                <div className="h-3 w-32 bg-slate-200 rounded-md" />
                <div className="h-5 w-1/2 bg-slate-200 rounded-md" />
              </div>
            ))}
          </div>
        ) : previousClasses.length === 0 ? (
          <div className="p-6 text-center bg-slate-50 rounded-2xl border border-slate-100 text-xs text-slate-500">
            No completed classes recorded yet.
          </div>
        ) : (
          <div className="space-y-3">
            {previousClasses.map((s) => (
              <div key={s.id} className="p-4 rounded-2xl bg-slate-50 border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-blue-700">{s.course?.title}</span>
                    <span className="text-slate-400">• 📅 {s.scheduled_date}</span>
                    <span className="text-slate-400">• ⏰ {s.start_time} IST</span>
                  </div>
                  <p className="font-bold text-slate-900 text-sm">{s.title}</p>
                  <p className="text-[11px] text-slate-500">
                    {s.materials?.length || 0} materials attached • {s.attendances?.length || 0} students attended
                  </p>
                </div>
                <span className="px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase bg-slate-200 text-slate-700 self-start sm:self-auto">
                  Completed
                </span>
              </div>
            ))}
          </div>
        )}
      </section>

      {/* Main Grid: MY ASSIGNED COURSES + Recent Students (Progressively loaded) */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {/* Left 2 Cols: Assigned Courses */}
        <div className="lg:col-span-2 bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-bold text-slate-900">MY ASSIGNED COURSES</h2>
              <p className="text-xs text-slate-500">Curriculums assigned to you by administrators.</p>
            </div>
            <Link
              to="/tutor/courses"
              className="text-xs font-bold text-blue-600 hover:underline"
            >
              View All Courses ({courses.length}) →
            </Link>
          </div>

          {loadingCourses ? (
            <div className="divide-y divide-slate-100 animate-pulse">
              {[1, 2, 3].map((i) => (
                <div key={i} className="py-4 space-y-2">
                  <div className="h-3 w-20 bg-slate-200 rounded-md" />
                  <div className="h-5 w-1/2 bg-slate-200 rounded-md" />
                  <div className="h-3 w-1/3 bg-slate-100 rounded-md" />
                </div>
              ))}
            </div>
          ) : errorCourses ? (
            <div className="p-4 rounded-2xl bg-red-50 text-red-700 text-xs font-bold border border-red-200 flex items-center justify-between">
              <span>{errorCourses}</span>
              <button type="button" onClick={() => fetchCourses(true)} className="px-3 py-1 bg-red-600 text-white rounded-lg text-xs">
                ↻ Retry
              </button>
            </div>
          ) : courses.length === 0 ? (
            <div className="p-10 text-center bg-slate-50 rounded-2xl border border-slate-100">
              <span className="text-3xl mb-2 block">📖</span>
              <h3 className="text-sm font-bold text-slate-800">No courses assigned yet</h3>
              <p className="text-xs text-slate-500 mt-1">Administrators will assign training programs to your faculty account.</p>
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {courses.map((course) => (
                <div key={course.id} className="py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 border border-blue-200">
                        {course.category || 'Tech'}
                      </span>
                      <span className="text-xs text-slate-400">• {course.difficulty}</span>
                    </div>
                    <h3 className="text-sm font-bold text-slate-900">{course.title}</h3>
                    <p className="text-xs text-slate-500">
                      {course.duration} • <span className="capitalize">{course.status || 'Published'}</span> • {course.enrollments_count || 0} enrolled
                    </p>
                  </div>

                  <div className="flex items-center gap-2 text-xs font-bold shrink-0">
                    <Link
                      to={`/tutor/courses/${course.id}/curriculum`}
                      className="px-3 py-1.5 rounded-xl bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 transition flex items-center gap-1"
                    >
                      <span>🛠️</span> Curriculum
                    </Link>
                    <Link
                      to={`/tutor/courses/${course.id}/analytics`}
                      className="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 transition flex items-center gap-1"
                    >
                      <span>📊</span> Analytics
                    </Link>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Right Col: Recent Enrolled Students (Progressively loaded) */}
        <div className="lg:col-span-1 bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-bold text-slate-900">STUDENTS</h2>
            <Link to="/tutor/students" className="text-xs font-bold text-blue-600 hover:underline">
              View Roster ({stats.total_students}) →
            </Link>
          </div>

          {loadingStudents ? (
            <div className="space-y-3 animate-pulse">
              {[1, 2, 3].map((i) => (
                <div key={i} className="p-3 bg-slate-50 rounded-2xl border border-slate-100 space-y-2">
                  <div className="h-3 w-24 bg-slate-200 rounded-md" />
                  <div className="h-3 w-36 bg-slate-100 rounded-md" />
                </div>
              ))}
            </div>
          ) : errorStudents ? (
            <div className="p-4 rounded-2xl bg-red-50 text-red-700 text-xs font-bold border border-red-200 flex items-center justify-between">
              <span>{errorStudents}</span>
              <button type="button" onClick={() => fetchStudents(true)} className="px-3 py-1 bg-red-600 text-white rounded-lg text-xs">
                ↻ Retry
              </button>
            </div>
          ) : recentStudents.length === 0 ? (
            <div className="p-6 text-center bg-slate-50 rounded-2xl text-xs text-slate-500">
              No students enrolled in your courses yet.
            </div>
          ) : (
            <div className="space-y-3">
              {recentStudents.map((item) => (
                <div key={item.id} className="p-3 bg-slate-50 rounded-2xl border border-slate-100 text-xs">
                  <div className="flex items-center justify-between mb-1">
                    <p className="font-bold text-slate-800">{item.user?.name || 'Student'}</p>
                    <span className="text-[10px] text-blue-600 font-bold">
                      {Math.round(Number(item.progress_percentage || 0))}% done
                    </span>
                  </div>
                  <p className="text-[11px] text-slate-500 line-clamp-1">{item.course?.title}</p>
                  <p className="text-[10px] text-slate-400 mt-1">
                    Enrolled {new Date(item.enrolled_at).toLocaleDateString()}
                  </p>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
