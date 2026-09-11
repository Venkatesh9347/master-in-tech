import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import API from '../services/api'
import { useAuth } from '../context/useAuth'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import CourseReviews from '../components/lms/CourseReviews'
import PublicAccessGateModal from '../components/PublicAccessGateModal'
import type { Course } from '../types/course'
import type { Section } from '../types/lms'

interface CourseDetailData extends Omit<Course, 'sections'> {
  sections?: Section[]
  sections_count?: number
  lessons_count?: number
  is_enrolled?: boolean
}

interface PaymentOrderPayload {
  provider: string
  order_id: string
  payment_id: string
  amount: number
  currency: string
  status: string
  reused: boolean
}

interface CreateOrderResponse {
  message: string
  order: PaymentOrderPayload
  key_id: string | null
}

type PayPhase = 'idle' | 'ordering' | 'ready' | 'verifying'

function loadRazorpayCheckout(): Promise<void> {
  if (typeof (window as unknown as { Razorpay?: unknown }).Razorpay !== 'undefined') return Promise.resolve()
  return new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = 'https://checkout.razorpay.com/v1/checkout.js'
    script.async = true
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('Failed to load Razorpay checkout.'))
    document.body.appendChild(script)
  })
}

export default function CourseCatalogDetails() {
  const { id } = useParams<{ id: string }>()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [course, setCourse] = useState<CourseDetailData | null>(null)
  const [sections, setSections] = useState<Section[]>([])
  const [loading, setLoading] = useState(true)
  const [enquiryOpen, setEnquiryOpen] = useState(false)
  const [error, setError] = useState('')
  const [openSections, setOpenSections] = useState<Record<number, boolean>>({})
  const [actionError, setActionError] = useState('')
  const [payPhase, setPayPhase] = useState<PayPhase>('idle')
  const [payError, setPayError] = useState('')
  const [payOrder, setPayOrder] = useState<PaymentOrderPayload | null>(null)

  const handleStartLearning = () => {
    if (!course) return
    if (course.is_enrolled) {
      navigate(`/student/courses/${course.id}/lessons`)
      return
    }
    if (user) {
      setActionError('')
      API.post(`/courses/${course.id}/enroll`)
        .then(() => {
          navigate(`/student/courses/${course.id}/lessons`)
        })
        .catch((err: unknown) => {
          // Self-enrollment is admin-managed (server responds 403): route to
          // counselling enquiry. Any other failure is a real error and must
          // be shown as such — never disguised as an enquiry flow.
          const status = (err as { response?: { status?: number } })?.response?.status
          if (status === 403) {
            setEnquiryOpen(true)
          } else if (status === undefined) {
            setActionError('Network error. Please check your connection and try again.')
          } else {
            setActionError(`Something went wrong (${status}). Please try again later.`)
          }
        })
      return
    }
    setEnquiryOpen(true)
  }

  const pollEnrollmentThenEnter = async (courseId: number) => {
    setPayPhase('verifying')
    // Webhook fulfilment is server-side and near-instant; poll briefly.
    for (let attempt = 0; attempt < 15; attempt++) {
      await new Promise((r) => setTimeout(r, 2000))
      try {
        const res = await API.get<{ enrolled: boolean; enrollment?: { status: string } }>(
          `/courses/${courseId}/enrollment`
        )
        if (res.data?.enrolled && res.data.enrollment?.status === 'active') {
          navigate(`/student/courses/${courseId}/lessons`)
          return
        }
      } catch {
        // Keep polling until the attempts run out.
      }
    }
    setPayPhase('ready')
    setPayError('Payment received but enrollment is taking longer than expected. It will appear under My Courses shortly — or contact support.')
  }

  const openRazorpayCheckout = async (order: PaymentOrderPayload, keyId: string, courseId: number, courseTitle: string) => {
    try {
      await loadRazorpayCheckout()
    } catch {
      setPayPhase('ready')
      setPayError('Could not load the payment gateway. Please check your connection and try again.')
      return
    }
    const RazorpayCtor = (window as unknown as {
      Razorpay?: new (opts: Record<string, unknown>) => { open(): void }
    }).Razorpay
    if (!RazorpayCtor) {
      setPayPhase('ready')
      setPayError('Payment gateway failed to initialise. Please try again.')
      return
    }
    const rzp = new RazorpayCtor({
      key: keyId,
      order_id: order.order_id,
      amount: order.amount,
      currency: order.currency,
      name: 'MasterInTech',
      description: `Enrollment: ${courseTitle}`,
      theme: { color: '#2563eb' },
      // Gateway verification + enrollment happen server-side via webhook;
      // the handler only waits for that fulfilment to land.
      handler: () => {
        void pollEnrollmentThenEnter(courseId)
      },
      modal: {
        ondismiss: () => {
          setPayPhase('ready')
          setPayError('Payment was not completed. No amount was charged.')
        },
      },
    })
    rzp.open()
  }

  const handlePayOnline = async () => {
    if (!course || payPhase === 'ordering' || payPhase === 'verifying') return
    if (!user) {
      setEnquiryOpen(true)
      return
    }
    setPayError('')
    setPayPhase('ordering')
    try {
      const res = await API.post<CreateOrderResponse>('/payments/order', {
        course_id: course.id,
      })
      const order = res.data.order
      setPayOrder(order)
      if (order.provider === 'razorpay' && res.data.key_id) {
        await openRazorpayCheckout(order, res.data.key_id, course.id, course.title)
      } else {
        // No live gateway in this environment: stay on an explicit
        // ready state. Dev builds offer a stub-callback simulator below;
        // production builds direct the learner to counselling instead.
        setPayPhase('ready')
      }
    } catch (err: unknown) {
      const response = (err as { response?: { status?: number; data?: { message?: string } } })?.response
      const status = response?.status
      setPayPhase('idle')
      if (status === 409) {
        navigate(`/student/courses/${course.id}/lessons`)
      } else if (status === 503) {
        setPayError('Online payments are not enabled yet. Please use Enquire Now and our team will help you enroll.')
      } else if (status === undefined) {
        setPayError('Network error. Please check your connection and try again.')
      } else {
        setPayError(response?.data?.message || `Something went wrong (${status}). Please try again later.`)
      }
    }
  }

  // DEV-ONLY test affordance (stripped from production builds): drive the
  // real stub-gateway webhook path so the full order -> webhook ->
  // enrollment chain is exercisable locally without Razorpay credentials.
  // It never fabricates enrollment — fulfilment runs server-side.
  const handleSimulateGatewayCallback = async () => {
    if (!course || !payOrder) return
    setPayError('')
    try {
      await API.post('/payments/razorpay/webhook', {
        event: 'payment.captured',
        payload: { payment: { entity: { id: payOrder.payment_id } } },
      }, {
        headers: { 'X-Razorpay-Signature': 'dev-stub-callback' },
      })
      await pollEnrollmentThenEnter(course.id)
    } catch {
      setPayPhase('ready')
      setPayError('Test callback failed. Is the stub payment provider active?')
    }
  }

  useEffect(() => {
    if (!id) {
      setError('Course not found.')
      setLoading(false)
      return
    }

    setLoading(true)
    API.get<CourseDetailData>(`/courses/${id}`)
      .then((courseRes) => {
        const courseData = courseRes.data
        setCourse(courseData)
        const embeddedSections = courseData.sections
        if (Array.isArray(embeddedSections) && embeddedSections.length > 0) {
          setSections(embeddedSections)
          setOpenSections({ [embeddedSections[0].id]: true })
          setLoading(false)
        } else {
          // Lazy fallback only if sections not embedded
          API.get<Section[]>(`/courses/${id}/sections`)
            .then((secRes) => {
              const secs = Array.isArray(secRes.data) ? secRes.data : []
              setSections(secs)
              if (secs.length > 0) {
                setOpenSections({ [secs[0].id]: true })
              }
            })
            .finally(() => setLoading(false))
        }
      })
      .catch(() => {
        // Fallback for catalog list if single route fails
        API.get('/courses')
          .then((res) => {
            const courses: CourseDetailData[] = Array.isArray(res.data)
              ? (res.data as CourseDetailData[])
              : ((res.data as { data?: CourseDetailData[] })?.data || [])
            const found =
              courses.find((c) => String(c.id) === id) ||
              courses.find((c) => c.slug === id)
            if (found) {
              setCourse(found)
            } else {
              setError('Course not found.')
            }
          })
          .catch(() => setError('Unable to load this course right now.'))
      })
      .finally(() => setLoading(false))
  }, [id])

  const toggleSection = (secId: number) => {
    setOpenSections((prev) => ({
      ...prev,
      [secId]: !prev[secId],
    }))
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex flex-col">
        <Navbar />
        <div className="flex-grow flex items-center justify-center py-24">
          <div className="flex items-center gap-3 text-slate-600 font-semibold">
            <span className="animate-spin inline-block w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full" />
            Loading course syllabus and curriculum...
          </div>
        </div>
        <Footer />
      </div>
    )
  }

  if (error || !course) {
    return (
      <div className="min-h-screen bg-slate-50 flex flex-col">
        <Navbar />
        <div className="flex-grow flex items-center justify-center py-20 px-4">
          <div className="text-center bg-white p-10 rounded-3xl border border-slate-200 shadow-sm max-w-md">
            <span className="text-4xl mb-3 block">⚠️</span>
            <h2 className="text-xl font-bold text-slate-900 mb-2">Course Unavailable</h2>
            <p className="text-sm text-slate-500 mb-6">
              {error || 'The requested course could not be loaded.'}
            </p>
            <Link
              to="/courses"
              className="inline-block rounded-xl bg-blue-600 px-6 py-2.5 text-xs font-bold text-white hover:bg-blue-700 transition shadow-sm"
            >
              Browse All Courses
            </Link>
          </div>
        </div>
        <Footer />
      </div>
    )
  }

  const totalLessons = sections.reduce((acc, s) => acc + (s.lessons?.length || 0), 0) || course.lessons_count || 0

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-500 selection:text-white">
      <Navbar />

      {/* 1. HERO BANNER */}
      <section className="bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 text-white py-14 border-b border-slate-800">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center gap-2 text-xs font-semibold text-slate-400 mb-4">
            <Link to="/" className="hover:text-white">Home</Link>
            <span>/</span>
            <Link to="/courses" className="hover:text-white">Courses</Link>
            <span>/</span>
            <span className="text-blue-400 truncate max-w-xs">{course.title}</span>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-10">
            {/* Left Main Details */}
            <div className="lg:col-span-2 space-y-4">
              <div className="flex flex-wrap items-center gap-2.5">
                <span className="bg-blue-600/30 border border-blue-400/30 text-blue-300 text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider">
                  {course.category || 'Tech Program'}
                </span>
                <span className="bg-slate-800 text-slate-300 text-xs font-bold px-3 py-1 rounded-full border border-slate-700">
                  {course.difficulty} Level
                </span>
                <span className="bg-emerald-950/60 border border-emerald-800 text-emerald-400 text-xs font-bold px-3 py-1 rounded-full">
                  Accredited Certificate Included
                </span>
              </div>

              <h1 className="text-3xl sm:text-4xl lg:text-5xl font-black text-white tracking-tight leading-tight">
                {course.title}
              </h1>

              <p className="text-base sm:text-lg text-slate-300 leading-relaxed font-normal">
                {course.description}
              </p>

              {/* Stats Row */}
              <div className="flex flex-wrap items-center gap-6 pt-3 text-xs sm:text-sm text-slate-300">
                {course.average_rating ? (
                  <div className="flex items-center gap-1.5 bg-amber-400/10 border border-amber-400/30 px-3 py-1 rounded-xl text-amber-300">
                    <span className="font-bold">★ {course.average_rating}</span>
                    <span className="text-amber-400/70">({course.reviews_count ?? 0} ratings)</span>
                  </div>
                ) : (
                  <div className="flex items-center gap-1.5 bg-amber-400/10 border border-amber-400/30 px-3 py-1 rounded-xl text-amber-300">
                    <span className="font-bold">New Program</span>
                  </div>
                )}
                <div className="flex items-center gap-1.5">
                  <span>⏱️</span>
                  <span>{course.duration}</span>
                </div>
                <div className="flex items-center gap-1.5">
                  <span>📖</span>
                  <span>{totalLessons} Lessons</span>
                </div>
                {!!course.students_count && (
                  <div className="flex items-center gap-1.5">
                    <span>👥</span>
                    <span>{(course.students_count).toLocaleString()} Enrolled</span>
                  </div>
                )}
              </div>

              {/* Instructor Byline */}
              <div className="flex items-center gap-3 pt-2">
                <div className="w-10 h-10 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-white text-sm shadow-xs">
                  {course.instructor ? course.instructor.charAt(0).toUpperCase() : 'M'}
                </div>
                <div>
                  <p className="text-xs text-slate-400">Created by Senior Faculty</p>
                  <p className="text-sm font-bold text-white">{course.instructor}</p>
                </div>
              </div>
            </div>

            {/* Right Sticky Enrollment Card */}
            <div className="lg:col-span-1">
              <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-7 shadow-2xl text-white sticky top-24">
                {/* Course Preview Box */}
                <div className="w-full aspect-video rounded-2xl bg-gradient-to-br from-blue-900 to-indigo-950 border border-slate-700/60 mb-5 flex flex-col items-center justify-center text-center p-4 relative overflow-hidden group">
                  {course.thumbnail && (
                    <img
                      src={course.thumbnail}
                      alt={course.title}
                      className="absolute inset-0 w-full h-full object-cover opacity-40 group-hover:scale-105 transition-transform duration-300"
                      onError={(e) => {
                        e.currentTarget.style.display = 'none'
                      }}
                    />
                  )}
                  <span className="w-12 h-12 rounded-full bg-blue-600/90 text-white text-xl font-bold flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform relative z-10">
                    ▶
                  </span>
                  <span className="text-xs font-bold text-white/90 mt-2 relative z-10">Course Preview Available</span>
                </div>

                <div className="mb-5 pb-5 border-b border-slate-800 flex items-baseline justify-between">
                  <div>
                    <span className="text-xs text-slate-400 block font-bold uppercase tracking-wider">Admissions & Counseling</span>
                    <span className="text-2xl font-black text-white">
                      {course.is_enrolled ? (
                        <span className="text-emerald-400 text-2xl font-black">Enrolled ✓</span>
                      ) : (
                        'Free Live Demo'
                      )}
                    </span>
                  </div>

                  {course.is_enrolled ? (
                    <span className="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-xs font-bold px-2.5 py-1 rounded-full">
                      Active Student
                    </span>
                  ) : (
                    <span className="bg-blue-500/20 text-blue-400 border border-blue-500/30 text-xs font-bold px-2.5 py-1 rounded-full">
                      1-on-1 Guidance
                    </span>
                  )}
                </div>

                {/* Main Action Button */}
                {course.is_enrolled ? (
                  <button
                    type="button"
                    onClick={() => navigate(`/student/courses/${course.id}/lessons`)}
                    className="w-full py-3.5 px-4 rounded-xl font-bold text-sm text-white bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 shadow-md shadow-emerald-500/20 transition flex items-center justify-center gap-2"
                  >
                    <span>🚀</span> Continue Learning
                  </button>
                ) : (
                  <div className="space-y-3">
                    <button
                      type="button"
                      onClick={handleStartLearning}
                      className="w-full py-3.5 px-4 rounded-xl font-bold text-sm text-white bg-blue-600 hover:bg-blue-500 active:bg-blue-700 shadow-md shadow-blue-500/20 transition flex items-center justify-center gap-2"
                    >
                      <span>🚀</span> Start Learning / Enroll
                    </button>
                    {actionError && (
                      <p className="text-[11px] text-center text-red-400 bg-red-500/10 border border-red-500/30 rounded-lg px-3 py-2">
                        {actionError}
                      </p>
                    )}
                    <button
                      type="button"
                      onClick={handlePayOnline}
                      disabled={payPhase === 'ordering' || payPhase === 'verifying'}
                      className="w-full py-2.5 px-4 rounded-xl font-bold text-xs text-emerald-300 bg-emerald-950/60 hover:bg-emerald-900/60 border border-emerald-800 transition flex items-center justify-center gap-1.5 disabled:opacity-50"
                    >
                      <span>💳</span>
                      {payPhase === 'ordering'
                        ? 'Creating secure order…'
                        : payPhase === 'verifying'
                          ? 'Verifying payment…'
                          : 'Pay & Enroll Online'}
                    </button>
                    {payError && (
                      <p className="text-[11px] text-center text-red-400 bg-red-500/10 border border-red-500/30 rounded-lg px-3 py-2">
                        {payError}
                      </p>
                    )}
                    {import.meta.env.DEV && payPhase === 'ready' && payOrder && payOrder.provider !== 'razorpay' && (
                      <div className="rounded-lg border border-dashed border-slate-600 px-3 py-2.5 text-[11px] text-slate-300 space-y-2">
                        <p className="font-bold text-slate-200">
                          Test mode — order {payOrder.order_id} ({(payOrder.amount / 100).toLocaleString()} {payOrder.currency})
                        </p>
                        <p>No live gateway here. Simulate the gateway callback to run the real webhook → enrollment path:</p>
                        <button
                          type="button"
                          onClick={handleSimulateGatewayCallback}
                          className="w-full py-1.5 px-3 rounded-lg text-[11px] font-bold bg-slate-700 hover:bg-slate-600 text-white transition"
                        >
                          Simulate gateway callback (dev only)
                        </button>
                      </div>
                    )}
                    <button
                      type="button"
                      onClick={() => setEnquiryOpen(true)}
                      className="w-full py-2.5 px-4 rounded-xl font-bold text-xs text-slate-300 bg-slate-800 hover:bg-slate-700 border border-slate-700 transition flex items-center justify-center gap-1.5"
                    >
                      <span>💬</span> Enquire Now & Get Syllabus
                    </button>

                    {course.brochure && (
                      <a
                        href={course.brochure}
                        download={`${course.title.replace(/[^a-zA-Z0-9_-]/g, '_')}_Brochure.pdf`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="w-full py-2 px-4 rounded-xl font-bold text-xs text-blue-300 bg-blue-950/60 hover:bg-blue-900/80 border border-blue-800/80 transition flex items-center justify-center gap-1.5"
                      >
                        <span>📥</span> Download Course Brochure (PDF)
                      </a>
                    )}
                  </div>
                )}

                <ul className="mt-6 space-y-2.5 text-xs text-slate-300">
                  <li className="flex items-center gap-2.5">
                    <span className="text-emerald-400">✓</span> Full Lifetime Access to All Modules & Lessons
                  </li>
                  <li className="flex items-center gap-2.5">
                    <span className="text-emerald-400">✓</span> Hands-on Quizzes & Real-World Capstones
                  </li>
                  <li className="flex items-center gap-2.5">
                    <span className="text-emerald-400">✓</span> Verifiable Master In Tech Industry Certificate
                  </li>
                  <li className="flex items-center gap-2.5">
                    <span className="text-emerald-400">✓</span> 1-on-1 Faculty Mentorship & Placement Support
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* 2. SYLLABUS, REQUIREMENTS & REVIEWS */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 flex-grow w-full">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-10">
          {/* Main 2-column Content */}
          <div className="lg:col-span-2 space-y-12">
            {/* What you'll learn */}
            {course.learning_objectives && course.learning_objectives.length > 0 ? (
              <section className="bg-white rounded-3xl p-7 sm:p-8 border border-slate-200/80 shadow-xs">
                <h2 className="text-xl font-extrabold text-slate-900 mb-6 flex items-center gap-2.5">
                  <span>🎯</span> What You Will Learn in this Program
                </h2>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs sm:text-sm text-slate-700">
                  {course.learning_objectives.map((outcome, idx) => (
                    <div key={idx} className="flex items-start gap-2.5">
                      <span className="text-emerald-600 font-bold text-base mt-[-2px]">✓</span>
                      <span>{outcome}</span>
                    </div>
                  ))}
                </div>
              </section>
            ) : null}

            {/* Skills You Will Gain */}
            {course.skills_gained && course.skills_gained.length > 0 ? (
              <section className="bg-white rounded-3xl p-7 sm:p-8 border border-slate-200/80 shadow-xs">
                <h2 className="text-xl font-extrabold text-slate-900 mb-4 flex items-center gap-2.5">
                  <span>⚡</span> Skills You Will Gain
                </h2>
                <div className="flex flex-wrap gap-2 pt-1">
                  {course.skills_gained.map((skill, idx) => (
                    <span
                      key={idx}
                      className="px-3 py-1.5 rounded-xl bg-blue-50 text-blue-800 text-xs font-bold border border-blue-200/60"
                    >
                      {skill}
                    </span>
                  ))}
                </div>
              </section>
            ) : null}

            {/* Requirements Section */}
            {course.prerequisites && course.prerequisites.length > 0 ? (
              <section className="bg-white rounded-3xl p-7 sm:p-8 border border-slate-200/80 shadow-xs">
                <h2 className="text-xl font-extrabold text-slate-900 mb-4 flex items-center gap-2.5">
                  <span>📋</span> Requirements & Prerequisites
                </h2>
                <ul className="space-y-2 text-xs sm:text-sm text-slate-600">
                  {course.prerequisites.map((req, idx) => (
                    <li key={idx} className="flex items-start gap-2">
                      <span className="text-blue-600 font-bold">•</span>
                      <span>{req}</span>
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}

            {/* Full Description Section */}
            {course.full_description ? (
              <section className="bg-white rounded-3xl p-7 sm:p-8 border border-slate-200/80 shadow-xs">
                <h2 className="text-xl font-extrabold text-slate-900 mb-4 flex items-center gap-2.5">
                  <span>📖</span> Detailed Course Overview
                </h2>
                <div className="text-xs sm:text-sm text-slate-700 leading-relaxed whitespace-pre-line space-y-3">
                  {course.full_description}
                </div>
              </section>
            ) : null}

            {/* Curriculum Accordion */}
            <section className="bg-white rounded-3xl p-7 sm:p-8 border border-slate-200/80 shadow-xs">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                  <h2 className="text-xl font-extrabold text-slate-900 flex items-center gap-2.5">
                    <span>📚</span> Full Course Curriculum & Syllabus
                  </h2>
                  <p className="text-xs text-slate-500 mt-1">
                    {sections.length} Modules • {totalLessons} Lessons • {course.duration}
                  </p>
                </div>

                {sections.length > 0 && (
                  <button
                    type="button"
                    onClick={() => {
                      const allOpen = Object.keys(openSections).length === sections.length
                      if (allOpen) {
                        setOpenSections({})
                      } else {
                        const newOpen: Record<number, boolean> = {}
                        sections.forEach((s) => (newOpen[s.id] = true))
                        setOpenSections(newOpen)
                      }
                    }}
                    className="text-xs font-bold text-blue-600 hover:text-blue-800 transition"
                  >
                    {Object.keys(openSections).length === sections.length ? 'Collapse All' : 'Expand All'}
                  </button>
                )}
              </div>

              {sections.length === 0 ? (
                <div className="p-8 text-center bg-slate-50 rounded-2xl border border-slate-100">
                  <p className="text-sm font-bold text-slate-700">Course lessons ready in classroom</p>
                  <p className="text-xs text-slate-500 mt-1">
                    Video lessons, interactive quizzes, and capstone challenges are accessible upon enrollment.
                  </p>
                </div>
              ) : (
                <div className="space-y-4">
                  {sections.map((sec, sIdx) => {
                    const isOpen = openSections[sec.id]
                    const lessons = sec.lessons || []
                    return (
                      <div
                        key={sec.id}
                        className="rounded-2xl border border-slate-200/80 overflow-hidden transition"
                      >
                        {/* Section Header */}
                        <button
                          type="button"
                          onClick={() => toggleSection(sec.id)}
                          className="w-full p-4 sm:p-5 bg-slate-50 hover:bg-slate-100/80 flex items-center justify-between text-left transition"
                        >
                          <div className="flex items-center gap-3">
                            <span className="w-7 h-7 rounded-lg bg-blue-100 text-blue-700 font-bold text-xs flex items-center justify-center">
                              {sIdx + 1}
                            </span>
                            <div>
                              <h4 className="text-sm font-bold text-slate-900">{sec.title}</h4>
                              <p className="text-[11px] text-slate-500">{lessons.length} lessons in this module</p>
                            </div>
                          </div>
                          <span className="text-xs font-bold text-slate-400">
                            {isOpen ? '▲' : '▼'}
                          </span>
                        </button>

                        {/* Section Lessons List */}
                        {isOpen && (
                          <div className="p-4 bg-white border-t border-slate-200/80 divide-y divide-slate-100">
                            {lessons.length === 0 ? (
                              <p className="text-xs text-slate-400 py-2">Lessons being configured.</p>
                            ) : (
                              lessons.map((les, lIdx) => (
                                <div
                                  key={les.id}
                                  className="py-3 flex items-center justify-between gap-4 text-xs hover:text-blue-600 transition"
                                >
                                  <div className="flex items-center gap-3">
                                    <span className="text-sm">
                                      {les.type === 'video' ? '🎥' : les.type === 'quiz' ? '📝' : les.type === 'assignment' ? '🛠️' : '📄'}
                                    </span>
                                    <div>
                                      <span className="font-semibold text-slate-800">
                                        {sIdx + 1}.{lIdx + 1} {les.title}
                                      </span>
                                      <span className="ml-2 px-1.5 py-0.5 rounded text-[10px] uppercase font-bold bg-slate-100 text-slate-500">
                                        {les.type}
                                      </span>
                                    </div>
                                  </div>
                                  <span className="text-[11px] text-slate-400 font-medium whitespace-nowrap">
                                    {les.duration || '10 min'}
                                  </span>
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

            {/* Course Reviews */}
            <section className="bg-white rounded-3xl p-7 sm:p-8 border border-slate-200/80 shadow-xs">
              <CourseReviews courseId={course.id} isEnrolled={Boolean(course.is_enrolled)} />
            </section>

            {/* Course Specific FAQ */}
            <section className="bg-white rounded-3xl p-7 sm:p-8 border border-slate-200/80 shadow-xs space-y-4">
              <h2 className="text-xl font-extrabold text-slate-900 flex items-center gap-2.5">
                <span>❓</span> Course Frequently Asked Questions
              </h2>
              <div className="space-y-3 pt-2">
                {[
                  {
                    q: 'How long do I have access to this course?',
                    a: 'You receive permanent lifetime access to all lessons, quizzes, resources, and future material updates.',
                  },
                  {
                    q: 'Will I get an accredited certificate?',
                    a: 'Yes, completing all modules, quizzes, and assignments generates a verifiable Master In Tech certificate.',
                  },
                  {
                    q: 'Can I ask instructor questions if I get stuck?',
                    a: 'Yes, our classroom player features a built-in Q&A discussion tab where instructors and teaching assistants answer queries.',
                  },
                ].map((faq, idx) => (
                  <details
                    key={idx}
                    className="group rounded-2xl bg-slate-50 p-4 border border-slate-200 open:bg-white open:ring-2 open:ring-blue-500/20 transition"
                  >
                    <summary className="flex cursor-pointer items-center justify-between font-bold text-xs sm:text-sm text-slate-900 list-none">
                      <span>{faq.q}</span>
                      <span className="text-blue-600 font-bold group-open:rotate-45 transition-transform duration-200">+</span>
                    </summary>
                    <p className="mt-2 text-xs text-slate-600 pt-2 border-t border-slate-200/60 leading-relaxed">
                      {faq.a}
                    </p>
                  </details>
                ))}
              </div>
            </section>
          </div>

          {/* Right Sidebar */}
          <div className="lg:col-span-1 space-y-6">
            {/* Instructor Card */}
            <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs">
              <div className="flex items-center gap-4 mb-4">
                <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white font-black text-xl flex items-center justify-center shadow-md shrink-0">
                  {course.instructor ? course.instructor.charAt(0).toUpperCase() : 'M'}
                </div>
                <div>
                  <h3 className="text-base font-bold text-slate-900">{course.instructor}</h3>
                  <p className="text-xs font-semibold text-blue-600">Senior Engineering Faculty</p>
                </div>
              </div>
              <p className="text-xs text-slate-600 leading-relaxed">
                Dedicated mentor with extensive industry engineering experience, committed to training engineers with real production standards.
              </p>
            </div>

            {/* Certificate Preview Card */}
            <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs">
              <span className="text-3xl mb-3 block">🏆</span>
              <h3 className="text-base font-extrabold text-slate-900">
                Verifiable Certificate
              </h3>
              <p className="text-xs text-slate-600 mt-1 leading-relaxed">
                Earn an accredited digital certificate with a public verification URL to showcase on LinkedIn and to hiring managers.
              </p>
              <div className="mt-4 pt-3 border-t border-slate-100 text-xs font-bold text-blue-600 flex items-center justify-between">
                <span>Tamper-Proof Code</span>
                <span>✓ Included</span>
              </div>
            </div>
          </div>
        </div>
      </main>

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
