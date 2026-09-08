import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import API from '../services/api'
import { useAuth } from '../context/useAuth'
import type { Course, CourseSection } from '../types/course'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import PublicAccessGateModal from '../components/PublicAccessGateModal'

export default function CourseDetails() {
  const { courseId } = useParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [course, setCourse] = useState<Course | null>(null)
  const [sections, setSections] = useState<CourseSection[]>([])
  const [loading, setLoading] = useState(true)
  const [enquiryOpen, setEnquiryOpen] = useState(false)
  const [error, setError] = useState('')
  const [expandedModules, setExpandedModules] = useState<Record<number, boolean>>({})

  const handleStartLearning = () => {
    if (!course) return
    if (course.is_enrolled) {
      navigate(`/student/courses/${course.id}/lessons`)
      return
    }
    if (user) {
      navigate(`/student/checkout/${course.id}`)
      return
    }
    setEnquiryOpen(true)
  }

  useEffect(() => {
    if (!courseId) return

    setLoading(true)
    API.get<Course>(`/courses/${courseId}`)
      .then((response) => {
        const c = response.data
        setCourse(c)
        if (c.sections && Array.isArray(c.sections)) {
          setSections(c.sections)
          // Open first two modules by default
          const initialExpanded: Record<number, boolean> = {}
          c.sections.forEach((s, idx) => {
            initialExpanded[s.id] = idx < 2
          })
          setExpandedModules(initialExpanded)
        } else {
          // Fetch sections if not embedded
          API.get<CourseSection[]>(`/courses/${c.id}/sections`)
            .then((secRes) => {
              const secList = Array.isArray(secRes.data) ? secRes.data : []
              setSections(secList)
              const initialExpanded: Record<number, boolean> = {}
              secList.forEach((s, idx) => {
                initialExpanded[s.id] = idx < 2
              })
              setExpandedModules(initialExpanded)
            })
            .catch(() => {})
        }
      })
      .catch((err) => {
        console.error(err)
        setError('Course not found or currently unavailable.')
      })
      .finally(() => setLoading(false))
  }, [courseId])

  const toggleModule = (id: number) => {
    setExpandedModules((prev) => ({
      ...prev,
      [id]: !prev[id],
    }))
  }

  const getLessonTypeIcon = (type?: string) => {
    switch (type) {
      case 'video':
        return '📹'
      case 'quiz':
        return '❓'
      case 'assignment':
        return '📝'
      case 'document':
        return '📑'
      default:
        return '📄'
    }
  }

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-500 selection:text-white">
      <Navbar />

      {loading ? (
        <main className="flex-grow flex items-center justify-center py-20">
          <div className="text-center space-y-3">
            <span className="animate-spin inline-block w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full" />
            <p className="text-sm font-semibold text-slate-600">Loading course curriculum from platform...</p>
          </div>
        </main>
      ) : error || !course ? (
        <main className="flex-grow max-w-4xl mx-auto px-4 py-16 w-full text-center">
          <div className="bg-white rounded-3xl p-12 border border-slate-200 shadow-xs space-y-4">
            <span className="text-4xl block">⚠️</span>
            <h2 className="text-xl font-bold text-slate-900">Unable to Load Program</h2>
            <p className="text-sm text-slate-600 max-w-md mx-auto">{error || 'Course does not exist.'}</p>
            <Link
              to="/courses"
              className="inline-block px-6 py-2.5 rounded-xl font-bold text-xs bg-blue-600 text-white hover:bg-blue-700 transition"
            >
              ← Back to Course Catalog
            </Link>
          </div>
        </main>
      ) : (
        <main className="flex-grow">
          {/* Hero Banner with Course Visuals */}
          <section className="relative bg-slate-950 text-white py-12 lg:py-16 overflow-hidden border-b border-slate-800">
            {course.thumbnail && (
              <img
                src={course.thumbnail}
                alt={course.title}
                className="absolute inset-0 w-full h-full object-cover opacity-20 filter blur-sm pointer-events-none"
              />
            )}
            <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/80 to-slate-950/40 pointer-events-none" />

            <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              {/* Breadcrumb */}
              <div className="flex items-center gap-2 text-xs font-semibold text-slate-400 mb-4">
                <Link to="/courses" className="hover:text-blue-400 transition">
                  Courses
                </Link>
                <span>/</span>
                <span className="text-blue-400">{course.category || 'Engineering'}</span>
                <span>/</span>
                <span className="text-slate-300 truncate max-w-xs">{course.title}</span>
              </div>

              <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
                <div className="lg:col-span-2 space-y-4">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="px-3 py-1 rounded-lg text-xs font-extrabold uppercase bg-blue-600/30 text-blue-400 border border-blue-500/30">
                      {course.category || 'Master Track'}
                    </span>
                    <span className="px-3 py-1 rounded-lg text-xs font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/30">
                      {course.difficulty || 'Intermediate'} Level
                    </span>
                    <span className="px-3 py-1 rounded-lg text-xs font-semibold bg-slate-800 text-slate-300 border border-slate-700">
                      ⏱️ {course.duration}
                    </span>
                  </div>

                  <h1 className="text-3xl sm:text-4xl lg:text-5xl font-black text-white tracking-tight leading-tight">
                    {course.title}
                  </h1>

                  <p className="text-sm sm:text-base text-slate-300 leading-relaxed">
                    {course.description}
                  </p>

                  <div className="flex flex-wrap items-center gap-6 pt-2 text-xs text-slate-400">
                    <div className="flex items-center gap-1.5 bg-slate-900/80 px-3 py-1.5 rounded-xl border border-slate-800">
                      <span className="text-amber-400 font-bold">★</span>
                      <span className="text-white font-bold">{course.average_rating || 4.9}</span>
                      <span>({course.reviews_count || 36} reviews)</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span>👥</span>
                      <span>{course.students_count || 450} Enrolled Learners</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span>👨‍🏫 Lead Faculty:</span>
                      <strong className="text-white">{course.instructor}</strong>
                    </div>
                  </div>
                </div>

                {/* Sticky Enrollment Card on Right */}
                <div className="bg-slate-900/90 backdrop-blur-md rounded-3xl p-6 sm:p-8 border border-slate-800 shadow-2xl space-y-6">
                  <div>
                    <span className="text-[10px] font-extrabold uppercase tracking-widest text-blue-400">
                      Program Access
                    </span>
                    <h3 className="text-xl font-bold text-white mt-1">Live Training & LMS Portal</h3>
                    <p className="text-xs text-slate-400 mt-1">
                      Includes 1-on-1 mentor guidance, production labs, and verified graduation credential.
                    </p>
                  </div>

                  <div className="space-y-2.5 text-xs text-slate-300 border-y border-slate-800 py-4">
                    <div className="flex items-center justify-between">
                      <span className="text-slate-400">Curriculum Depth:</span>
                      <span className="font-bold text-white">
                        {sections.length > 0 ? `${sections.length} Modules` : 'Full Capstone Track'}
                      </span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-slate-400">Interactive Lessons:</span>
                      <span className="font-bold text-white">
                        {course.lessons_count || sections.reduce((acc, s) => acc + (s.lessons?.length || 0), 0) || '30+'} Topics
                      </span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-slate-400">Learning Format:</span>
                      <span className="font-bold text-emerald-400">Live Mentorship + LMS</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-slate-400">Level Requirement:</span>
                      <span className="font-bold text-white">{course.difficulty || 'Intermediate'}</span>
                    </div>
                  </div>

                  {course.is_enrolled ? (
                    <div className="space-y-3">
                      <div className="p-3 bg-emerald-950/60 border border-emerald-500/30 rounded-xl text-emerald-300 text-xs font-semibold flex items-center gap-2">
                        <span>✓</span> You are actively enrolled in this program.
                      </div>
                      <button
                        type="button"
                        onClick={() => navigate(`/student/courses/${course.id}/lessons`)}
                        className="w-full py-3.5 px-4 rounded-2xl font-bold text-xs text-slate-950 bg-emerald-400 hover:bg-emerald-300 transition shadow-lg shadow-emerald-500/20 flex items-center justify-center gap-2"
                      >
                        <span>🚀</span> Enter Classroom
                      </button>
                    </div>
                  ) : (
                    <div className="space-y-3">
                      <button
                        type="button"
                        onClick={handleStartLearning}
                        className="w-full py-3.5 px-4 rounded-2xl font-bold text-xs text-white bg-blue-600 hover:bg-blue-700 transition shadow-lg shadow-blue-500/25 flex items-center justify-center gap-2"
                      >
                        <span>🚀</span> Start Learning / Enroll
                      </button>
                      <p className="text-[11px] text-center text-slate-400">
                        Zero commitment. Speak with faculty to review syllabus & prerequisites.
                      </p>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </section>

          {/* Main Details Body */}
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div className="lg:col-span-2 space-y-10">
              {/* 1. What You Will Learn */}
              {course.learning_objectives && course.learning_objectives.length > 0 && (
                <section className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xs space-y-4">
                  <h2 className="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <span>🎯</span> What You Will Learn
                  </h2>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                    {course.learning_objectives.map((obj, i) => (
                      <div key={i} className="flex items-start gap-2.5 text-xs text-slate-700 leading-relaxed">
                        <span className="text-emerald-600 font-extrabold text-sm">✓</span>
                        <span>{obj}</span>
                      </div>
                    ))}
                  </div>
                </section>
              )}

              {/* 2. Skills You Will Gain */}
              {course.skills_gained && course.skills_gained.length > 0 && (
                <section className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xs space-y-4">
                  <h2 className="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <span>⚡</span> Skills You Will Gain
                  </h2>
                  <div className="flex flex-wrap gap-2 pt-2">
                    {course.skills_gained.map((skill, i) => (
                      <span
                        key={i}
                        className="px-3 py-1.5 rounded-xl bg-blue-50 text-blue-800 text-xs font-bold border border-blue-200/60"
                      >
                        {skill}
                      </span>
                    ))}
                  </div>
                </section>
              )}

              {/* 3. Detailed Curriculum Syllabus */}
              <section className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xs space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-4">
                  <div>
                    <h2 className="text-lg font-bold text-slate-900 flex items-center gap-2">
                      <span>📚</span> Course Curriculum & Modules
                    </h2>
                    <p className="text-xs text-slate-500 mt-0.5">
                      {sections.length} structured modules • {course.lessons_count || '25+'} interactive learning sessions
                    </p>
                  </div>

                  <button
                    type="button"
                    onClick={() => {
                      const allOpen = Object.values(expandedModules).every(Boolean)
                      const nextState: Record<number, boolean> = {}
                      sections.forEach((s) => {
                        nextState[s.id] = !allOpen
                      })
                      setExpandedModules(nextState)
                    }}
                    className="text-xs font-bold text-blue-600 hover:text-blue-700 hover:underline"
                  >
                    {Object.values(expandedModules).every(Boolean) ? 'Collapse All' : 'Expand All'}
                  </button>
                </div>

                {sections.length === 0 ? (
                  <p className="text-xs text-slate-500 italic">Curriculum topics are being updated by faculty.</p>
                ) : (
                  <div className="space-y-3">
                    {sections.map((section, sIdx) => {
                      const isExpanded = expandedModules[section.id]
                      const lessons = section.lessons || []

                      return (
                        <div
                          key={section.id}
                          className="rounded-2xl border border-slate-200 overflow-hidden transition"
                        >
                          <button
                            type="button"
                            onClick={() => toggleModule(section.id)}
                            className="w-full p-4 sm:p-5 text-left bg-slate-50/80 hover:bg-slate-100/80 transition flex items-center justify-between gap-4"
                          >
                            <div className="flex items-center gap-3">
                              <span className="w-7 h-7 rounded-lg bg-blue-100 text-blue-700 font-extrabold text-xs flex items-center justify-center shrink-0">
                                {sIdx + 1}
                              </span>
                              <div>
                                <h3 className="text-xs sm:text-sm font-bold text-slate-900">
                                  {section.title}
                                </h3>
                                {section.description && (
                                  <p className="text-[11px] text-slate-500 line-clamp-1 mt-0.5">
                                    {section.description}
                                  </p>
                                )}
                              </div>
                            </div>

                            <div className="flex items-center gap-3 shrink-0 text-xs text-slate-500 font-medium">
                              <span>{lessons.length} topics</span>
                              <span className="text-base">{isExpanded ? '−' : '+'}</span>
                            </div>
                          </button>

                          {isExpanded && (
                            <div className="p-4 sm:p-5 bg-white space-y-2.5 border-t border-slate-200/80">
                              {lessons.length === 0 ? (
                                <p className="text-xs text-slate-400 italic">Module topics loading...</p>
                              ) : (
                                lessons.map((lesson) => (
                                  <div
                                    key={lesson.id}
                                    className="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-xs"
                                  >
                                    <div className="flex items-center gap-2.5 text-slate-800">
                                      <span>{getLessonTypeIcon(lesson.type)}</span>
                                      <span className="font-semibold">{lesson.title}</span>
                                    </div>
                                    <div className="flex items-center gap-2 text-[11px] text-slate-500">
                                      {lesson.duration && <span>⏱️ {lesson.duration}</span>}
                                    </div>
                                  </div>
                                ))
                              )}
                            </div>
                          )}
                        </div>
                      )
                    })}
                  </div>
                )}
              </section>

              {/* 4. Full Program Description */}
              {course.full_description && (
                <section className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xs space-y-4">
                  <h2 className="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <span>📖</span> Program Overview & Methodology
                  </h2>
                  <div className="text-xs sm:text-sm text-slate-700 leading-relaxed whitespace-pre-line space-y-3">
                    {course.full_description}
                  </div>
                </section>
              )}
            </div>

            {/* Sidebar Details: Prerequisites & Faculty */}
            <aside className="space-y-6">
              {/* Prerequisites Card */}
              {course.prerequisites && course.prerequisites.length > 0 && (
                <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-3">
                  <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                    <span>📋</span> Prerequisites
                  </h3>
                  <ul className="space-y-2 text-xs text-slate-700">
                    {course.prerequisites.map((req, i) => (
                      <li key={i} className="flex items-start gap-2">
                        <span className="text-blue-600 font-bold">•</span>
                        <span>{req}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* Lead Faculty Card */}
              <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500">
                  Faculty & Mentorship
                </h3>
                <div className="flex items-center gap-3">
                  <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 text-white font-black text-base flex items-center justify-center shadow-sm">
                    {course.instructor.charAt(0)}
                  </div>
                  <div>
                    <h4 className="text-sm font-extrabold text-slate-900">{course.instructor}</h4>
                    <p className="text-[11px] text-blue-600 font-semibold">Principal Instructor & Mentor</p>
                  </div>
                </div>
                <p className="text-xs text-slate-600 leading-relaxed">
                  Learn through live instructor-led masterclasses, direct Q&A office hours, and code-level architectural reviews.
                </p>
              </div>

              {/* Consultation Card */}
              <div className="bg-gradient-to-br from-slate-900 to-blue-950 text-white rounded-3xl p-6 space-y-3">
                <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-blue-500/20 text-blue-300 rounded border border-blue-500/30">
                  Direct Advisory
                </span>
                <h4 className="text-sm font-bold">Have Questions About This Track?</h4>
                <p className="text-xs text-slate-300 leading-relaxed">
                  Book a free live demo session or talk directly with our engineering academic counselors.
                </p>
                <button
                  type="button"
                  onClick={() => setEnquiryOpen(true)}
                  className="w-full py-2.5 px-4 rounded-xl font-bold text-xs text-slate-950 bg-white hover:bg-slate-100 transition shadow-sm"
                >
                  Book Free Demo
                </button>
              </div>
            </aside>
          </div>
        </main>
      )}

      <Footer />

      <PublicAccessGateModal
        isOpen={enquiryOpen}
        onClose={() => setEnquiryOpen(false)}
        courseId={course?.id}
        courseTitle={course?.title}
      />
    </div>
  )
}