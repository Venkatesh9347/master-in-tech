import { useEffect, useState, useCallback } from 'react'
import { useParams, Link } from 'react-router-dom'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import QuizPlayer from '../components/lms/QuizPlayer'
import type { ClassSession } from '../types/classSession'
import type { Quiz } from '../types/lms'

export default function StudentClassDetails() {
  const { id } = useParams<{ id: string }>()
  const [session, setSession] = useState<ClassSession | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [joining, setJoining] = useState(false)

  // Quiz Modal State
  const [activeQuizData, setActiveQuizData] = useState<Quiz | null>(null)
  const [loadingQuiz, setLoadingQuiz] = useState(false)
  const [quizModalOpen, setQuizModalOpen] = useState(false)

  const fetchSessionDetails = useCallback(() => {
    if (!id) return
    setLoading(true)
    setError('')

    API.get<ClassSession>(`/student/class-sessions/${id}`)
      .then((res) => {
        setSession(res.data)
      })
      .catch((err: unknown) => {
        const response = err as { response?: { data?: { message?: string } } }
        setError(response.response?.data?.message || 'Failed to load class session details.')
      })
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => {
    fetchSessionDetails()
  }, [fetchSessionDetails])

  const handleJoinClass = async () => {
    if (!session || !id) return
    setJoining(true)

    try {
      const res = await API.post<{ meeting_url: string; platform: string }>(`/student/class-sessions/${id}/join`)
      const url = res.data.meeting_url
      if (url) {
        window.open(url, '_blank', 'noopener,noreferrer')
      }
      // Update attendance status to present locally
      setSession({
        ...session,
        attendance_status: 'Present',
      })
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      alert(response.response?.data?.message || 'Unable to join class.')
    } finally {
      setJoining(false)
    }
  }

  const handleStartQuiz = async () => {
    const quizId = session?.quiz?.id
    if (!quizId) return
    setLoadingQuiz(true)

    try {
      const res = await API.get<Quiz>(`/quizzes/${quizId}`)
      setActiveQuizData(res.data)
      setQuizModalOpen(true)
    } catch {
      alert('Unable to load quiz questions right now.')
    } finally {
      setLoadingQuiz(false)
    }
  }

  const handleQuizComplete = () => {
    fetchSessionDetails()
  }

  const isZoom = session?.platform?.toLowerCase() === 'zoom'
  const isCancelled = session?.status === 'cancelled'
  const isCompleted = session?.status === 'completed'
  const hasRecording = Boolean(session?.recording_url && session.recording_url.trim().length > 0)
  const hasQuiz = Boolean(session?.quiz && session.quiz.id)

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <Navbar />

      <main className="flex-grow max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full">
        {/* Navigation Breadcrumb */}
        <div className="mb-6">
          <Link
            to="/student"
            className="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition"
          >
            <span>←</span> Back to Student Dashboard
          </Link>
        </div>

        {loading ? (
          <div className="py-24 text-center text-slate-500 space-y-3">
            <div className="w-10 h-10 border-2 border-blue-600/20 border-t-blue-600 rounded-full animate-spin mx-auto" />
            <p className="text-xs font-semibold">Loading class session details...</p>
          </div>
        ) : error ? (
          <div className="p-8 rounded-3xl bg-white border border-red-200 text-center space-y-4 shadow-sm">
            <span className="text-4xl">⚠️</span>
            <h2 className="text-lg font-black text-slate-900">Access Restricted</h2>
            <p className="text-xs text-slate-600 max-w-md mx-auto">{error}</p>
            <Link
              to="/student"
              className="inline-block px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-500 transition"
            >
              Return to My Courses
            </Link>
          </div>
        ) : session ? (
          <div className="space-y-8">
            {/* Header Hero Banner */}
            <div className="bg-slate-900 text-white rounded-3xl p-6 sm:p-10 shadow-xl relative overflow-hidden">
              <div className="relative z-10 space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="px-3 py-1 rounded-full text-xs font-extrabold uppercase bg-blue-500/20 text-blue-300 border border-blue-400/30">
                    {session.course?.title || session.course_title || 'MasterInTech Program'}
                  </span>
                  <span
                    className={`px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider border ${
                      session.status === 'live'
                        ? 'bg-emerald-950 text-emerald-300 border-emerald-500 animate-pulse'
                        : isCompleted
                        ? 'bg-slate-800 text-slate-300 border-slate-700'
                        : isCancelled
                        ? 'bg-rose-950 text-rose-300 border-rose-800'
                        : 'bg-blue-900/60 text-blue-200 border-blue-700'
                    }`}
                  >
                    {session.status}
                  </span>
                  <span className="px-3 py-1 rounded-full text-xs font-bold uppercase bg-slate-800 text-slate-300 border border-slate-700">
                    {isZoom ? '📹 Zoom' : '👥 Microsoft Teams'}
                  </span>
                </div>

                <h1 className="text-2xl sm:text-4xl font-black font-mono tracking-tight leading-tight">
                  {session.batch_code || session.batch_number || session.batch?.code || session.title}
                </h1>

                {session.description && (
                  <p className="text-xs sm:text-sm text-slate-300 max-w-2xl leading-relaxed">
                    {session.description}
                  </p>
                )}

                {/* Faculty & Schedule Strip */}
                <div className="pt-4 border-t border-slate-800 flex flex-wrap items-center gap-6 text-xs text-slate-300">
                  <div className="flex items-center gap-2.5">
                    <div className="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center font-bold text-white shadow-xs">
                      {session.tutor_name?.charAt(0) || session.tutor?.name?.charAt(0) || 'T'}
                    </div>
                    <div>
                      <p className="text-[10px] text-slate-400 uppercase font-bold">Trainer</p>
                      <p className="font-bold text-white">{session.tutor_name || session.tutor?.name || 'Lead Instructor'}</p>
                    </div>
                  </div>

                  <div>
                    <p className="text-[10px] text-slate-400 uppercase font-bold">Date & Time (IST)</p>
                    <p className="font-bold text-white">
                      📅 {session.scheduled_date} • ⏰ {session.start_time} – {session.end_time} IST
                    </p>
                  </div>

                  {session.meeting_id && (
                    <div>
                      <p className="text-[10px] text-slate-400 uppercase font-bold">Meeting ID</p>
                      <p className="font-mono font-bold text-blue-300">{session.meeting_id}</p>
                    </div>
                  )}

                  {session.meeting_password && (
                    <div>
                      <p className="text-[10px] text-slate-400 uppercase font-bold">Passcode</p>
                      <p className="font-mono font-bold text-slate-300">{session.meeting_password}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Background Ambient Glow */}
              <div className="absolute top-0 right-0 -mr-20 -mt-20 w-80 h-80 bg-blue-600/10 rounded-full blur-3xl pointer-events-none" />
            </div>

            {/* Cancelled Notice Banner */}
            {isCancelled && (
              <div className="p-6 rounded-3xl bg-rose-50 border border-rose-200 text-rose-900 space-y-2">
                <div className="flex items-center gap-2">
                  <span className="text-xl">🚫</span>
                  <h3 className="text-base font-extrabold">This class has been cancelled.</h3>
                </div>
                <p className="text-xs text-rose-700 leading-relaxed">
                  This training session was cancelled by the institution administration. Please refer to upcoming scheduled classes.
                </p>
              </div>
            )}

            {/* Active / Upcoming Launch Callout */}
            {!isCancelled && !isCompleted && session.meeting_url && (
              <div className="bg-white rounded-3xl p-8 border border-blue-200 shadow-md flex flex-col sm:flex-row sm:items-center justify-between gap-6">
                <div className="space-y-1">
                  <span className="text-[10px] font-extrabold uppercase tracking-wider text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full">
                    {session.status === 'live' ? '🔴 Live Session' : '📅 Scheduled Class'}
                  </span>
                  <h2 className="text-lg font-black text-slate-900 mt-2">Ready to join your interactive classroom?</h2>
                  <p className="text-xs text-slate-500">
                    Your attendance will be automatically verified when joining. Real meeting credentials configured by Admin.
                  </p>
                </div>

                <button
                  type="button"
                  onClick={handleJoinClass}
                  disabled={joining}
                  className="px-8 py-3.5 rounded-2xl bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-extrabold text-sm transition flex items-center justify-center gap-2 shadow-lg shadow-blue-500/25 shrink-0"
                >
                  <span>🚀</span> {joining ? 'Connecting...' : 'JOIN CLASS'}
                </button>
              </div>
            )}

            {!isCancelled && !isCompleted && !session.meeting_url && (
              <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs flex items-center gap-4">
                <div className="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg font-bold shrink-0 border border-amber-200">
                  ⏳
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-900">Meeting Link Pending</h3>
                  <p className="text-xs text-slate-500 mt-0.5">The administration has scheduled this session. The join button will appear as soon as the meeting URL is configured in Admin C-Panel.</p>
                </div>
              </div>
            )}

            {/* Completed Session Cards (Attendance, Quiz, Recording) */}
            {isCompleted && (
              <div className="space-y-6">
                {/* 3 Metric Status Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  {/* Attendance Card */}
                  <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs space-y-1">
                    <p className="text-[11px] font-bold uppercase text-slate-400">Attendance Status</p>
                    <p
                      className={`text-base font-black ${
                        session.attendance_status === 'Present'
                          ? 'text-emerald-600'
                          : session.attendance_status === 'Absent'
                          ? 'text-rose-600'
                          : 'text-slate-700'
                      }`}
                    >
                      {session.attendance_status === 'Present'
                        ? '✓ Present'
                        : session.attendance_status === 'Absent'
                        ? '✗ Absent'
                        : 'Not Recorded'}
                    </p>
                    <p className="text-[10px] text-slate-500">
                      {session.attendance_status === 'Present'
                        ? 'Live class attendance confirmed'
                        : 'Recorded on class session'}
                    </p>
                  </div>

                  {/* Materials Count Card */}
                  <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs space-y-1">
                    <p className="text-[11px] font-bold uppercase text-slate-400">Class Handouts</p>
                    <p className="text-base font-black text-slate-900">
                      {(session.materials?.length || 0) > 0
                        ? `${session.materials?.length} Files Shared`
                        : 'None shared'}
                    </p>
                    <p className="text-[10px] text-slate-500">Lecture slides, code, exercises</p>
                  </div>

                  {/* Quiz Status Card */}
                  <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs space-y-1">
                    <p className="text-[11px] font-bold uppercase text-slate-400">Module Quiz Checkpoint</p>
                    <p
                      className={`text-base font-black ${
                        session.quiz?.status === 'Completed'
                          ? 'text-emerald-600'
                          : session.quiz?.status === 'Available'
                          ? 'text-blue-600'
                          : 'text-slate-500'
                      }`}
                    >
                      {session.quiz?.status || 'Not assigned'}
                    </p>
                    <p className="text-[10px] text-slate-500">
                      {session.quiz?.questions_count ? `${session.quiz.questions_count} Questions` : 'Knowledge evaluation'}
                    </p>
                  </div>
                </div>

                {/* Recording Section (Shown ONLY if recording_url exists) */}
                {hasRecording && (
                  <div className="p-6 rounded-3xl bg-emerald-50 border border-emerald-200 text-emerald-950 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xs">
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <span className="text-xl">🎥</span>
                        <h3 className="text-base font-extrabold text-emerald-950">Session Recording Available</h3>
                      </div>
                      <p className="text-xs text-emerald-800">
                        Review the complete recorded lecture and trainer demonstrations at your own pace.
                      </p>
                    </div>

                    <a
                      href={session.recording_url as string}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold text-xs transition flex items-center justify-center gap-1.5 shadow-sm shrink-0"
                    >
                      <span>▶️</span> WATCH RECORDING
                    </a>
                  </div>
                )}

                {/* Interactive Quiz Checkpoint Section (Only if quiz assigned) */}
                {hasQuiz && (
                  <section className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-4">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                      <div>
                        <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
                          Module Assessment
                        </span>
                        <h2 className="text-lg font-black text-slate-900 mt-1">
                          {session.quiz?.title || 'Class Knowledge Checkpoint'}
                        </h2>
                        {session.quiz?.description && (
                          <p className="text-xs text-slate-500 mt-0.5">{session.quiz.description}</p>
                        )}
                      </div>

                      <div className="flex items-center gap-3">
                        {session.quiz?.score !== null && session.quiz?.score !== undefined && (
                          <div className="text-right">
                            <p className="text-[10px] text-slate-400 uppercase font-bold">Latest Score</p>
                            <p className={`text-base font-black ${session.quiz.passed ? 'text-emerald-600' : 'text-amber-600'}`}>
                              {session.quiz.score}% {session.quiz.passed ? '(Passed)' : ''}
                            </p>
                          </div>
                        )}

                        <button
                          type="button"
                          onClick={handleStartQuiz}
                          disabled={loadingQuiz}
                          className="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-extrabold text-xs transition flex items-center gap-1.5 shadow-sm shadow-blue-500/20 shrink-0"
                        >
                          <span>📝</span> {loadingQuiz ? 'Loading...' : session.quiz?.attempt_status === 'Completed' ? 'RETAKE QUIZ' : 'START QUIZ'}
                        </button>
                      </div>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2 text-xs">
                      <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <p className="text-[10px] text-slate-400 uppercase font-bold">Questions</p>
                        <p className="font-bold text-slate-800 mt-0.5">{session.quiz?.questions_count || 0} Multiple Choice</p>
                      </div>
                      <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <p className="text-[10px] text-slate-400 uppercase font-bold">Time Limit</p>
                        <p className="font-bold text-slate-800 mt-0.5">{session.quiz?.time_limit ? `${session.quiz.time_limit} Mins` : 'Untimed'}</p>
                      </div>
                      <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <p className="text-[10px] text-slate-400 uppercase font-bold">Passing Score</p>
                        <p className="font-bold text-slate-800 mt-0.5">{session.quiz?.passing_score || 70}%</p>
                      </div>
                    </div>
                  </section>
                )}
              </div>
            )}

            {/* Materials List Section */}
            <section className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-4">
              <div className="flex items-center justify-between">
                <h2 className="text-base font-black text-slate-900 flex items-center gap-2">
                  <span>📚</span> Class Materials & Study Handouts ({session.materials?.length || 0})
                </h2>
                <span className="text-xs font-bold text-slate-500">
                  {(session.materials?.length || 0) > 0 ? `${session.materials?.length} files shared` : 'None shared'}
                </span>
              </div>

              {session.materials && session.materials.length > 0 ? (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {session.materials.map((m) => (
                    <div
                      key={m.id}
                      className="p-5 rounded-2xl bg-slate-50 border border-slate-200/90 flex flex-col justify-between space-y-3"
                    >
                      <div className="space-y-1">
                        <div className="flex items-center justify-between gap-2">
                          <span className="text-2xl">📄</span>
                          <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded bg-slate-200 text-slate-700">
                            {m.file_type || 'PDF'}
                          </span>
                        </div>
                        <h3 className="font-bold text-sm text-slate-900">{m.title}</h3>
                        {m.description && (
                          <p className="text-xs text-slate-500">{m.description}</p>
                        )}
                        <p className="text-[10px] text-slate-400 mt-1">
                          {m.file_name} • {(m.file_size / 1024).toFixed(1)} KB • {new Date(m.created_at).toLocaleDateString()}
                        </p>
                      </div>

                      <a
                        href={m.file_path}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="w-full py-2 px-4 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs text-center transition flex items-center justify-center gap-1 shadow-xs"
                      >
                        VIEW / DOWNLOAD
                      </a>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="py-10 text-center text-slate-400 text-xs italic bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                  No materials have been shared for this session yet.
                </div>
              )}
            </section>
          </div>
        ) : null}
      </main>

      {/* Interactive Quiz Runner Modal */}
      {quizModalOpen && activeQuizData && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4 overflow-y-auto backdrop-blur-xs">
          <div className="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div>
                <h3 className="text-lg font-black text-slate-900">{activeQuizData.title}</h3>
                <p className="text-xs text-slate-500">Module Knowledge Evaluation</p>
              </div>
              <button
                type="button"
                onClick={() => setQuizModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 font-bold text-lg p-1"
              >
                ✕
              </button>
            </div>

            <QuizPlayer
              quiz={activeQuizData}
              courseId={session?.course_id || 0}
              onComplete={() => {
                handleQuizComplete()
                setQuizModalOpen(false)
              }}
            />
          </div>
        </div>
      )}

      <Footer />
    </div>
  )
}
