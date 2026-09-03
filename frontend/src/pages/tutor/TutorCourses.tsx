import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'
import API from '../../services/api'
import type { Course } from '../../types/course'

export default function TutorCourses() {
  const { user } = useAuth()
  const [courses, setCourses] = useState<Course[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [filterCategory, setFilterCategory] = useState('all')
  const [filterStatus, setFilterStatus] = useState('all')
  const [errorMsg, setErrorMsg] = useState('')

  const loadCourses = () => {
    setLoading(true)
    API.get<Course[]>('/tutor/courses')
      .then((res) => {
        setCourses(Array.isArray(res.data) ? res.data : [])
      })
      .catch(() => setErrorMsg('Failed to load assigned courses.'))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadCourses()
  }, [user?.id])

  const categories = Array.from(new Set(courses.map((c) => c.category).filter(Boolean) as string[]))

  const filteredCourses = courses.filter((c) => {
    const matchesSearch =
      c.title.toLowerCase().includes(search.toLowerCase()) ||
      c.description.toLowerCase().includes(search.toLowerCase())

    const matchesCategory = filterCategory === 'all' || c.category === filterCategory
    const matchesStatus =
      filterStatus === 'all' || (c.status || (c.is_published ? 'published' : 'draft')) === filterStatus

    return matchesSearch && matchesCategory && matchesStatus
  })

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-slate-900">My Assigned Courses</h1>
          <p className="text-xs text-slate-500">
            Curriculums and student cohorts assigned to your faculty profile by administrators.
          </p>
        </div>
      </div>

      {errorMsg && (
        <div className="p-3.5 rounded-xl bg-red-50 text-red-800 text-xs font-semibold border border-red-200">
          ⚠️ {errorMsg}
        </div>
      )}

      {/* Filters & Search */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs grid grid-cols-1 sm:grid-cols-3 gap-3">
        <input
          type="text"
          placeholder="Search assigned courses..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="px-3.5 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs focus:outline-hidden focus:border-blue-500"
        />

        <select
          value={filterCategory}
          onChange={(e) => setFilterCategory(e.target.value)}
          aria-label="Filter by category"
          className="px-3.5 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-700 focus:outline-hidden focus:border-blue-500"
        >
          <option value="all">All Categories</option>
          {categories.map((cat) => (
            <option key={cat} value={cat}>
              {cat}
            </option>
          ))}
        </select>

        <select
          value={filterStatus}
          onChange={(e) => setFilterStatus(e.target.value)}
          aria-label="Filter by status"
          className="px-3.5 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-700 focus:outline-hidden focus:border-blue-500"
        >
          <option value="all">All Statuses</option>
          <option value="published">Published</option>
          <option value="draft">Draft</option>
        </select>
      </div>

      {/* Course Grid */}
      {loading ? (
        <div className="py-20 text-center text-xs text-slate-400 font-semibold">
          <span className="inline-block animate-spin mr-2">⚡</span> Loading assigned courses...
        </div>
      ) : filteredCourses.length === 0 ? (
        <div className="p-12 text-center bg-white rounded-3xl border border-slate-200 space-y-3">
          <span className="text-3xl">📚</span>
          <h2 className="text-base font-bold text-slate-800">No assigned courses found</h2>
          <p className="text-xs text-slate-500 max-w-sm mx-auto">
            {courses.length === 0
              ? 'Administrators will assign programs to your faculty account.'
              : 'No courses match your active filter search.'}
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {filteredCourses.map((course) => {
            const isDraft = course.status === 'draft' || course.is_published === false

            return (
              <div
                key={course.id}
                className="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs hover:shadow-md transition flex flex-col justify-between space-y-4"
              >
                <div className="space-y-3">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
                      {course.category || 'Tech Program'}
                    </span>
                    <span
                      className={`text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-full border ${
                        isDraft
                          ? 'bg-slate-100 text-slate-600 border-slate-300'
                          : 'bg-emerald-50 text-emerald-700 border-emerald-200'
                      }`}
                    >
                      {isDraft ? 'Draft' : '● Published'}
                    </span>
                  </div>

                  <div>
                    <h2 className="text-base font-bold text-slate-900 leading-snug">{course.title}</h2>
                    <p className="text-xs text-slate-500 mt-1 line-clamp-2">{course.description}</p>
                  </div>

                  <div className="grid grid-cols-3 gap-2 pt-2 border-t border-slate-100 text-center">
                    <div className="p-2 bg-slate-50 rounded-xl">
                      <p className="text-[10px] text-slate-400 font-bold uppercase">Enrolled</p>
                      <p className="text-sm font-black text-slate-800">{course.enrollments_count || 0}</p>
                    </div>
                    <div className="p-2 bg-slate-50 rounded-xl">
                      <p className="text-[10px] text-slate-400 font-bold uppercase">Duration</p>
                      <p className="text-xs font-bold text-slate-800 mt-0.5 truncate">{course.duration}</p>
                    </div>
                    <div className="p-2 bg-slate-50 rounded-xl">
                      <p className="text-[10px] text-slate-400 font-bold uppercase">Status</p>
                      <p className={`text-xs font-bold mt-0.5 capitalize ${isDraft ? 'text-amber-600' : 'text-emerald-600'}`}>
                        {isDraft ? 'Draft' : 'Published'}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Actions */}
                <div className="space-y-2 pt-2">
                  <div className="grid grid-cols-2 gap-2 text-xs font-bold">
                    <Link
                      to={`/tutor/courses/${course.id}/curriculum`}
                      className="py-2 px-2 rounded-xl bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 transition text-center flex items-center justify-center gap-1 text-[11px]"
                    >
                      <span>🛠️</span> Curriculum
                    </Link>
                    <Link
                      to={`/tutor/courses/${course.id}/analytics`}
                      className="py-2 px-2 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 transition text-center flex items-center justify-center gap-1 text-[11px]"
                    >
                      <span>📊</span> Analytics
                    </Link>
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
