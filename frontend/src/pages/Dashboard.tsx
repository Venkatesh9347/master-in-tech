import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import API from '../services/api'
import ImageUploadField from '../components/admin/ImageUploadField'
import BrochureUploadField from '../components/admin/BrochureUploadField'

interface DashboardStatistics {
  total_students: number
  active_students: number
  total_tutors: number
  active_tutors: number
  total_admins?: number
  total_courses: number
  published_courses: number
  new_enquiries: number
  todays_demos: number
  pending_admissions: number
  active_enrollments: number
  certificates_issued?: number
  pending_assignments?: number
}

interface AdmissionsPipeline {
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

interface RecentEnquiry {
  id: number
  name: string
  email: string
  phone: string
  course_id?: number | null
  course_title?: string | null
  preferred_time?: string | null
  status: string
  created_at: string
}

interface TodaysDemo {
  id: number
  name: string
  email: string
  phone: string
  course_title?: string | null
  demo_date?: string | null
  demo_time?: string | null
  demo_outcome?: string | null
  assigned_agent?: string | null
  status: string
}

interface RecentAdmission {
  id: number
  name: string
  email: string
  phone: string
  course_title?: string | null
  status: string
  enrolled_at?: string | null
  updated_at: string
}

interface CourseOverviewItem {
  id: number
  title: string
  category: string
  instructor: string
  instructor_id?: number | null
  status: string
  is_published: boolean
  enrollments_count: number
  sections_count: number
  lessons_count: number
  duration: string
  difficulty: string
  description?: string
  average_rating: number
  internal_price?: number
  thumbnail?: string | null
  banner?: string | null
  brochure?: string | null
  media_id?: number | null
  brochure_media_id?: number | null
}

interface SystemActivityItem {
  event: string
  description: string
  user: string
  status?: string
  timestamp: string
  icon: string
}

interface AssignableInstructor {
  id: number
  name: string
  email: string
  role: string
}

interface DashboardData {
  statistics: DashboardStatistics
  admissions_pipeline: AdmissionsPipeline
  recent_enquiries: RecentEnquiry[]
  todays_demos: TodaysDemo[]
  recent_admissions: RecentAdmission[]
  courses_overview: CourseOverviewItem[]
  student_activity: {
    recent_enrollments: Array<{
      type: string
      student_name: string
      student_email?: string
      course_title: string
      timestamp: string
    }>
    recent_certificates: Array<{
      type: string
      student_name: string
      course_title: string
      certificate_code: string
      timestamp: string
    }>
  }
  tutor_activity: Array<{
    id: number
    name: string
    email: string
    taught_courses_count: number
  }>
  system_activity: SystemActivityItem[]
}

const statusBadgeStyles: Record<string, string> = {
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

export default function Dashboard() {
  const navigate = useNavigate()
  const [data, setData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  // Create Course Modal State
  const [showCourseModal, setShowCourseModal] = useState(false)
  const [courseForm, setCourseForm] = useState({
    title: '',
    description: '',
    category: 'Full Stack Development',
    instructor: 'Lead Faculty',
    instructor_id: '',
    duration: '10 weeks',
    difficulty: 'Beginner',
    price: '24999',
    thumbnail: '',
    banner: '',
    brochure: '',
    media_id: undefined as number | undefined,
    brochure_media_id: undefined as number | undefined,
  })
  const [creatingCourse, setCreatingCourse] = useState(false)

  // Edit Course Modal State
  const [editingCourse, setEditingCourse] = useState<CourseOverviewItem | null>(null)
  const [editCourseForm, setEditCourseForm] = useState({
    title: '',
    description: '',
    category: 'Full Stack Development',
    instructor: '',
    instructor_id: '',
    duration: '10 weeks',
    difficulty: 'Beginner',
    price: '24999',
    is_published: true,
    thumbnail: '',
    banner: '',
    brochure: '',
    media_id: undefined as number | undefined,
    brochure_media_id: undefined as number | undefined,
  })
  const [savingCourseEdit, setSavingCourseEdit] = useState(false)
  const [courseActionMsg, setCourseActionMsg] = useState('')
  const [courseError, setCourseError] = useState('')

  const apiErrorMessage = (err: unknown): string => {
    const maybe = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    if (maybe.response?.data?.message) return maybe.response.data.message
    const firstFieldError = maybe.response?.data?.errors ? Object.values(maybe.response.data.errors)[0]?.[0] : undefined
    if (firstFieldError) return firstFieldError
    return 'Something went wrong. Please try again.'
  }

  // Instructors eligible to own courses (tutors + faculty)
  const [assignableInstructors, setAssignableInstructors] = useState<AssignableInstructor[]>([])

  const loadDashboard = () => {
    setLoading(true)
    API.get<DashboardData>('/admin/dashboard')
      .then((res) => {
        setData(res.data)
      })
      .catch(() => {
        setError('Failed to load administrative operations overview.')
      })
      .finally(() => setLoading(false))

    // Tutors + faculty are eligible to own courses. Load both so the
    // course create/edit forms can assign a real instructor account.
    Promise.all([
      API.get<AssignableInstructor[]>('/admin/users?role=tutor'),
      API.get<AssignableInstructor[]>('/admin/users?role=faculty'),
    ])
      .then(([tutors, faculty]) => {
        const merged = [...(Array.isArray(tutors.data) ? tutors.data : []), ...(Array.isArray(faculty.data) ? faculty.data : [])]
        const seen = new Set<number>()
        setAssignableInstructors(merged.filter((u) => (seen.has(u.id) ? false : (seen.add(u.id), true))))
      })
      .catch(() => {
        setAssignableInstructors([])
      })
  }

  useEffect(() => {
    loadDashboard()
  }, [])

  const handleCreateCourse = async (e: React.FormEvent) => {
    e.preventDefault()
    setCreatingCourse(true)
    setCourseActionMsg('')
    setCourseError('')
    try {
      await API.post('/courses', {
        ...courseForm,
        instructor_id: courseForm.instructor_id ? Number(courseForm.instructor_id) : undefined,
        price: Number(courseForm.price) || 0,
      })
      setCourseActionMsg('Course created successfully!')
      setShowCourseModal(false)
      setCourseForm({
        title: '',
        description: '',
        category: 'Full Stack Development',
        instructor: 'Lead Faculty',
        instructor_id: '',
        duration: '10 weeks',
        difficulty: 'Beginner',
        price: '24999',
        thumbnail: '',
        banner: '',
        brochure: '',
        media_id: undefined,
        brochure_media_id: undefined,
      })
      loadDashboard()
    } catch (err) {
      setCourseError(apiErrorMessage(err))
    } finally {
      setCreatingCourse(false)
    }
  }

  const openEditCourseModal = (course: CourseOverviewItem) => {
    setEditingCourse(course)
    setEditCourseForm({
      title: course.title,
      description: course.description || '',
      category: course.category || 'Full Stack Development',
      instructor: course.instructor,
      instructor_id: course.instructor_id ? String(course.instructor_id) : '',
      duration: course.duration || '10 weeks',
      difficulty: course.difficulty || 'Beginner',
      price: String(course.internal_price || '24999'),
      is_published: course.is_published,
      thumbnail: course.thumbnail || '',
      banner: course.banner || course.thumbnail || '',
      brochure: course.brochure || '',
      media_id: course.media_id ? Number(course.media_id) : undefined,
      brochure_media_id: course.brochure_media_id ? Number(course.brochure_media_id) : undefined,
    })
    setCourseActionMsg('')
    setCourseError('')
  }

  const handleSaveCourseEdit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!editingCourse) return
    setSavingCourseEdit(true)
    setCourseActionMsg('')
    setCourseError('')

    try {
      await API.put(`/courses/${editingCourse.id}`, {
        ...editCourseForm,
        instructor_id: editCourseForm.instructor_id ? Number(editCourseForm.instructor_id) : undefined,
        price: Number(editCourseForm.price) || 0,
        is_published: editCourseForm.is_published,
      })
      setCourseActionMsg(`Course '${editCourseForm.title}' updated successfully.`)
      setEditingCourse(null)
      loadDashboard()
    } catch (err) {
      setCourseError(apiErrorMessage(err))
    } finally {
      setSavingCourseEdit(false)
    }
  }

  const handleTogglePublish = async (course: CourseOverviewItem) => {
    const nextPublished = !course.is_published
    try {
      await API.put(`/courses/${course.id}`, {
        is_published: nextPublished,
        status: nextPublished ? 'published' : 'draft',
      })
      setCourseActionMsg(`Course '${course.title}' is now ${nextPublished ? 'Published' : 'Unpublished (Draft)'}.`)
      loadDashboard()
    } catch {
      setError('Failed to toggle course publication status.')
    }
  }

  const handleDeleteCourse = async (course: CourseOverviewItem) => {
    if (!window.confirm(`Are you sure you want to delete '${course.title}'? This action cannot be undone.`)) {
      return
    }

    try {
      await API.delete(`/courses/${course.id}`)
      setCourseActionMsg(`Course '${course.title}' has been deleted.`)
      loadDashboard()
    } catch {
      setError('Failed to delete course.')
    }
  }

  if (loading && !data) {
    return (
      <div className="py-24 text-center text-slate-400 font-semibold text-xs">
        <span className="animate-spin inline-block w-6 h-6 border-2 border-purple-500 border-t-transparent rounded-full mb-3" />
        <p>Loading Master In Tech Operations Dashboard...</p>
      </div>
    )
  }

  if (error && !data) {
    return (
      <div className="p-6 bg-red-950/80 border border-red-800 text-red-300 rounded-3xl text-xs font-bold text-center">
        ⚠️ {error}
        <button
          type="button"
          onClick={loadDashboard}
          className="block mx-auto mt-3 px-4 py-2 bg-red-800 text-white rounded-xl"
        >
          Retry
        </button>
      </div>
    )
  }

  const stats = data?.statistics || {
    total_students: 0,
    active_students: 0,
    total_tutors: 0,
    active_tutors: 0,
    total_admins: 0,
    total_courses: 0,
    published_courses: 0,
    new_enquiries: 0,
    todays_demos: 0,
    pending_admissions: 0,
    active_enrollments: 0,
  }

  const pipeline = data?.admissions_pipeline || {
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
  }

  return (
    <div className="space-y-8">
      {/* Header & Status Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <span className="text-[10px] font-black tracking-widest text-purple-400 uppercase">
            Operations & LMS Command Center
          </span>
          <h1 className="text-2xl sm:text-3xl font-black text-white mt-1">Admin Operations Dashboard</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Real-time admissions funnel, student engagement, faculty activity, and platform governance.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={loadDashboard}
            className="px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-800 hover:bg-slate-900 text-xs font-bold text-slate-300 transition flex items-center gap-1.5"
          >
            <span>🔄</span> Refresh Data
          </button>
        </div>
      </div>

      {courseActionMsg && (
        <div className="p-4 bg-emerald-950/80 border border-emerald-800 text-emerald-300 rounded-2xl text-xs font-bold flex items-center justify-between">
          <span>✓ {courseActionMsg}</span>
          <button type="button" onClick={() => setCourseActionMsg('')} className="text-emerald-400 font-bold">
            ×
          </button>
        </div>
      )}

      {/* 1. TOP KPI STATISTICS CARDS */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5">
        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">Total Students</span>
          <p className="text-2xl font-black text-white mt-1">{stats.total_students}</p>
          <span className="text-[10px] text-emerald-400 font-semibold mt-1 block">
            ● {stats.active_students} Active Learners
          </span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">Faculty Tutors</span>
          <p className="text-2xl font-black text-purple-400 mt-1">{stats.total_tutors}</p>
          <span className="text-[10px] text-purple-300 font-semibold mt-1 block">
            ● {stats.active_tutors} Teaching Instructors
          </span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">Administrators</span>
          <p className="text-2xl font-black text-indigo-400 mt-1">{stats.total_admins || 1}</p>
          <span className="text-[10px] text-indigo-300 font-semibold mt-1 block">
            ● Security & Governance
          </span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">Total Courses</span>
          <p className="text-2xl font-black text-blue-400 mt-1">{stats.total_courses}</p>
          <span className="text-[10px] text-blue-300 font-semibold mt-1 block">
            ● {stats.published_courses} Published Live
          </span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">New Enquiries</span>
          <p className="text-2xl font-black text-amber-400 mt-1">{stats.new_enquiries}</p>
          <span className="text-[10px] text-amber-300 font-semibold mt-1 block">
            ● Awaiting First Contact
          </span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">Today's Demos</span>
          <p className="text-2xl font-black text-cyan-400 mt-1">{stats.todays_demos}</p>
          <span className="text-[10px] text-cyan-300 font-semibold mt-1 block">
            ● Live 1-on-1 Sessions
          </span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">Pending Admissions</span>
          <p className="text-2xl font-black text-teal-400 mt-1">{stats.pending_admissions}</p>
          <span className="text-[10px] text-teal-300 font-semibold mt-1 block">
            ● Confirmed Candidates
          </span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">Total Enrollments</span>
          <p className="text-2xl font-black text-emerald-400 mt-1">{stats.active_enrollments}</p>
          <span className="text-[10px] text-emerald-300 font-semibold mt-1 block">
            ● LMS Classroom Passes
          </span>
        </div>

        <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 shadow-xs">
          <span className="text-xs font-bold text-slate-400 block">Certificates Issued</span>
          <p className="text-2xl font-black text-amber-300 mt-1">{stats.certificates_issued || 0}</p>
          <span className="text-[10px] text-slate-400 font-semibold mt-1 block">
            ● Tamper-Proof Verified
          </span>
        </div>

        <div className="bg-gradient-to-br from-purple-900 to-indigo-950 p-4 rounded-2xl border border-purple-700 shadow-xs flex flex-col justify-between">
          <span className="text-[11px] font-black uppercase text-purple-200 block">Admissions Quick Desk</span>
          <Link
            to="/admin/enquiries"
            className="mt-2 py-1.5 px-3 rounded-xl bg-white text-purple-900 text-xs font-black text-center hover:bg-purple-50 transition"
          >
            Open Lead Desk →
          </Link>
        </div>
      </div>

      {/* 2. ADMISSIONS PIPELINE STAGES SUMMARY */}
      <div className="bg-slate-950 p-6 rounded-3xl border border-slate-800 space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-base font-black text-white">Admissions Funnel & Stage Summary</h2>
            <p className="text-xs text-slate-400">Click any stage to filter prospective students in the pipeline.</p>
          </div>
          <Link to="/admin/enquiries" className="text-xs font-bold text-purple-400 hover:underline">
            Manage Pipeline Desk →
          </Link>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2.5">
          {[
            { label: 'New', count: pipeline.new, status: 'new', color: 'text-blue-400' },
            { label: 'Contacted', count: pipeline.contacted, status: 'contacted', color: 'text-amber-400' },
            { label: 'Demo Sched', count: pipeline.demo_scheduled, status: 'demo_scheduled', color: 'text-purple-400' },
            { label: 'Demo Done', count: pipeline.demo_completed, status: 'demo_completed', color: 'text-indigo-400' },
            { label: 'Interested', count: pipeline.interested, status: 'interested', color: 'text-cyan-400' },
            { label: 'Follow-up', count: pipeline.follow_up, status: 'follow_up', color: 'text-orange-400' },
            { label: 'Adm Confirmed', count: pipeline.admission_confirmed, status: 'admission_confirmed', color: 'text-teal-400' },
            { label: 'Enrolled', count: pipeline.enrolled, status: 'enrolled', color: 'text-emerald-400' },
          ].map((stage) => (
            <button
              key={stage.label}
              type="button"
              onClick={() => navigate(`/admin/enquiries?status=${stage.status}`)}
              className="p-3 bg-slate-900 hover:bg-slate-850 rounded-2xl border border-slate-800 text-left transition group"
            >
              <span className="text-[10px] font-bold uppercase text-slate-400 block group-hover:text-slate-200">
                {stage.label}
              </span>
              <span className={`text-lg font-black mt-0.5 block ${stage.color}`}>{stage.count}</span>
            </button>
          ))}
        </div>
      </div>

      {/* 3. QUICK ACTIONS BAR */}
      <div className="bg-slate-950 p-5 rounded-3xl border border-slate-800">
        <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-400 mb-3">
          Administrative Quick Actions
        </h3>
        <div className="flex flex-wrap items-center gap-2.5">
          <Link
            to="/admin/users"
            className="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5"
          >
            <span>👥</span> + Create Tutor Account
          </Link>
          <button
            type="button"
            onClick={() => setShowCourseModal(true)}
            className="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5"
          >
            <span>📚</span> + Create Course
          </button>
          <Link
            to="/admin/enquiries"
            className="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-200 text-xs font-bold border border-slate-700 transition flex items-center gap-1.5"
          >
            <span>📬</span> View Enquiries & Leads
          </Link>
          <Link
            to="/admin/events/new"
            className="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-200 text-xs font-bold border border-slate-700 transition flex items-center gap-1.5"
          >
            <span>📅</span> + Schedule Event
          </Link>
          <Link
            to="/admin/submissions"
            className="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-200 text-xs font-bold border border-slate-700 transition flex items-center gap-1.5"
          >
            <span>📝</span> Grading Desk ({stats.pending_assignments || 0})
          </Link>
          <Link
            to="/admin/enrollments"
            className="px-4 py-2 rounded-xl bg-purple-950/80 hover:bg-purple-900 text-purple-300 hover:text-white text-xs font-bold border border-purple-800 transition flex items-center gap-1.5"
          >
            <span>🎓</span> Student Enrollments
          </Link>
          <Link
            to="/admin/batches"
            className="px-4 py-2 rounded-xl bg-purple-950/80 hover:bg-purple-900 text-purple-300 hover:text-white text-xs font-bold border border-purple-800 transition flex items-center gap-1.5"
          >
            <span>📦</span> Batch Management
          </Link>
          <Link
            to="/admin/users"
            className="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-200 text-xs font-bold border border-slate-700 transition flex items-center gap-1.5"
          >
            <span>⚙️</span> Manage All Users
          </Link>
        </div>
      </div>

      {/* 4. MAIN TWO-COLUMN OPERATIONAL GRID */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
        {/* Left 2 Columns */}
        <div className="lg:col-span-2 space-y-8">
          {/* Today's & Upcoming Demos */}
          <div className="bg-slate-950 p-6 rounded-3xl border border-slate-800 space-y-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <span className="w-2.5 h-2.5 rounded-full bg-cyan-400 animate-pulse" />
                <h2 className="text-base font-black text-white">Today's & Scheduled Demos</h2>
              </div>
              <Link to="/admin/enquiries?status=demo_scheduled" className="text-xs font-bold text-cyan-400 hover:underline">
                View All Demos →
              </Link>
            </div>

            {data?.todays_demos && data.todays_demos.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b border-slate-800 text-slate-400 font-bold uppercase">
                      <th className="py-2.5 px-3">Student</th>
                      <th className="py-2.5 px-3">Program</th>
                      <th className="py-2.5 px-3">Time & Slot</th>
                      <th className="py-2.5 px-3">Assigned Counselor</th>
                      <th className="py-2.5 px-3 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {data.todays_demos.map((demo) => (
                      <tr key={demo.id} className="hover:bg-slate-900/60 transition">
                        <td className="py-3 px-3">
                          <p className="font-bold text-white">{demo.name}</p>
                          <p className="text-[10px] text-slate-400">{demo.phone}</p>
                        </td>
                        <td className="py-3 px-3 text-slate-300 font-semibold">{demo.course_title || 'General'}</td>
                        <td className="py-3 px-3">
                          <span className="text-purple-300 font-bold">
                            {demo.demo_date ? new Date(demo.demo_date).toLocaleDateString() : 'Pending'}
                          </span>
                          <span className="text-[10px] text-slate-400 block">{demo.demo_time || 'Slot TBA'}</span>
                        </td>
                        <td className="py-3 px-3 text-slate-400">{demo.assigned_agent || 'Unassigned'}</td>
                        <td className="py-3 px-3 text-right">
                          <Link
                            to="/admin/enquiries"
                            className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-[11px] border border-slate-700"
                          >
                            View Demo
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="text-xs text-slate-500 py-4 text-center">No demos scheduled for today.</p>
            )}
          </div>

          {/* Recent Inquiries */}
          <div className="bg-slate-950 p-6 rounded-3xl border border-slate-800 space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-base font-black text-white">Recent Course Enquiries</h2>
              <Link to="/admin/enquiries" className="text-xs font-bold text-purple-400 hover:underline">
                Open All Enquiries →
              </Link>
            </div>

            {data?.recent_enquiries && data.recent_enquiries.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b border-slate-800 text-slate-400 font-bold uppercase">
                      <th className="py-2.5 px-3">Student</th>
                      <th className="py-2.5 px-3">Program</th>
                      <th className="py-2.5 px-3">Preferred Slot</th>
                      <th className="py-2.5 px-3">Status</th>
                      <th className="py-2.5 px-3 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {data.recent_enquiries.map((enq) => (
                      <tr key={enq.id} className="hover:bg-slate-900/60 transition">
                        <td className="py-3 px-3">
                          <p className="font-bold text-white">{enq.name}</p>
                          <p className="text-[10px] text-slate-400">{enq.email}</p>
                        </td>
                        <td className="py-3 px-3 text-slate-300 font-semibold">{enq.course_title || 'General'}</td>
                        <td className="py-3 px-3 text-slate-400">{enq.preferred_time || 'Flexible'}</td>
                        <td className="py-3 px-3">
                          <span
                            className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${
                              statusBadgeStyles[enq.status] || statusBadgeStyles.new
                            }`}
                          >
                            {enq.status.replace('_', ' ')}
                          </span>
                        </td>
                        <td className="py-3 px-3 text-right">
                          <Link
                            to="/admin/enquiries"
                            className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-[11px] border border-slate-700"
                          >
                            View Lead
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="text-xs text-slate-500 py-4 text-center">No recent inquiries.</p>
            )}
          </div>

          {/* Courses Overview & Management */}
          <div className="bg-slate-950 p-6 rounded-3xl border border-slate-800 space-y-4">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-base font-black text-white">Course Portfolio & Curriculum Roster</h2>
                <p className="text-xs text-slate-400">Manage tracks, instructors, publication, and internal pricing.</p>
              </div>
              <button
                type="button"
                onClick={() => setShowCourseModal(true)}
                className="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-bold transition"
              >
                + New Course
              </button>
            </div>

            {data?.courses_overview && data.courses_overview.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b border-slate-800 text-slate-400 font-bold uppercase">
                      <th className="py-2.5 px-3">Course Track</th>
                      <th className="py-2.5 px-3">Instructor</th>
                      <th className="py-2.5 px-3">Status</th>
                      <th className="py-2.5 px-3">Internal Price</th>
                      <th className="py-2.5 px-3">Enrolled</th>
                      <th className="py-2.5 px-3">Structure</th>
                      <th className="py-2.5 px-3 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {data.courses_overview.map((c) => (
                      <tr key={c.id} className="hover:bg-slate-900/60 transition">
                        <td className="py-3 px-3 max-w-xs">
                          <p className="font-bold text-white truncate">{c.title}</p>
                          <span className="text-[10px] text-blue-400 font-semibold">{c.category} • {c.difficulty}</span>
                        </td>
                        <td className="py-3 px-3 text-slate-300">{c.instructor}</td>
                        <td className="py-3 px-3">
                          <span
                            className={`px-2 py-0.5 rounded-md text-[10px] font-bold capitalize ${
                              c.is_published ? 'bg-emerald-950 text-emerald-300' : 'bg-amber-950 text-amber-300'
                            }`}
                          >
                            {c.is_published ? 'Published' : 'Draft'}
                          </span>
                        </td>
                        <td className="py-3 px-3 font-semibold text-slate-200">
                          ₹{c.internal_price ? c.internal_price.toLocaleString() : '0'}
                          <span className="block text-[9px] text-slate-500">Admin only</span>
                        </td>
                        <td className="py-3 px-3 font-bold text-white">{c.enrollments_count}</td>
                        <td className="py-3 px-3 text-slate-400">
                          {c.sections_count} sec • {c.lessons_count} les
                        </td>
                        <td className="py-3 px-3 text-right space-x-1.5 whitespace-nowrap">
                          <button
                            type="button"
                            onClick={() => openEditCourseModal(c)}
                            className="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-[11px] border border-slate-700"
                          >
                            Edit
                          </button>
                          <button
                            type="button"
                            onClick={() => handleTogglePublish(c)}
                            className={`px-2 py-1 rounded-lg font-bold text-[11px] border ${
                              c.is_published
                                ? 'bg-amber-950/60 text-amber-300 border-amber-800 hover:bg-amber-900'
                                : 'bg-emerald-950/60 text-emerald-300 border-emerald-800 hover:bg-emerald-900'
                            }`}
                          >
                            {c.is_published ? 'Unpublish' : 'Publish'}
                          </button>
                          <Link
                            to={`/admin/courses/${c.id}/curriculum`}
                            className="px-2 py-1 rounded-lg bg-blue-950 hover:bg-blue-900 text-blue-300 border border-blue-800 font-bold text-[11px]"
                          >
                            Curriculum
                          </Link>
                          <button
                            type="button"
                            onClick={() => handleDeleteCourse(c)}
                            className="px-2 py-1 rounded-lg bg-red-950/60 hover:bg-red-900 text-red-300 border border-red-800 font-bold text-[11px]"
                          >
                            Delete
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="text-xs text-slate-500 py-4 text-center">No courses configured.</p>
            )}
          </div>
        </div>

        {/* Right 1 Column */}
        <div className="space-y-8">
          {/* Confirmed Admissions */}
          <div className="bg-slate-950 p-6 rounded-3xl border border-slate-800 space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-base font-black text-white">Recent Admissions</h2>
              <Link to="/admin/enquiries?status=admission_confirmed" className="text-xs font-bold text-teal-400 hover:underline">
                View All →
              </Link>
            </div>

            {data?.recent_admissions && data.recent_admissions.length > 0 ? (
              <div className="space-y-3">
                {data.recent_admissions.map((adm) => (
                  <div key={adm.id} className="p-3 bg-slate-900 rounded-2xl border border-slate-800 text-xs flex items-center justify-between">
                    <div>
                      <p className="font-bold text-white">{adm.name}</p>
                      <p className="text-[11px] text-slate-400">{adm.course_title || 'General'}</p>
                      <span className="text-[10px] text-slate-500">
                        {adm.status === 'enrolled' ? 'Enrolled & Active' : 'Confirmed Candidate'}
                      </span>
                    </div>
                    <Link
                      to="/admin/enquiries"
                      className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-[11px] font-bold border border-slate-700"
                    >
                      {adm.status === 'enrolled' ? 'View' : 'Enroll'}
                    </Link>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-xs text-slate-500 py-3 text-center">No confirmed admissions yet.</p>
            )}
          </div>

          {/* Student LMS Activity */}
          <div className="bg-slate-950 p-6 rounded-3xl border border-slate-800 space-y-4">
            <h2 className="text-base font-black text-white">Recent Student LMS Activity</h2>
            <div className="space-y-2.5 text-xs">
              {data?.student_activity?.recent_enrollments?.map((act, idx) => (
                <div key={idx} className="p-3 bg-slate-900 rounded-2xl border border-slate-800 flex items-start gap-2.5">
                  <span className="text-base">🎓</span>
                  <div>
                    <p className="text-slate-200">
                      <strong className="text-white">{act.student_name}</strong> enrolled in{' '}
                      <span className="text-blue-400 font-semibold">{act.course_title}</span>
                    </p>
                    <span className="text-[10px] text-slate-500">{new Date(act.timestamp).toLocaleDateString()}</span>
                  </div>
                </div>
              ))}
              {(!data?.student_activity?.recent_enrollments || data.student_activity.recent_enrollments.length === 0) && (
                <p className="text-xs text-slate-500 py-2 text-center">No recent LMS student activity.</p>
              )}
            </div>
          </div>

          {/* Real-time System Activity Stream */}
          <div className="bg-slate-950 p-6 rounded-3xl border border-slate-800 space-y-4">
            <h2 className="text-base font-black text-white">System Activity Stream</h2>
            <div className="space-y-2.5 text-xs max-h-72 overflow-y-auto pr-1">
              {data?.system_activity?.map((act, idx) => (
                <div key={idx} className="p-2.5 bg-slate-900 rounded-xl border border-slate-800 flex items-start gap-2.5">
                  <span className="text-sm shrink-0">{act.icon}</span>
                  <div className="min-w-0">
                    <p className="font-bold text-slate-200 text-[11px] truncate">{act.event}</p>
                    <p className="text-slate-400 text-[10px] line-clamp-2">{act.description}</p>
                    <span className="text-[9px] text-slate-500 block mt-0.5">
                      {new Date(act.timestamp).toLocaleString()}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* CREATE COURSE MODAL */}
      {showCourseModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-xs p-4">
          <div className="bg-slate-950 border border-slate-800 rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl text-white space-y-6">
            <div className="flex items-center justify-between border-b border-slate-800 pb-4">
              <div>
                <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-blue-950 text-blue-300 border border-blue-700">
                  Course Portfolio
                </span>
                <h2 className="text-xl font-black text-white mt-1">Create New Program Track</h2>
              </div>
              <button
                type="button"
                onClick={() => setShowCourseModal(false)}
                className="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center font-bold"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleCreateCourse} className="space-y-4 text-xs">
              {courseError && (
                <div className="p-3 bg-red-950/80 border border-red-800 text-red-300 rounded-xl text-xs font-bold flex items-center justify-between">
                  <span>⚠ {courseError}</span>
                  <button type="button" onClick={() => setCourseError('')} className="text-red-300 font-bold">
                    ×
                  </button>
                </div>
              )}
              <div>
                <label className="block text-slate-400 font-bold uppercase mb-1">Course Title</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Master Full Stack & Cloud Architecture"
                  value={courseForm.title}
                  onChange={(e) => setCourseForm({ ...courseForm, title: e.target.value })}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Category</label>
                  <select
                    value={courseForm.category}
                    onChange={(e) => setCourseForm({ ...courseForm, category: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  >
                    <option value="Cyber Security">Cyber Security</option>
                    <option value="DATABASE">DATABASE</option>
                    <option value="CLOUD COMPUTING">CLOUD COMPUTING</option>
                    <option value="DATA ENGINEERING">DATA ENGINEERING</option>
                    <option value="DATA ANALYST">DATA ANALYST</option>
                    <option value="DATA SCIENCE">DATA SCIENCE</option>
                    <option value="SAP">SAP</option>
                    <option value="ARTIFICIAL INTELLIGENCE">ARTIFICIAL INTELLIGENCE</option>
                    <option value="FULL STACK">FULL STACK</option>
                    <option value="Marketing & Business">Marketing & Business</option>
                    <option value="Healthcare & Life Sciences">Healthcare & Life Sciences</option>
                    <option value="Quality Assurance & Testing">Quality Assurance & Testing</option>
                    <option value="Mobile Engineering">Mobile Engineering</option>
                    <option value="Design & Creative">Design & Creative</option>
                  </select>
                </div>
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Instructor</label>
                  <input
                    type="text"
                    required
                    value={courseForm.instructor}
                    onChange={(e) => setCourseForm({ ...courseForm, instructor: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  />
                </div>
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Assign Tutor (owner)</label>
                  <select
                    value={courseForm.instructor_id}
                    onChange={(e) => {
                      const id = Number(e.target.value)
                      const picked = assignableInstructors.find((u) => u.id === id)
                      setCourseForm({
                        ...courseForm,
                        instructor_id: e.target.value ? String(id) : '',
                        instructor: picked ? picked.name : courseForm.instructor,
                      })
                    }}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  >
                    <option value="">{assignableInstructors.length ? '-- Not Assigned --' : 'Loading tutors...'}</option>
                    {assignableInstructors.map((u) => (
                      <option key={u.id} value={u.id} className="text-white">
                        {u.name} ({u.role})
                      </option>
                    ))}
                  </select>
                  <p className="text-[10px] text-slate-500 mt-0.5">Determines which tutor manages this course.</p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Duration</label>
                  <input
                    type="text"
                    required
                    value={courseForm.duration}
                    onChange={(e) => setCourseForm({ ...courseForm, duration: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  />
                </div>
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Internal Price (₹ INR)</label>
                  <input
                    type="number"
                    value={courseForm.price}
                    onChange={(e) => setCourseForm({ ...courseForm, price: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block text-slate-400 font-bold uppercase mb-1">Course Description</label>
                <textarea
                  rows={3}
                  required
                  placeholder="Outline track curriculum..."
                  value={courseForm.description}
                  onChange={(e) => setCourseForm({ ...courseForm, description: e.target.value })}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>

              {/* Course Media Management */}
              <div className="space-y-3 pt-2 border-t border-slate-800">
                <ImageUploadField
                  label="Course Thumbnail (Card Image)"
                  value={courseForm.thumbnail}
                  onChange={(url, mediaId) => setCourseForm({ ...courseForm, thumbnail: url, media_id: mediaId })}
                  folder="courses"
                  aspectRatio="video"
                  helpText="Recommended: 16:9 ratio, JPG/PNG/WebP, max 5MB."
                />

                <ImageUploadField
                  label="Course Header Banner"
                  value={courseForm.banner}
                  onChange={(url) => setCourseForm({ ...courseForm, banner: url })}
                  folder="courses"
                  aspectRatio="banner"
                  helpText="Wide banner displayed on the Course Details syllabus page."
                />

                <BrochureUploadField
                  label="Course PDF Brochure (Downloadable Syllabus)"
                  value={courseForm.brochure}
                  mediaId={courseForm.brochure_media_id}
                  onChange={(url, mediaId) => setCourseForm({ ...courseForm, brochure: url, brochure_media_id: mediaId })}
                  helpText="Upload a PDF brochure for public visitors to download directly from the course cards."
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowCourseModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={creatingCourse}
                  className="px-6 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-500 transition disabled:opacity-50"
                >
                  {creatingCourse ? 'Creating...' : 'Create Course Track'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* EDIT COURSE MODAL */}
      {editingCourse && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-xs p-4 overflow-y-auto">
          <div className="bg-slate-950 border border-slate-800 rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl text-white space-y-6 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-slate-800 pb-4">
              <div>
                <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-blue-950 text-blue-300 border border-blue-700">
                  Course Catalog Management
                </span>
                <h2 className="text-xl font-black text-white mt-1">Edit Course Track</h2>
              </div>
              <button
                type="button"
                onClick={() => setEditingCourse(null)}
                className="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center font-bold"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSaveCourseEdit} className="space-y-4 text-xs">
              {courseError && (
                <div className="p-3 bg-red-950/80 border border-red-800 text-red-300 rounded-xl text-xs font-bold flex items-center justify-between">
                  <span>⚠ {courseError}</span>
                  <button type="button" onClick={() => setCourseError('')} className="text-red-300 font-bold">
                    ×
                  </button>
                </div>
              )}
              <div>
                <label className="block text-slate-400 font-bold uppercase mb-1">Course Title</label>
                <input
                  type="text"
                  required
                  value={editCourseForm.title}
                  onChange={(e) => setEditCourseForm({ ...editCourseForm, title: e.target.value })}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Category</label>
                  <select
                    value={editCourseForm.category}
                    onChange={(e) => setEditCourseForm({ ...editCourseForm, category: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  >
                    <option value="Cyber Security">Cyber Security</option>
                    <option value="DATABASE">DATABASE</option>
                    <option value="CLOUD COMPUTING">CLOUD COMPUTING</option>
                    <option value="DATA ENGINEERING">DATA ENGINEERING</option>
                    <option value="DATA ANALYST">DATA ANALYST</option>
                    <option value="DATA SCIENCE">DATA SCIENCE</option>
                    <option value="SAP">SAP</option>
                    <option value="ARTIFICIAL INTELLIGENCE">ARTIFICIAL INTELLIGENCE</option>
                    <option value="FULL STACK">FULL STACK</option>
                    <option value="Marketing & Business">Marketing & Business</option>
                    <option value="Healthcare & Life Sciences">Healthcare & Life Sciences</option>
                    <option value="Quality Assurance & Testing">Quality Assurance & Testing</option>
                    <option value="Mobile Engineering">Mobile Engineering</option>
                    <option value="Design & Creative">Design & Creative</option>
                  </select>
                </div>
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Instructor</label>
                  <input
                    type="text"
                    required
                    value={editCourseForm.instructor}
                    onChange={(e) => setEditCourseForm({ ...editCourseForm, instructor: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  />
                </div>
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Assign Tutor (owner)</label>
                  <select
                    value={editCourseForm.instructor_id}
                    onChange={(e) => {
                      const id = Number(e.target.value)
                      const picked = assignableInstructors.find((u) => u.id === id)
                      setEditCourseForm({
                        ...editCourseForm,
                        instructor_id: e.target.value ? String(id) : '',
                        instructor: picked ? picked.name : editCourseForm.instructor,
                      })
                    }}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  >
                    <option value="">{assignableInstructors.length ? '-- Not Assigned --' : 'Loading tutors...'}</option>
                    {assignableInstructors.map((u) => (
                      <option key={u.id} value={u.id} className="text-white">
                        {u.name} ({u.role})
                      </option>
                    ))}
                  </select>
                  <p className="text-[10px] text-slate-500 mt-0.5">Determines which tutor manages this course.</p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Duration</label>
                  <input
                    type="text"
                    required
                    value={editCourseForm.duration}
                    onChange={(e) => setEditCourseForm({ ...editCourseForm, duration: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  />
                </div>
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Difficulty</label>
                  <select
                    value={editCourseForm.difficulty}
                    onChange={(e) => setEditCourseForm({ ...editCourseForm, difficulty: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  >
                    <option value="Beginner">Beginner</option>
                    <option value="Intermediate">Intermediate</option>
                    <option value="Advanced">Advanced</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Internal Price (₹ INR)</label>
                  <input
                    type="number"
                    value={editCourseForm.price}
                    onChange={(e) => setEditCourseForm({ ...editCourseForm, price: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  />
                  <p className="text-[10px] text-slate-500 mt-0.5">Strictly hidden from public, students, & tutors.</p>
                </div>
                <div>
                  <label className="block text-slate-400 font-bold uppercase mb-1">Publication Status</label>
                  <select
                    value={editCourseForm.is_published ? 'published' : 'draft'}
                    onChange={(e) => setEditCourseForm({ ...editCourseForm, is_published: e.target.value === 'published' })}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                  >
                    <option value="published">Published (Live in Catalog)</option>
                    <option value="draft">Draft (Unpublished)</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-slate-400 font-bold uppercase mb-1">Course Description</label>
                <textarea
                  rows={3}
                  value={editCourseForm.description}
                  onChange={(e) => setEditCourseForm({ ...editCourseForm, description: e.target.value })}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>

              {/* Course Media Management */}
              <div className="space-y-3 pt-2 border-t border-slate-800">
                <ImageUploadField
                  label="Course Thumbnail (Card Image)"
                  value={editCourseForm.thumbnail}
                  onChange={(url, mediaId) => setEditCourseForm({ ...editCourseForm, thumbnail: url, media_id: mediaId })}
                  folder="courses"
                  aspectRatio="video"
                  helpText="Recommended: 16:9 ratio, JPG/PNG/WebP, max 5MB."
                />

                <ImageUploadField
                  label="Course Header Banner"
                  value={editCourseForm.banner}
                  onChange={(url) => setEditCourseForm({ ...editCourseForm, banner: url })}
                  folder="courses"
                  aspectRatio="banner"
                  helpText="Wide banner displayed on the Course Details syllabus page."
                />

                <BrochureUploadField
                  label="Course PDF Brochure (Downloadable Syllabus)"
                  value={editCourseForm.brochure}
                  mediaId={editCourseForm.brochure_media_id}
                  onChange={(url, mediaId) => setEditCourseForm({ ...editCourseForm, brochure: url, brochure_media_id: mediaId })}
                  helpText="Upload or replace the PDF brochure for this course track."
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setEditingCourse(null)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingCourseEdit}
                  className="px-6 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-500 transition disabled:opacity-50"
                >
                  {savingCourseEdit ? 'Saving...' : 'Save Course Changes'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}