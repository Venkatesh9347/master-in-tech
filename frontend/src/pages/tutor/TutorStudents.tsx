import { useEffect, useState } from 'react'
import { useAuth } from '../../context/useAuth'
import API from '../../services/api'

interface EnrolledStudent {
  id: number
  user_id: number
  course_id: number
  enrolled_at: string
  status: string
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

export default function TutorStudents() {
  const { user } = useAuth()
  const [students, setStudents] = useState<EnrolledStudent[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [selectedCourseFilter, setSelectedCourseFilter] = useState('all')

  useEffect(() => {
    API.get<EnrolledStudent[]>('/tutor/students')
      .then((res) => {
        setStudents(Array.isArray(res.data) ? res.data : [])
      })
      .catch(() => setError('Failed to load student roster.'))
      .finally(() => setLoading(false))
  }, [user?.id])

  // Unique courses for filter
  const courseOptions = Array.from(
    new Set(students.map((s) => s.course?.title).filter(Boolean) as string[])
  )

  const filteredStudents = students.filter((s) => {
    const matchesSearch =
      (s.user?.name || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
      (s.user?.email || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
      (s.course?.title || '').toLowerCase().includes(searchTerm.toLowerCase())

    const matchesCourse =
      selectedCourseFilter === 'all' || s.course?.title === selectedCourseFilter

    return matchesSearch && matchesCourse
  })

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-slate-900">Enrolled Students Roster</h1>
          <p className="text-xs text-slate-500">
            Learners enrolled in courses authored and taught by you.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <span className="text-xs font-bold text-slate-500 bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-xs">
            Total Learners: <strong className="text-blue-600 font-black">{students.length}</strong>
          </span>
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-2xl bg-red-50 text-red-700 text-xs font-bold border border-red-200">
          ⚠️ {error}
        </div>
      )}

      {/* Filter & Search Bar */}
      <div className="bg-white rounded-3xl p-4 border border-slate-200/80 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        <div className="w-full sm:w-80">
          <input
            type="text"
            placeholder="Search by student name or email..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-600 outline-none"
          />
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto">
          <span className="text-slate-400 font-bold uppercase text-[10px] whitespace-nowrap">Filter Course:</span>
          <select
            value={selectedCourseFilter}
            onChange={(e) => setSelectedCourseFilter(e.target.value)}
            className="px-3 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-600 outline-none bg-white text-xs"
          >
            <option value="all">All Taught Courses</option>
            {courseOptions.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Table */}
      <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs">
        {loading ? (
          <p className="text-xs text-slate-500 py-12 text-center">Loading student roster...</p>
        ) : filteredStudents.length === 0 ? (
          <div className="py-12 text-center">
            <span className="text-4xl mb-2 block">👥</span>
            <h3 className="text-sm font-bold text-slate-900">No students found</h3>
            <p className="text-xs text-slate-500 mt-1">
              {students.length === 0
                ? 'As learners enroll in your courses, they will appear in this roster.'
                : 'No students match your active filters.'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-slate-200 text-slate-400 font-bold uppercase tracking-wider">
                  <th className="py-3 px-4">Student</th>
                  <th className="py-3 px-4">Course Program</th>
                  <th className="py-3 px-4">Curriculum Progress</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4">Enrolled Date</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredStudents.map((s) => {
                  const progress = Math.round(Number(s.progress_percentage || 0))
                  const isDone = progress >= 100 || s.status === 'completed'

                  return (
                    <tr key={s.id} className="hover:bg-slate-50/80 transition">
                      <td className="py-4 px-4 font-bold text-slate-900">
                        {s.user?.name || `Student #${s.user_id}`}
                        <span className="block text-[11px] text-slate-400 font-normal">{s.user?.email}</span>
                      </td>
                      <td className="py-4 px-4 font-semibold text-slate-800">{s.course?.title}</td>
                      <td className="py-4 px-4">
                        <div className="flex items-center gap-2">
                          <div className="w-24 h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div
                              className={`h-full rounded-full transition-all duration-500 ${
                                isDone ? 'bg-emerald-500' : 'bg-blue-600'
                              }`}
                              style={{ width: `${Math.max(5, Math.min(100, progress))}%` }}
                            />
                          </div>
                          <span className="font-bold text-slate-700">{progress}%</span>
                        </div>
                      </td>
                      <td className="py-4 px-4">
                        <span
                          className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                            isDone
                              ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                              : 'bg-blue-50 text-blue-700 border border-blue-200'
                          }`}
                        >
                          {isDone ? 'Completed' : 'Active'}
                        </span>
                      </td>
                      <td className="py-4 px-4 text-slate-500">
                        {new Date(s.enrolled_at).toLocaleDateString()}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
