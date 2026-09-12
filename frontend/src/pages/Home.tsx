import { useEffect, useState, useMemo } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import CourseCard from '../components/courses/CourseCard'
import EventCard from '../components/events/EventCard'
import PublicAccessGateModal from '../components/PublicAccessGateModal'
import type { Course } from '../types/course'
import type { Event } from '../types/event'

interface HomeSection {
  id?: number
  section_key: string
  title?: string | null
  subtitle?: string | null
  badge?: string | null
  content?: Record<string, unknown> | null
  is_enabled: boolean
}

interface CategoryItem {
  id?: number
  name: string
  slug: string
  icon?: string | null
}

interface LearningPathItem {
  id: number
  title: string
  slug: string
  description?: string | null
  icon?: string | null
  difficulty: string
  estimated_duration: string
  courses?: Course[]
}

interface TestimonialItem {
  id: number
  student_name: string
  student_role_or_company?: string | null
  course_title?: string | null
  rating: number
  content: string
}

interface FaqItem {
  id: number
  category: string
  question: string
  answer: string
}

export default function Home() {
  const { user } = useAuth()
  const navigate = useNavigate()

  // Dynamic Data States
  const [sections, setSections] = useState<Record<string, HomeSection>>({})
  const [categories, setCategories] = useState<CategoryItem[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [events, setEvents] = useState<Event[]>([])
  const [learningPaths, setLearningPaths] = useState<LearningPathItem[]>([])
  const [testimonials, setTestimonials] = useState<TestimonialItem[]>([])
  const [faqs, setFaqs] = useState<FaqItem[]>([])
  const [loadingCourses, setLoadingCourses] = useState(true)

  // Interactive UI States
  const [searchQuery, setSearchQuery] = useState('')
  const [activeCategoryTab, setActiveCategoryTab] = useState('All')
  const [visibleCount, setVisibleCount] = useState(12)
  const [enquiryOpen, setEnquiryOpen] = useState(false)

  useEffect(() => {
    // 1. Fetch Consolidated Home Payload
    API.get<{
      sections?: HomeSection[]
      categories?: CategoryItem[]
      featured_courses?: Course[]
      learning_paths?: LearningPathItem[]
      testimonials?: TestimonialItem[]
      events?: Event[]
      faqs?: FaqItem[]
    }>('/public/home')
      .then((res) => {
        const data = res.data || {}

        if (Array.isArray(data.sections)) {
          const map: Record<string, HomeSection> = {}
          data.sections.forEach((s) => {
            map[s.section_key] = s
          })
          setSections(map)
        }

        if (Array.isArray(data.categories) && data.categories.length > 0) {
          setCategories(data.categories)
        }

        if (Array.isArray(data.featured_courses) && data.featured_courses.length > 0) {
          setCourses(data.featured_courses)
        }

        if (Array.isArray(data.learning_paths)) {
          setLearningPaths(data.learning_paths)
        }

        if (Array.isArray(data.testimonials)) {
          setTestimonials(data.testimonials)
        }

        if (Array.isArray(data.events)) {
          setEvents(data.events)
        }

        if (Array.isArray(data.faqs)) {
          setFaqs(data.faqs)
        }
      })
      .catch(() => {
        // Fallback: Fetch from direct endpoints
        API.get('/courses')
          .then((cRes) => {
            const list = Array.isArray(cRes.data) ? cRes.data : cRes.data.data || []
            setCourses(list)
          })
          .catch(() => setCourses([]))

        API.get('/events/upcoming')
          .then((eRes) => {
            const list = Array.isArray(eRes.data) ? eRes.data : eRes.data.data || []
            setEvents(list.slice(0, 3))
          })
          .catch(() => setEvents([]))
      })
      .finally(() => setLoadingCourses(false))
  }, [])

  const handleHeroSearch = (e: React.FormEvent) => {
    e.preventDefault()
    if (searchQuery.trim()) {
      navigate(`/courses?search=${encodeURIComponent(searchQuery.trim())}`)
    } else {
      navigate('/courses')
    }
  }

  // Dynamic Tabs: Built from Database Categories + 'All'
  const categoryTabs = useMemo(() => {
    if (categories.length > 0) {
      return ['All', ...categories.map((c) => c.name)]
    }
    return [
      'All',
      'Artificial Intelligence',
      'Machine Learning',
      'Data Science',
      'Python with AI',
      'SAP',
      'Medical Coding',
      'Web Development',
      'Full Stack Development',
      'Cyber Security',
      'Cloud & DevOps',
      'Database & SQL',
    ]
  }, [categories])

  // Priority-sorted and Category-filtered courses
  const filteredCourses = useMemo(() => {
    const sorted = [...courses].sort((a, b) => {
      const pA = a.priority !== undefined && a.priority !== null ? a.priority : 100
      const pB = b.priority !== undefined && b.priority !== null ? b.priority : 100
      if (pA !== pB) return pA - pB
      return a.id - b.id
    })

    if (activeCategoryTab === 'All') {
      return sorted
    }

    const tabLower = activeCategoryTab.toLowerCase()

    return sorted.filter((c) => {
      const cat = (c.category || '').toLowerCase()
      const title = (c.title || '').toLowerCase()
      const slug = (c.slug || '').toLowerCase()

      return (
        cat.includes(tabLower) ||
        title.includes(tabLower) ||
        slug.includes(tabLower.replace(/[^a-z0-9]+/g, '-')) ||
        (tabLower.includes('ai') && (cat.includes('ai') || title.includes('intelligence') || title.includes('neural'))) ||
        (tabLower.includes('full stack') && (cat.includes('web') || title.includes('web') || title.includes('react'))) ||
        (tabLower.includes('cloud') && (cat.includes('devops') || title.includes('aws') || title.includes('docker')))
      )
    })
  }, [courses, activeCategoryTab])

  const displayedCourses = useMemo(() => {
    return filteredCourses.slice(0, visibleCount)
  }, [filteredCourses, visibleCount])

  // Helper to check if a section is enabled in CMS (defaults to true)
  const isEnabled = (key: string) => {
    if (sections[key] !== undefined) {
      return sections[key].is_enabled
    }
    return true
  }

  // Real lesson inventory derived from loaded catalog data — never a
  // hardcoded number. Zero/unknown renders a neutral label instead.
  const totalCatalogLessons = useMemo(
    () => courses.reduce((acc, c) => acc + (c.lessons_count || 0), 0),
    [courses]
  )

  const heroSec = sections['hero'] || {}
  const bannerSec = sections['banner'] || {}
  const ctaSec = sections['cta'] || {}
  const exploreSec = sections['explore_courses'] || {}
  const heroContent = (heroSec.content || {}) as Record<string, string>
  const bannerContent = (bannerSec.content || {}) as Record<string, string>
  const ctaContent = (ctaSec.content || {}) as Record<string, string>

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 flex flex-col">
      <Navbar />

      {/* 0. ANNOUNCEMENT BANNER (CMS Controlled) */}
      {isEnabled('banner') && (
        <div className="bg-gradient-to-r from-purple-700 via-indigo-700 to-blue-700 text-white text-xs py-2 px-4 text-center font-bold flex items-center justify-center gap-3">
          <span>{bannerSec.title || '🚀 Admissions Open for 2026 Batch — Limited 1-on-1 Mentorship Seats Available'}</span>
          <button
            type="button"
            onClick={() => setEnquiryOpen(true)}
            className="px-2.5 py-0.5 rounded-full bg-white/20 hover:bg-white/30 text-white text-[11px] underline uppercase tracking-wider transition"
          >
            {bannerContent.cta_text || 'Enquire Now'}
          </button>
        </div>
      )}

      {/* 1. HERO SECTION (CMS Controlled) */}
      {isEnabled('hero') && (
        <section className="bg-slate-900 text-white pt-16 pb-20 border-b border-slate-800 relative overflow-hidden">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
              {/* Left Column */}
              <div className="lg:col-span-7 text-left space-y-6">
                <div className="inline-flex items-center gap-2 px-3 py-1 rounded-md bg-blue-500/10 border border-blue-400/20 text-blue-400 text-xs font-bold tracking-wider uppercase">
                  {heroSec.badge || 'BUILD SKILLS. BUILD YOUR CAREER.'}
                </div>

                <h1 className="text-3xl sm:text-5xl lg:text-5xl font-black tracking-tight leading-tight text-white">
                  {heroSec.title || 'Learn Technology From Fundamentals to Advanced.'}
                </h1>

                <p className="text-sm sm:text-base text-slate-300 leading-relaxed max-w-2xl font-normal">
                  {heroSec.subtitle ||
                    'Master industry-relevant skills through structured courses, practical projects, quizzes, and guided learning across Full Stack, Artificial Intelligence, Cloud Computing, Database, Cyber Security, and Enterprise ERP.'}
                </p>

                {/* Search Bar */}
                <form onSubmit={handleHeroSearch} className="max-w-lg">
                  <div className="flex items-center bg-slate-800/90 border border-slate-700 rounded-xl p-1.5 focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500 transition">
                    <span className="pl-3 text-slate-400 text-sm">🔍</span>
                    <input
                      type="text"
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      placeholder="Search courses, skills, or certifications..."
                      className="w-full bg-transparent px-3 py-2 text-xs sm:text-sm text-white placeholder:text-slate-400 outline-none"
                    />
                    <button
                      type="submit"
                      className="rounded-lg bg-blue-600 px-4 py-2 text-xs font-bold text-white hover:bg-blue-500 transition shrink-0"
                    >
                      Search
                    </button>
                  </div>
                </form>

                {/* CTAs */}
                <div className="flex flex-wrap items-center gap-3 pt-2">
                  <Link
                    to={heroContent.primary_button_url || '/courses'}
                    className="px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold transition shadow-sm"
                  >
                    {heroContent.primary_button_text || 'Explore Courses'}
                  </Link>
                  {user ? (
                    <Link
                      to="/student"
                      className="px-5 py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-bold transition"
                    >
                      My Learning Dashboard
                    </Link>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setEnquiryOpen(true)}
                      className="px-5 py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-bold transition"
                    >
                      {heroContent.secondary_button_text || 'Enquire Now'}
                    </button>
                  )}
                </div>
              </div>

              {/* Right Column: Code Visual Preview */}
              <div className="lg:col-span-5">
                <div className="bg-slate-950 rounded-2xl border border-slate-800 p-5 shadow-2xl space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-800/80 pb-3">
                    <div className="flex items-center gap-1.5">
                      <span className="w-2.5 h-2.5 rounded-full bg-red-500/80" />
                      <span className="w-2.5 h-2.5 rounded-full bg-amber-500/80" />
                      <span className="w-2.5 h-2.5 rounded-full bg-emerald-500/80" />
                    </div>
                    <span className="text-[11px] font-mono text-slate-400">masterintech-classroom.ts</span>
                  </div>

                  <div className="font-mono text-xs text-slate-300 space-y-1 bg-slate-900/60 p-3.5 rounded-xl border border-slate-800">
                    <p className="text-slate-500">// 1. Progression: Basic → Intermediate → Advanced</p>
                    <p><span className="text-blue-400">const</span> track = <span className="text-amber-300">'Full Stack & AI'</span>;</p>
                    <p><span className="text-blue-400">await</span> classroom.<span className="text-sky-300">completeLesson</span>(lessonId);</p>
                    <p><span className="text-blue-400">const</span> progress = <span className="text-emerald-400">100</span>; <span className="text-slate-500">// Verified</span></p>
                  </div>

                  <div className="grid grid-cols-2 gap-2.5 pt-1">
                    <div className="p-2.5 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-2">
                      <span className="text-base">🎯</span>
                      <div>
                        <p className="text-xs font-bold text-white">
                          {totalCatalogLessons > 0 ? `${totalCatalogLessons.toLocaleString()}+ Lessons` : 'Structured Lessons'}
                        </p>
                        <p className="text-[10px] text-slate-400">Database-Driven</p>
                      </div>
                    </div>
                    <div className="p-2.5 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-2">
                      <span className="text-base">🏆</span>
                      <div>
                        <p className="text-xs font-bold text-white">Certificates</p>
                        <p className="text-[10px] text-slate-400">Verifiable Code</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      )}

      {/* 2. VALUE / TRUST STRIP */}
      {isEnabled('stats') && (
        <section className="bg-white border-b border-slate-200 py-8">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
              <div className="flex items-start gap-3">
                <div className="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-sm shrink-0 border border-blue-100">
                  📚
                </div>
                <div>
                  <h4 className="text-xs font-bold text-slate-900">Structured Learning</h4>
                  <p className="text-[11px] text-slate-500 mt-0.5">Foundations to advanced tracks</p>
                </div>
              </div>

              <div className="flex items-start gap-3">
                <div className="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-sm shrink-0 border border-emerald-100">
                  🛠️
                </div>
                <div>
                  <h4 className="text-xs font-bold text-slate-900">Practical Projects</h4>
                  <p className="text-[11px] text-slate-500 mt-0.5">Hands-on capstones & code</p>
                </div>
              </div>

              <div className="flex items-start gap-3">
                <div className="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-sm shrink-0 border border-indigo-100">
                  📊
                </div>
                <div>
                  <h4 className="text-xs font-bold text-slate-900">Progress Tracking</h4>
                  <p className="text-[11px] text-slate-500 mt-0.5">Checkpoints, quizzes & notes</p>
                </div>
              </div>

              <div className="flex items-start gap-3">
                <div className="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-sm shrink-0 border border-amber-100">
                  🎓
                </div>
                <div>
                  <h4 className="text-xs font-bold text-slate-900">Verified Certificates</h4>
                  <p className="text-[11px] text-slate-500 mt-0.5">Official completion proof</p>
                </div>
              </div>
            </div>
          </div>
        </section>
      )}

      {/* 3. EXPLORE COURSES (CMS Controlled) */}
      {isEnabled('explore_courses') && (
        <section className="py-14 bg-slate-50">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="flex flex-col md:flex-row md:items-end justify-between mb-8 gap-4">
              <div>
                <h2 className="text-2xl font-black text-slate-900 tracking-tight">
                  {exploreSec.title || 'Explore Our Courses'}
                </h2>
                <p className="text-xs sm:text-sm text-slate-600 mt-1">
                  {exploreSec.subtitle ||
                    'Build skills from fundamentals to advanced expertise across major technical fields.'}
                </p>
              </div>
              <Link
                to="/courses"
                className="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1 shrink-0"
              >
                Browse All Courses →
              </Link>
            </div>

            {/* Dynamic Category Tabs */}
            <div className="flex items-center gap-2 overflow-x-auto pb-3 mb-6 no-scrollbar">
              {categoryTabs.map((tab) => (
                <button
                  key={tab}
                  type="button"
                  onClick={() => {
                    setActiveCategoryTab(tab)
                    setVisibleCount(12)
                  }}
                  className={`px-3.5 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition border cursor-pointer ${
                    activeCategoryTab === tab
                      ? 'bg-blue-600 text-white border-blue-600 shadow-xs ring-1 ring-blue-500/20'
                      : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-100 hover:text-slate-900'
                  }`}
                >
                  {tab}
                </button>
              ))}
            </div>

            {/* Course Grid */}
            {loadingCourses ? (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {[1, 2, 3, 4, 5, 6].map((n) => (
                  <div key={n} className="h-80 rounded-xl bg-white border border-slate-200 p-4 animate-pulse space-y-4">
                    <div className="h-40 bg-slate-100 rounded-lg" />
                    <div className="h-4 bg-slate-100 rounded w-3/4" />
                    <div className="h-3 bg-slate-100 rounded w-1/2" />
                  </div>
                ))}
              </div>
            ) : filteredCourses.length === 0 ? (
              <div className="text-center py-12 bg-white rounded-xl border border-slate-200">
                <p className="text-xs text-slate-500">No courses available in this category at the moment.</p>
                <Link to="/courses" className="text-xs font-bold text-blue-600 mt-2 inline-block">
                  View All Courses
                </Link>
              </div>
            ) : (
              <>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                  {displayedCourses.map((course) => (
                    <CourseCard key={course.id} course={course} />
                  ))}
                </div>

                {filteredCourses.length > visibleCount && (
                  <div className="mt-10 text-center flex flex-col sm:flex-row items-center justify-center gap-3">
                    <button
                      type="button"
                      onClick={() => setVisibleCount((prev) => prev + 12)}
                      className="px-6 py-2.5 rounded-xl bg-white hover:bg-slate-50 text-slate-800 border border-slate-300 text-xs font-bold transition shadow-xs hover:border-slate-400 cursor-pointer"
                    >
                      Load More Courses ({filteredCourses.length - visibleCount} remaining)
                    </button>
                    <Link
                      to="/courses"
                      className="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold transition shadow-xs"
                    >
                      Browse All Courses ({courses.length}) →
                    </Link>
                  </div>
                )}
              </>
            )}
          </div>
        </section>
      )}

      {/* 4. LEARNING PATHS ROADMAPS (CMS Controlled) */}
      {isEnabled('learning_paths') && learningPaths.length > 0 && (
        <section className="py-14 bg-white border-t border-slate-200">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="text-center max-w-2xl mx-auto mb-10">
              <span className="text-xs font-extrabold uppercase tracking-widest text-blue-600">
                Career Specializations
              </span>
              <h2 className="text-2xl font-black text-slate-900 tracking-tight mt-1">
                Structured Engineering Roadmaps
              </h2>
              <p className="text-xs sm:text-sm text-slate-600 mt-1">
                Follow guided multi-course pathways designed to take you from core syntax to staff architect level.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {learningPaths.map((lp) => (
                <div
                  key={lp.id}
                  className="rounded-3xl bg-slate-50 border border-slate-200 p-6 space-y-4 hover:shadow-lg transition flex flex-col justify-between"
                >
                  <div className="space-y-3">
                    <div className="w-12 h-12 rounded-2xl bg-blue-100 text-blue-700 flex items-center justify-center text-2xl">
                      {lp.icon || '🗺️'}
                    </div>
                    <h3 className="font-bold text-slate-900 text-base">{lp.title}</h3>
                    <p className="text-xs text-slate-600 leading-relaxed">{lp.description}</p>
                  </div>

                  <div className="pt-3 border-t border-slate-200 text-xs text-slate-500 flex items-center justify-between">
                    <span>⏱️ {lp.estimated_duration}</span>
                    <span>📊 {lp.difficulty}</span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* 5. TESTIMONIALS (CMS Controlled) */}
      {isEnabled('testimonials') && testimonials.length > 0 && (
        <section className="py-14 bg-slate-900 text-white border-t border-slate-800">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="text-center max-w-2xl mx-auto mb-10">
              <span className="text-xs font-extrabold uppercase tracking-widest text-blue-400">
                Alumni Reviews
              </span>
              <h2 className="text-2xl font-black text-white tracking-tight mt-1">
                Student Stories & Transformations
              </h2>
              <p className="text-xs sm:text-sm text-slate-300 mt-1">
                Hear directly from graduates who transitioned into top engineering roles worldwide.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {testimonials.map((t) => (
                <div
                  key={t.id}
                  className="rounded-2xl bg-slate-950 border border-slate-800 p-6 space-y-3 flex flex-col justify-between"
                >
                  <div className="space-y-2">
                    <div className="text-yellow-400 text-sm">{'★'.repeat(t.rating)}</div>
                    <p className="text-xs text-slate-300 italic leading-relaxed">
                      "{t.content}"
                    </p>
                  </div>

                  <div className="pt-3 border-t border-slate-800">
                    <h4 className="font-bold text-white text-xs">{t.student_name}</h4>
                    <p className="text-[11px] text-purple-400">{t.student_role_or_company}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* 6. UPCOMING MASTERCLASSES / EVENTS */}
      {isEnabled('events') && events.length > 0 && (
        <section className="py-12 bg-slate-50 border-t border-slate-200">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="flex items-center justify-between mb-6">
              <div>
                <h2 className="text-xl font-bold text-slate-900">Live Masterclasses & Workshops</h2>
                <p className="text-xs text-slate-500 mt-0.5">Join interactive sessions with industry experts.</p>
              </div>
              <Link to="/events" className="text-xs font-bold text-blue-600 hover:text-blue-700">
                View All Events →
              </Link>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {events.map((event) => (
                <EventCard key={event.id} event={event} />
              ))}
            </div>
          </div>
        </section>
      )}

      {/* 7. FAQS PREVIEW */}
      {isEnabled('faq') && faqs.length > 0 && (
        <section className="py-14 bg-white border-t border-slate-200">
          <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="text-center mb-8">
              <h2 className="text-2xl font-black text-slate-900">Frequently Asked Questions</h2>
              <p className="text-xs text-slate-500 mt-1">Quick answers to common student inquiries.</p>
            </div>

            <div className="space-y-3">
              {faqs.slice(0, 4).map((f) => (
                <details
                  key={f.id}
                  className="group bg-slate-50 rounded-2xl p-4 border border-slate-200 list-none"
                >
                  <summary className="font-bold text-xs text-slate-900 cursor-pointer flex justify-between items-center">
                    <span>{f.question}</span>
                    <span className="text-blue-600 text-base font-bold group-open:rotate-45 transition-transform">+</span>
                  </summary>
                  <p className="text-xs text-slate-600 mt-2 pt-2 border-t border-slate-200 leading-relaxed">
                    {f.answer}
                  </p>
                </details>
              ))}
            </div>

            <div className="text-center mt-6">
              <Link to="/faq" className="text-xs font-bold text-blue-600 hover:underline">
                View All Questions & Helpdesk →
              </Link>
            </div>
          </div>
        </section>
      )}

      {/* 8. ADMISSIONS CTA BANNER (CMS Controlled) */}
      {isEnabled('cta') && (
        <section className="py-14 bg-gradient-to-r from-blue-700 to-indigo-800 text-white text-center">
          <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <h2 className="text-2xl sm:text-3xl font-black">
              {ctaSec.title || 'Ready to Build Your Engineering Career?'}
            </h2>
            <p className="text-xs sm:text-sm text-blue-100 max-w-xl mx-auto leading-relaxed">
              {ctaSec.subtitle ||
                'Speak with our academic faculty advisors to select the right specialization for your goals.'}
            </p>
            <div className="pt-2">
              <button
                type="button"
                onClick={() => setEnquiryOpen(true)}
                className="px-6 py-3 rounded-xl font-bold bg-white text-blue-800 hover:bg-slate-100 text-xs shadow-lg transition"
              >
                {ctaContent.button_text || 'Submit Course Enquiry →'}
              </button>
            </div>
          </div>
        </section>
      )}

      {/* Public Enquiry Modal */}
      <PublicAccessGateModal
        isOpen={enquiryOpen}
        onClose={() => setEnquiryOpen(false)}
      />

      <Footer />
    </div>
  )
}
