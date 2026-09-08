import { useEffect, useState, useMemo } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import API from '../services/api'
import type { CourseProgress, Lesson, Section, LessonDiscussion, LessonDiscussionReply, LessonNoteData } from '../types/lms'
import VideoPlayer from '../components/lms/VideoPlayer'
import TextReader from '../components/lms/TextReader'
import LessonResourcesBox from '../components/lms/LessonResourcesBox'
import QuizPlayer from '../components/lms/QuizPlayer'
import AssignmentViewer from '../components/lms/AssignmentViewer'
import EnquiryModal from '../components/EnquiryModal'
import LiveClassList from '../components/live/LiveClassList'
import type { LiveClass } from '../types/liveClass'

export default function StudentLessons() {
  const { courseId } = useParams<{ courseId: string }>()
  const navigate = useNavigate()
  const [courseProgress, setCourseProgress] = useState<CourseProgress | null>(null)
  const [activeLesson, setActiveLesson] = useState<Lesson | null>(null)
  const [activeSection, setActiveSection] = useState<Section | null>(null)
  const [completedLessonIds, setCompletedLessonIds] = useState<number[]>([])
  const [loading, setLoading] = useState(true)
  const [completing, setCompleting] = useState(false)
  const [generatingCert, setGeneratingCert] = useState(false)
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false)
  const [enquiryOpen, setEnquiryOpen] = useState(false)
  const [error, setError] = useState('')
  const [actionError, setActionError] = useState<string | null>(null)

  // Bottom Tabs State ('overview' | 'notes' | 'resources' | 'discussion' | 'live')
  const [activeBottomTab, setActiveBottomTab] = useState<'overview' | 'notes' | 'resources' | 'discussion' | 'live'>('overview')
  const [courseLiveClasses, setCourseLiveClasses] = useState<LiveClass[]>([])
  const [loadingLiveClasses, setLoadingLiveClasses] = useState(false)

  // Real Database-Backed Personal Notes State
  const [personalNote, setPersonalNote] = useState('')
  const [noteSaved, setNoteSaved] = useState(false)
  const [savingNote, setSavingNote] = useState(false)

  // Real Database-Backed Q&A Discussion State
  const [discussions, setDiscussions] = useState<LessonDiscussion[]>([])
  const [newQuestionText, setNewQuestionText] = useState('')
  const [submittingQuestion, setSubmittingQuestion] = useState(false)
  const [replyBoxId, setReplyBoxId] = useState<number | null>(null)
  const [replyText, setReplyText] = useState('')
  const [submittingReply, setSubmittingReply] = useState(false)

  // Load course LMS progress hierarchy
  useEffect(() => {
    if (!courseId) return

    setLoading(true)
    API.get<CourseProgress>(`/courses/${courseId}/lms-progress`)
      .then((res) => {
        setCourseProgress(res.data)
        setCompletedLessonIds(res.data.completed_lessons || [])

        // Pick initial lesson
        if (res.data.current_lesson) {
          setActiveLesson(res.data.current_lesson)
          const parentSec = res.data.sections.find((s) =>
            s.lessons?.some((l) => l.id === res.data.current_lesson?.id)
          )
          if (parentSec) setActiveSection(parentSec)
        } else if (res.data.sections.length > 0 && res.data.sections[0].lessons?.length) {
          setActiveLesson(res.data.sections[0].lessons[0])
          setActiveSection(res.data.sections[0])
        }
      })
      .catch((err: unknown) => {
        const response = err as { response?: { data?: { message?: string } } }
        setError(response.response?.data?.message || 'Failed to load course classroom.')
      })
      .finally(() => setLoading(false))
  }, [courseId])

  // Load real student personal notes and Q&A from backend when activeLesson changes
  useEffect(() => {
    if (courseId && activeLesson) {
      // 1. Fetch authenticated personal note from database
      API.get<LessonNoteData>(`/courses/${courseId}/lessons/${activeLesson.id}/note`)
        .then((res) => {
          setPersonalNote(res.data.note || '')
        })
        .catch(() => {
          // Fallback to local key if offline
          const key = `mit_note_${courseId}_${activeLesson.id}`
          setPersonalNote(localStorage.getItem(key) || '')
        })

      // 2. Fetch real Q&A discussion threads from database
      API.get<LessonDiscussion[]>(`/courses/${courseId}/lessons/${activeLesson.id}/discussions`)
        .then((res) => {
          setDiscussions(Array.isArray(res.data) ? res.data : [])
        })
        .catch(() => {})

      setNoteSaved(false)
      setActionError(null)
    }
  }, [courseId, activeLesson])

  const handleSaveNote = async () => {
    if (!courseId || !activeLesson || savingNote) return

    setSavingNote(true)
    try {
      await API.post(`/courses/${courseId}/lessons/${activeLesson.id}/note`, {
        note: personalNote,
      })
      const key = `mit_note_${courseId}_${activeLesson.id}`
      localStorage.setItem(key, personalNote)
      setNoteSaved(true)
      setTimeout(() => setNoteSaved(false), 3000)
    } catch {
      setActionError('Could not save note to database right now.')
    } finally {
      setSavingNote(false)
    }
  }

  const handlePostQuestion = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!courseId || !activeLesson || !newQuestionText.trim() || submittingQuestion) return

    setSubmittingQuestion(true)
    try {
      const res = await API.post<LessonDiscussion>(
        `/courses/${courseId}/lessons/${activeLesson.id}/discussions`,
        {
          question: newQuestionText.trim(),
        }
      )
      setDiscussions((prev) => [res.data, ...prev])
      setNewQuestionText('')
    } catch {
      setActionError('Could not submit question right now.')
    } finally {
      setSubmittingQuestion(false)
    }
  }

  const handlePostReply = async (discussionId: number) => {
    if (!replyText.trim() || submittingReply) return

    setSubmittingReply(true)
    try {
      const res = await API.post<LessonDiscussionReply>(
        `/discussions/${discussionId}/reply`,
        {
          reply: replyText.trim(),
        }
      )
      setDiscussions((prev) =>
        prev.map((d) =>
          d.id === discussionId
            ? { ...d, replies: [...(d.replies || []), res.data] }
            : d
        )
      )
      setReplyText('')
      setReplyBoxId(null)
    } catch {
      setActionError('Could not post reply right now.')
    } finally {
      setSubmittingReply(false)
    }
  }

  // Flatten all lessons across sections for prev/next chronological navigation
  const allLessons: { lesson: Lesson; section: Section }[] = useMemo(() => {
    const list: { lesson: Lesson; section: Section }[] = []
    if (courseProgress?.sections) {
      courseProgress.sections.forEach((sec) => {
        ;(sec.lessons || []).forEach((les) => {
          list.push({ lesson: les, section: sec })
        })
      })
    }
    return list
  }, [courseProgress])

  const currentIdx = allLessons.findIndex((item) => item.lesson.id === activeLesson?.id)
  const prevItem = currentIdx > 0 ? allLessons[currentIdx - 1] : null
  const nextItem = currentIdx >= 0 && currentIdx < allLessons.length - 1 ? allLessons[currentIdx + 1] : null

  const isCurrentCompleted = activeLesson ? completedLessonIds.includes(activeLesson.id) : false

  const handleLessonSelect = (lesson: Lesson, section: Section) => {
    setActiveLesson(lesson)
    setActiveSection(section)
    setMobileSidebarOpen(false)
    setActionError(null)
    window.scrollTo({ top: 0, behavior: 'smooth' })

    // Record lesson start & last accessed timestamp in backend
    if (courseId) {
      API.post(`/courses/${courseId}/lessons/${lesson.id}/start`).catch(() => {})
    }
  }

  // Handle video playback heartbeat & progress syncing
  const handlePlaybackProgress = (currentTime: number, duration: number) => {
    if (!courseId || !activeLesson) return

    API.post<{
      completed: boolean
      status: string
      watched_percent: number
      course_progress_percentage: number
      completed_count: number
      total_lessons: number
      is_course_completed: boolean
    }>(`/courses/${courseId}/lessons/${activeLesson.id}/playback-progress`, {
      current_time: currentTime,
      duration: duration,
    })
      .then((res) => {
        if (res.data.completed) {
          setCompletedLessonIds((prev) =>
            prev.includes(activeLesson.id) ? prev : [...prev, activeLesson.id]
          )
        }

        if (courseProgress) {
          setCourseProgress((prev) =>
            prev
              ? {
                  ...prev,
                  progress_percentage: res.data.course_progress_percentage ?? prev.progress_percentage,
                  completed_lesson_count: res.data.completed_count ?? prev.completed_lesson_count,
                  is_course_completed: res.data.is_course_completed ?? prev.is_course_completed,
                }
              : null
          )
        }
      })
      .catch(() => {})
  }

  const handleLessonComplete = async () => {
    if (!courseId || !activeLesson || completing) return

    setCompleting(true)
    setActionError(null)

    try {
      const res = await API.post<{
        completed: boolean
        progress_percentage: number
        completed_count: number
        total_lessons: number
        is_course_completed: boolean
      }>(`/courses/${courseId}/lessons/${activeLesson.id}/complete`)

      const isNowCompleted = res.data.completed !== false

      // Update completed state ONLY from backend response
      setCompletedLessonIds((prev) => {
        if (isNowCompleted) {
          return prev.includes(activeLesson.id) ? prev : [...prev, activeLesson.id]
        } else {
          return prev.filter((id) => id !== activeLesson.id)
        }
      })

      // Update full course progress state
      if (courseProgress) {
        setCourseProgress((prev) =>
          prev
            ? {
                ...prev,
                progress_percentage: res.data.progress_percentage ?? prev.progress_percentage,
                completed_lesson_count: res.data.completed_count ?? prev.completed_lesson_count,
                is_course_completed: res.data.is_course_completed ?? prev.is_course_completed,
              }
            : null
        )
      }

      // Auto-advance to next lesson if available
      if (isNowCompleted && nextItem) {
        setActiveLesson(nextItem.lesson)
        setActiveSection(nextItem.section)
        window.scrollTo({ top: 0, behavior: 'smooth' })
        API.post(`/courses/${courseId}/lessons/${nextItem.lesson.id}/start`).catch(() => {})
      }
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setActionError(
        response.response?.data?.message ||
          'Failed to complete lesson. Please ensure all completion criteria are fulfilled.'
      )
    } finally {
      setCompleting(false)
    }
  }

  const handleClaimOrViewCertificate = async () => {
    if (!courseId || generatingCert) return
    setGeneratingCert(true)
    setActionError(null)
    try {
      const res = await API.post<{
        certificate?: { certificate_code?: string }
        message?: string
      }>(`/courses/${courseId}/certificate`)
      const code = res.data?.certificate?.certificate_code
      if (code) {
        navigate(`/student/certificates/${code}`)
      } else {
        // No code returned means the certificate was not issued; surface the server message instead.
        setActionError(
          res.data?.message || 'Certificate could not be issued. Please complete all lessons first.'
        )
      }
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setActionError(
        response.response?.data?.message ||
          'Certificate could not be issued. Please ensure all lessons are completed.'
      )
    } finally {
      setGeneratingCert(false)
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-900 text-white flex flex-col items-center justify-center p-6">
        <span className="animate-spin inline-block w-10 h-10 border-4 border-blue-500 border-t-transparent rounded-full mb-4" />
        <p className="text-slate-400 font-medium text-sm">Entering interactive classroom...</p>
      </div>
    )
  }

  if (error || !courseProgress) {
    return (
      <div className="min-h-screen bg-slate-900 text-white flex flex-col items-center justify-center p-6 text-center">
        <div className="bg-slate-950 p-8 rounded-3xl border border-slate-800 shadow-2xl max-w-md w-full space-y-4">
          <div className="w-14 h-14 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 text-2xl font-bold flex items-center justify-center mx-auto">
            🔒
          </div>
          <h2 className="text-xl font-black text-white">Course Access Required</h2>
          <p className="text-xs text-slate-400 leading-relaxed">
            {error || 'You do not have active enrollment access to this classroom.'}
          </p>
          <div className="pt-2 flex flex-col gap-2.5">
            <button
              type="button"
              onClick={() => setEnquiryOpen(true)}
              className="w-full py-3 rounded-xl font-bold text-xs text-white bg-blue-600 hover:bg-blue-700 transition shadow-md shadow-blue-500/20"
            >
              Book Free Live Demo & Enquire
            </button>
            <Link
              to="/student"
              className="w-full py-2.5 rounded-xl font-bold text-xs text-slate-300 bg-slate-800 hover:bg-slate-700 transition"
            >
              ← Back to Student Dashboard
            </Link>
          </div>
        </div>

        <EnquiryModal
          isOpen={enquiryOpen}
          onClose={() => setEnquiryOpen(false)}
          courseId={courseId ? Number(courseId) : undefined}
        />
      </div>
    )
  }

  const progressPercentage = Math.round(courseProgress.progress_percentage || 0)
  const is100Percent = progressPercentage >= 100

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100 flex flex-col selection:bg-blue-500 selection:text-white">
      {/* 1. TOP CLASSROOM NAV BAR */}
      <header className="bg-slate-950 border-b border-slate-800 sticky top-0 z-40 h-16 flex items-center justify-between px-4 sm:px-6">
        <div className="flex items-center gap-3 min-w-0">
          <Link
            to="/student"
            className="text-xs font-bold text-slate-400 hover:text-white transition flex items-center gap-1.5 shrink-0"
          >
            <span>←</span> <span className="hidden sm:inline">My Dashboard</span>
          </Link>

          <span className="text-slate-700">|</span>

          <div className="min-w-0">
            <h1 className="text-sm font-bold text-white truncate max-w-xs sm:max-w-md lg:max-w-lg">
              {courseProgress.course_title}
            </h1>
            {activeSection && (
              <p className="text-[11px] text-slate-400 truncate">
                {activeSection.title} • Lesson {currentIdx + 1} of {allLessons.length}
              </p>
            )}
          </div>
        </div>

        <div className="flex items-center gap-4 shrink-0">
          {/* Progress Pill */}
          <div className="hidden sm:flex items-center gap-2 text-xs">
            <span className="text-slate-400">Progress:</span>
            <span className="font-extrabold text-blue-400">{progressPercentage}%</span>
            <div className="w-20 h-2 bg-slate-800 rounded-full overflow-hidden">
              <div
                className="h-full bg-gradient-to-r from-blue-500 to-indigo-500 rounded-full transition-all duration-500"
                style={{ width: `${Math.max(5, progressPercentage)}%` }}
              />
            </div>
          </div>

          {/* Certificate Ready Indicator */}
          {is100Percent && (
            <button
              type="button"
              onClick={handleClaimOrViewCertificate}
              disabled={generatingCert}
              className="px-3 py-1 rounded-full text-xs font-extrabold bg-amber-400 text-slate-950 hover:bg-amber-300 transition shadow-xs flex items-center gap-1 cursor-pointer"
            >
              <span>🎓</span> {generatingCert ? 'Loading...' : 'Certificate Ready!'}
            </button>
          )}

          {/* Mobile Curriculum Toggle */}
          <button
            type="button"
            onClick={() => setMobileSidebarOpen(!mobileSidebarOpen)}
            className="md:hidden px-3 py-1.5 rounded-xl bg-slate-800 text-xs font-bold text-slate-200 hover:bg-slate-700"
          >
            📚 Curriculum
          </button>
        </div>
      </header>

      {/* 2. MAIN WORKSPACE: LEFT/CENTER PLAYER + RIGHT CURRICULUM */}
      <div className="flex-grow flex flex-col md:flex-row overflow-hidden">
        {/* Left/Center Main Area */}
        <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">
          {/* Course Completed Celebratory Banner */}
          {is100Percent && (
            <div className="p-5 rounded-2xl bg-gradient-to-r from-emerald-950/80 via-slate-900 to-indigo-950/80 border border-emerald-500/30 flex flex-col sm:flex-row items-center justify-between gap-4 shadow-lg">
              <div className="flex items-center gap-3.5">
                <span className="text-3xl">🏆</span>
                <div>
                  <h3 className="text-base font-extrabold text-white">Course Completed! (100%)</h3>
                  <p className="text-xs text-slate-300">
                    Congratulations! You have completed all published lessons and modules in this program.
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={handleClaimOrViewCertificate}
                disabled={generatingCert}
                className="shrink-0 px-5 py-2.5 rounded-xl font-bold text-xs bg-amber-400 text-slate-950 hover:bg-amber-300 transition shadow-sm flex items-center gap-1.5 cursor-pointer"
              >
                <span>🎓</span> {generatingCert ? 'Loading...' : 'View Official Certificate'}
              </button>
            </div>
          )}

          {/* Action Error Banner if completion requirement unmet */}
          {actionError && (
            <div className="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs font-semibold flex items-center justify-between gap-3 shadow-lg">
              <div className="flex items-center gap-2.5">
                <span className="text-base">⚠️</span>
                <span>{actionError}</span>
              </div>
              <button
                type="button"
                onClick={() => setActionError(null)}
                className="text-rose-400 hover:text-white font-bold p-1"
                aria-label="Dismiss alert"
              >
                ✕
              </button>
            </div>
          )}

          {/* Active Lesson Content Viewer */}
          {activeLesson ? (
            <div className="space-y-6">
              {activeLesson.type === 'video' && (
                <VideoPlayer
                  lesson={activeLesson}
                  courseId={Number(courseId)}
                  onProgress={handlePlaybackProgress}
                />
              )}
              {(activeLesson.type === 'text' || activeLesson.type === 'article') && <TextReader lesson={activeLesson} />}
              {activeLesson.type === 'document' && <TextReader lesson={activeLesson} />}
              {activeLesson.type === 'quiz' && activeLesson.quiz && (
                <QuizPlayer
                  quiz={activeLesson.quiz}
                  courseId={Number(courseId)}
                  onComplete={() => handleLessonComplete()}
                />
              )}
              {(activeLesson.type === 'assignment' || activeLesson.type === 'project') && activeLesson.assignment && (
                <AssignmentViewer
                  assignment={activeLesson.assignment}
                  onComplete={() => handleLessonComplete()}
                />
              )}
              {(activeLesson.type === 'assignment' || activeLesson.type === 'project') && !activeLesson.assignment && (
                <TextReader lesson={activeLesson} />
              )}

              {/* Downloadable Resources Box if attached */}
              {activeLesson.resources && activeLesson.resources.length > 0 && (
                <LessonResourcesBox resources={activeLesson.resources} />
              )}
            </div>
          ) : (
            <div className="p-12 text-center bg-slate-800/40 rounded-3xl border border-slate-700/60">
              <span className="text-4xl mb-2 block">📖</span>
              <h3 className="text-base font-bold text-white">Select a lesson to begin</h3>
              <p className="text-xs text-slate-400 mt-1">Choose a topic from the curriculum sidebar.</p>
            </div>
          )}

          {/* 3. BELOW TABS: Overview, Notes, Resources, Discussion */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 overflow-hidden shadow-xl">
            {/* Tab Bar */}
            <div className="flex items-center border-b border-slate-800 px-6 pt-4 gap-6 text-xs font-bold">
              {[
                { id: 'overview', label: '📖 Overview & Objectives', icon: '' },
                { id: 'notes', label: '✍️ My Notes', icon: '' },
                { id: 'resources', label: '📎 Downloads & Links', icon: '' },
                { id: 'discussion', label: '💬 Q&A Discussion', icon: '' },
                { id: 'live', label: '📹 Live Masterclasses', icon: '' },
              ].map((tab) => (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => {
                    setActiveBottomTab(tab.id as typeof activeBottomTab)
                    if (tab.id === 'live' && courseId) {
                      setLoadingLiveClasses(true)
                      API.get<{ classes: LiveClass[] }>(`/courses/${courseId}/live-classes`)
                        .then((res) => setCourseLiveClasses(res.data.classes || []))
                        .catch(() => {})
                        .finally(() => setLoadingLiveClasses(false))
                    }
                  }}
                  className={`pb-3 transition border-b-2 ${
                    activeBottomTab === tab.id
                      ? 'text-blue-400 border-blue-500 font-extrabold'
                      : 'text-slate-400 border-transparent hover:text-slate-200'
                  }`}
                >
                  {tab.label}
                </button>
              ))}
            </div>

            {/* Tab Contents */}
            <div className="p-6 sm:p-8 text-xs sm:text-sm text-slate-300">
              {/* Overview Tab */}
              {activeBottomTab === 'overview' && (
                <div className="space-y-4">
                  <h3 className="text-base font-bold text-white">
                    {activeLesson?.title || 'Lesson Overview'}
                  </h3>
                  <p className="text-slate-400 leading-relaxed">
                    {activeLesson?.description ||
                      'In this module, you will gain hands-on proficiency in building production-standard engineering components, mastering core syntax, debugging workflows, and testing.'}
                  </p>

                  <div className="pt-4 border-t border-slate-800">
                    <h4 className="text-xs font-extrabold uppercase tracking-wider text-slate-400 mb-2">
                      Key Competency Outcomes
                    </h4>
                    <ul className="space-y-1.5 text-xs text-slate-300">
                      <li className="flex items-center gap-2">
                        <span className="text-blue-400">✓</span> Master core principles and architecture paradigms
                      </li>
                      <li className="flex items-center gap-2">
                        <span className="text-blue-400">✓</span> Implement hands-on coding exercises and capstone milestones
                      </li>
                      <li className="flex items-center gap-2">
                        <span className="text-blue-400">✓</span> Write maintainable, testable code conforming to industry standards
                      </li>
                    </ul>
                  </div>
                </div>
              )}

              {/* Notes Tab */}
              {activeBottomTab === 'notes' && (
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <div>
                      <h3 className="text-sm font-bold text-white">Personal Study Notes</h3>
                      <p className="text-[11px] text-slate-500">Notes are auto-saved to your browser for this lesson.</p>
                    </div>
                    {noteSaved && (
                      <span className="text-emerald-400 text-xs font-bold">✓ Notes Saved!</span>
                    )}
                  </div>

                  <textarea
                    value={personalNote}
                    onChange={(e) => setPersonalNote(e.target.value)}
                    rows={6}
                    placeholder="Type key takeaways, code snippets, or thoughts for this lesson..."
                    className="w-full p-4 rounded-2xl bg-slate-900 border border-slate-700 text-xs text-white placeholder:text-slate-500 focus:ring-2 focus:ring-blue-500 outline-none"
                  />

                  <div className="flex justify-end">
                    <button
                      type="button"
                      onClick={handleSaveNote}
                      className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 transition"
                    >
                      Save Notes
                    </button>
                  </div>
                </div>
              )}

              {/* Resources Tab */}
              {activeBottomTab === 'resources' && (
                <div className="space-y-4">
                  <div>
                    <h3 className="text-sm font-bold text-white">Lesson Resources & Attachments</h3>
                    <p className="text-xs text-slate-400">
                      Download source code, templates, or documentation attached to this lesson.
                    </p>
                  </div>

                  {activeLesson?.resources && activeLesson.resources.length > 0 ? (
                    <div className="space-y-2.5">
                      {activeLesson.resources.map((res) => (
                        <div
                          key={res.id}
                          className="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between gap-3"
                        >
                          <div className="flex items-center gap-2.5 min-w-0">
                            <span className="text-lg shrink-0">📄</span>
                            <div className="min-w-0">
                              <p className="font-bold text-white text-xs truncate">{res.title}</p>
                              <p className="text-[10px] text-slate-400 truncate">
                                {res.file_size ? `${res.file_size} • ` : ''}
                                {res.description || 'Downloadable attachment'}
                              </p>
                            </div>
                          </div>
                          <a
                            href={res.file_url}
                            target="_blank"
                            rel="noreferrer"
                            className="px-3.5 py-1.5 rounded-xl font-bold text-xs bg-blue-600 hover:bg-blue-700 text-white transition shrink-0 inline-flex items-center gap-1"
                          >
                            <span>Download</span>
                            <span>↓</span>
                          </a>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="p-8 rounded-2xl bg-slate-900/60 border border-slate-800 text-center space-y-1">
                      <span className="text-2xl block mb-1">📁</span>
                      <p className="text-xs font-bold text-slate-300">No attachments for this lesson</p>
                      <p className="text-[11px] text-slate-500 max-w-sm mx-auto">
                        Any supplementary starter code, technical slides, or PDFs added for this module will be listed here.
                      </p>
                    </div>
                  )}
                </div>
              )}

              {/* Discussion Tab */}
              {activeBottomTab === 'discussion' && (
                <div className="space-y-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <h3 className="text-sm font-bold text-white">Student & Mentor Q&A Forum</h3>
                      <p className="text-[11px] text-slate-500">Ask technical questions and engage in discussions directly with instructors.</p>
                    </div>
                  </div>

                  {/* Ask Question Form */}
                  <form onSubmit={handlePostQuestion} className="space-y-2">
                    <textarea
                      value={newQuestionText}
                      onChange={(e) => setNewQuestionText(e.target.value)}
                      rows={3}
                      placeholder="Ask a question about this lesson or share your insights..."
                      className="w-full p-3.5 rounded-2xl bg-slate-900 border border-slate-700 text-xs text-white placeholder:text-slate-500 focus:ring-2 focus:ring-blue-500 outline-none"
                    />
                    <div className="flex justify-end">
                      <button
                        type="submit"
                        disabled={submittingQuestion || !newQuestionText.trim()}
                        className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 transition"
                      >
                        {submittingQuestion ? 'Posting...' : 'Post Question'}
                      </button>
                    </div>
                  </form>

                  {/* Questions List */}
                  <div className="space-y-4 pt-2">
                    {discussions.length === 0 ? (
                      <div className="p-8 rounded-2xl bg-slate-900/40 border border-slate-800 text-center text-slate-500 text-xs">
                        <span className="text-2xl block mb-1">💬</span>
                        No questions asked yet for this lesson. Be the first to start the discussion!
                      </div>
                    ) : (
                      discussions.map((d) => (
                        <div key={d.id} className="p-4 rounded-2xl bg-slate-900 border border-slate-800 space-y-3">
                          <div className="flex items-center justify-between text-xs">
                            <div className="flex items-center gap-2">
                              <span className="font-bold text-blue-400">{d.user?.name || 'Student'}</span>
                              <span className="text-[10px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-400 font-semibold uppercase">
                                {d.user?.role || 'student'}
                              </span>
                            </div>
                            <span className="text-[10px] text-slate-500">
                              {new Date(d.created_at).toLocaleDateString()}
                            </span>
                          </div>

                          <p className="text-xs text-slate-200 leading-relaxed">{d.question_text}</p>

                          {/* Replies */}
                          {d.replies && d.replies.length > 0 && (
                            <div className="space-y-2 pt-2 border-t border-slate-800/80">
                              {d.replies.map((r) => (
                                <div key={r.id} className="p-3 rounded-xl bg-slate-950/70 border border-slate-800/80 text-xs space-y-1">
                                  <div className="flex items-center justify-between text-[11px]">
                                    <span className="font-bold text-slate-300">
                                      {r.user?.name || 'Mentor'}{' '}
                                      <span className="text-[10px] text-indigo-400 font-medium">({r.user?.role || 'Instructor'})</span>
                                    </span>
                                    <span className="text-[10px] text-slate-500">
                                      {new Date(r.created_at).toLocaleDateString()}
                                    </span>
                                  </div>
                                  <p className="text-slate-400 text-xs">{r.reply_text}</p>
                                </div>
                              ))}
                            </div>
                          )}

                          {/* Inline Reply Form */}
                          {replyBoxId === d.id ? (
                            <div className="pt-2 space-y-2">
                              <textarea
                                value={replyText}
                                onChange={(e) => setReplyText(e.target.value)}
                                rows={2}
                                placeholder="Write your reply..."
                                className="w-full p-2.5 rounded-xl bg-slate-950 border border-slate-700 text-xs text-white placeholder:text-slate-500 focus:ring-1 focus:ring-blue-500 outline-none"
                              />
                              <div className="flex items-center justify-end gap-2">
                                <button
                                  type="button"
                                  onClick={() => {
                                    setReplyBoxId(null)
                                    setReplyText('')
                                  }}
                                  className="px-3 py-1 text-xs text-slate-400 hover:text-white"
                                >
                                  Cancel
                                </button>
                                <button
                                  type="button"
                                  onClick={() => handlePostReply(d.id)}
                                  disabled={submittingReply || !replyText.trim()}
                                  className="px-4 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold disabled:opacity-50 transition"
                                >
                                  {submittingReply ? 'Sending...' : 'Reply'}
                                </button>
                              </div>
                            </div>
                          ) : (
                            <div className="flex justify-end pt-1">
                              <button
                                type="button"
                                onClick={() => {
                                  setReplyBoxId(d.id)
                                  setReplyText('')
                                }}
                                className="text-[11px] font-bold text-blue-400 hover:text-blue-300 flex items-center gap-1"
                              >
                                <span>↩</span> Reply
                              </button>
                            </div>
                          )}
                        </div>
                      ))
                    )}
                  </div>
                </div>
              )}

              {/* Live Masterclasses Tab */}
              {activeBottomTab === 'live' && (
                <div className="space-y-4">
                  <div>
                    <h3 className="text-sm font-bold text-white">Live Classroom Sessions</h3>
                    <p className="text-xs text-slate-400">
                      Join scheduled live masterclasses, interactive coding teardowns, and tutor Q&A.
                    </p>
                  </div>

                  <LiveClassList
                    classes={courseLiveClasses}
                    loading={loadingLiveClasses}
                    courseId={Number(courseId)}
                  />
                </div>
              )}
            </div>
          </div>
        </main>

        {/* Right Curriculum Sidebar */}
        <aside
          className={`w-full md:w-80 lg:w-96 bg-slate-950 border-l border-slate-800 flex-shrink-0 flex flex-col ${
            mobileSidebarOpen ? 'block fixed inset-0 z-50 md:relative' : 'hidden md:flex'
          }`}
        >
          {/* Sidebar Top Header */}
          <div className="p-4 border-b border-slate-800 flex items-center justify-between">
            <div>
              <h2 className="text-sm font-bold text-white">Course Curriculum</h2>
              <p className="text-[11px] text-slate-400">
                {completedLessonIds.length} of {allLessons.length} lessons completed ({progressPercentage}%)
              </p>
            </div>
            {mobileSidebarOpen && (
              <button
                type="button"
                onClick={() => setMobileSidebarOpen(false)}
                className="md:hidden p-1.5 text-slate-400 hover:text-white text-lg font-bold"
              >
                ✕
              </button>
            )}
          </div>

          {/* Curriculum Sections List */}
          <div className="flex-1 overflow-y-auto p-3 space-y-3">
            {courseProgress.sections.map((section, sIdx) => {
              const lessons = section.lessons || []
              const completedInSec = lessons.filter((l) => completedLessonIds.includes(l.id)).length

              return (
                <div key={section.id} className="rounded-2xl bg-slate-900 border border-slate-800 overflow-hidden">
                  <div className="p-3 bg-slate-900/80 border-b border-slate-800/80 flex items-center justify-between">
                    <div>
                      <p className="text-[10px] font-bold text-slate-400 uppercase">Module {sIdx + 1}</p>
                      <h4 className="text-xs font-bold text-white truncate max-w-[200px]">{section.title}</h4>
                    </div>
                    <span className="text-[10px] font-bold text-slate-400 bg-slate-800 px-2 py-0.5 rounded-full">
                      {completedInSec}/{lessons.length}
                    </span>
                  </div>

                  <div className="divide-y divide-slate-800/50 p-1.5">
                    {lessons.map((lesson) => {
                      const isActive = activeLesson?.id === lesson.id
                      const isCompleted = completedLessonIds.includes(lesson.id)
                      const isInProgress =
                        !isCompleted &&
                        (lesson.started ||
                          (lesson.progress_percentage !== undefined && lesson.progress_percentage > 0) ||
                          (isActive && activeLesson?.started))

                      return (
                        <button
                          key={lesson.id}
                          type="button"
                          onClick={() => handleLessonSelect(lesson, section)}
                          className={`w-full p-2.5 rounded-xl text-left transition text-xs flex items-center justify-between gap-2 ${
                            isActive
                              ? 'bg-blue-600 text-white font-bold shadow-sm'
                              : 'text-slate-300 hover:bg-slate-800/80'
                          }`}
                        >
                          <div className="flex items-center gap-2 min-w-0">
                            <span className="text-sm shrink-0">
                              {lesson.type === 'video'
                                ? '🎥'
                                : lesson.type === 'quiz'
                                ? '📝'
                                : lesson.type === 'assignment'
                                ? '🛠️'
                                : '📄'}
                            </span>
                            <span className="truncate text-[11px]">{lesson.title}</span>
                          </div>

                          <div className="flex items-center gap-1.5 shrink-0">
                            {isCompleted ? (
                              <span className="px-1.5 py-0.5 rounded-md bg-emerald-500/20 border border-emerald-500/40 text-emerald-400 text-[9px] font-black flex items-center gap-1">
                                <span>✓</span> Done
                              </span>
                            ) : isInProgress ? (
                              <span className="px-1.5 py-0.5 rounded-md bg-amber-500/20 border border-amber-500/40 text-amber-300 text-[9px] font-bold flex items-center gap-1">
                                <span>◐</span> {Math.round(lesson.progress_percentage || 0)}%
                              </span>
                            ) : (
                              <span className="px-1.5 py-0.5 rounded-md bg-slate-800 text-slate-400 text-[9px] font-medium flex items-center gap-1">
                                <span>○</span> Ready
                              </span>
                            )}
                          </div>
                        </button>
                      )
                    })}
                  </div>
                </div>
              )
            })}
          </div>
        </aside>
      </div>

      {/* 4. BOTTOM ACTION CONTROL BAR */}
      <footer className="bg-slate-950 border-t border-slate-800 p-4 sticky bottom-0 z-30">
        <div className="max-w-7xl mx-auto flex items-center justify-between gap-4">
          <button
            type="button"
            disabled={!prevItem}
            onClick={() => prevItem && handleLessonSelect(prevItem.lesson, prevItem.section)}
            className="px-4 py-2 rounded-xl text-xs font-bold bg-slate-800 text-slate-200 hover:bg-slate-700 disabled:opacity-30 disabled:cursor-not-allowed transition flex items-center gap-1.5"
          >
            <span>←</span> Previous
          </button>

          <button
            type="button"
            onClick={handleLessonComplete}
            disabled={completing}
            className={`px-6 py-2.5 rounded-xl text-xs font-extrabold transition shadow-md flex items-center gap-2 ${
              isCurrentCompleted
                ? 'bg-emerald-600 hover:bg-emerald-500 text-white'
                : 'bg-blue-600 hover:bg-blue-500 text-white'
            }`}
          >
            {isCurrentCompleted ? (
              <>
                <span>✓</span> Completed • Next Lesson →
              </>
            ) : (
              <>
                <span>✓</span> Mark Complete & Continue
              </>
            )}
          </button>

          <button
            type="button"
            disabled={!nextItem}
            onClick={() => nextItem && handleLessonSelect(nextItem.lesson, nextItem.section)}
            className="px-4 py-2 rounded-xl text-xs font-bold bg-slate-800 text-slate-200 hover:bg-slate-700 disabled:opacity-30 disabled:cursor-not-allowed transition flex items-center gap-1.5"
          >
            Next <span>→</span>
          </button>
        </div>
      </footer>
    </div>
  )
}
