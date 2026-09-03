import { useEffect, useState, useCallback, useMemo } from 'react'
import API from '../../services/api'
import type { Course } from '../../types/course'

interface StudentUser {
  id: number
  name: string
  email: string
  student_id?: string | null
  role: string
  status?: string | null
  avatar?: string | null
  created_at?: string
  enrollments_count?: number
}

interface EnrollmentItem {
  id: number
  user_id: number
  course_id: number
  status: string
  progress_percentage: number | string
  enrolled_at: string
  total_lessons?: number
  completed_lessons?: number
  calculated_progress_percentage?: number
  user?: StudentUser
  course?: Course & {
    sections_count?: number
    lessons_count?: number
  }
}

interface StatsData {
  total_enrollments: number
  active_enrollments: number
  completed_enrollments: number
  unique_students: number
}

export default function AdminEnrollments() {
  // Mode: 'by_student' | 'master_table'
  const [viewMode, setViewMode] = useState<'by_student' | 'master_table'>('by_student')

  // Platform Data
  const [stats, setStats] = useState<StatsData | null>(null)
  const [students, setStudents] = useState<StudentUser[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [allEnrollments, setAllEnrollments] = useState<EnrollmentItem[]>([])

  // Student Assignment Mode State
  const [selectedStudent, setSelectedStudent] = useState<StudentUser | null>(null)
  const [studentEnrollments, setStudentEnrollments] = useState<EnrollmentItem[]>([])
  const [studentSearch, setStudentSearch] = useState('')
  const [assignCourseId, setAssignCourseId] = useState<string | number>('')
  const [assignStatus, setAssignStatus] = useState<string>('active')

  // Master Table Mode State
  const [masterSearch, setMasterSearch] = useState('')
  const [masterStatusFilter, setMasterStatusFilter] = useState<string>('all')
  const [masterCourseFilter, setMasterCourseFilter] = useState<string>('all')

  // Quick Assign Modal State (for Master Table view)
  const [showQuickAssignModal, setShowQuickAssignModal] = useState(false)
  const [modalStudentId, setModalStudentId] = useState<string | number>('')
  const [modalCourseId, setModalCourseId] = useState<string | number>('')
  const [modalStatus, setModalStatus] = useState<string>('active')

  // Delete Confirmation Modal State
  const [enrollmentToDelete, setEnrollmentToDelete] = useState<EnrollmentItem | null>(null)
  const [deleting, setDeleting] = useState(false)

  // Loading & Async Action States
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [loadingStudentEnrollments, setLoadingStudentEnrollments] = useState(false)
  const [assigning, setAssigning] = useState(false)
  const [updatingEnrollmentId, setUpdatingEnrollmentId] = useState<number | null>(null)

  // Notification Alerts
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  // 1. Fetch Stats & Courses & Students
  const fetchStats = useCallback(async () => {
    try {
      const res = await API.get<StatsData>('/admin/enrollments/stats')
      setStats(res.data)
    } catch {
      // Non-blocking
    }
  }, [])

  const fetchCourses = useCallback(async () => {
    try {
      const res = await API.get<Course[]>('/courses')
      setCourses(Array.isArray(res.data) ? res.data : [])
    } catch {
      // Non-blocking
    }
  }, [])

  const fetchStudents = useCallback(async () => {
    try {
      const res = await API.get<StudentUser[]>('/admin/users?role=student')
      const studentList = Array.isArray(res.data) ? res.data : []
      setStudents(studentList)
      return studentList
    } catch {
      setErrorMsg('Failed to load students list.')
      return []
    }
  }, [])

  const fetchAllEnrollments = useCallback(async () => {
    try {
      const res = await API.get<EnrollmentItem[]>('/admin/enrollments')
      setAllEnrollments(Array.isArray(res.data) ? res.data : [])
    } catch {
      // Non-blocking
    }
  }, [])

  // 2. Fetch Enrollments for Selected Student
  const fetchStudentEnrollments = useCallback(async (studentId: number) => {
    setLoadingStudentEnrollments(true)
    try {
      const res = await API.get<EnrollmentItem[]>(`/admin/students/${studentId}/enrollments`)
      setStudentEnrollments(Array.isArray(res.data) ? res.data : [])
    } catch {
      setErrorMsg('Failed to load enrollments for this student.')
    } finally {
      setLoadingStudentEnrollments(false)
    }
  }, [])

  // Initial Load
  useEffect(() => {
    setLoadingInitial(true)
    Promise.all([fetchStats(), fetchCourses(), fetchStudents(), fetchAllEnrollments()])
      .then(([, , studentList]) => {
        if (studentList && studentList.length > 0 && !selectedStudent) {
          setSelectedStudent(studentList[0])
          fetchStudentEnrollments(studentList[0].id)
        }
      })
      .finally(() => setLoadingInitial(false))
  }, [fetchStats, fetchCourses, fetchStudents, fetchAllEnrollments, fetchStudentEnrollments, selectedStudent])

  // Select student handler
  const handleSelectStudent = (student: StudentUser) => {
    setSelectedStudent(student)
    setAssignCourseId('')
    setAssignStatus('active')
    setErrorMsg('')
    setSuccessMsg('')
    fetchStudentEnrollments(student.id)
  }

  // Filtered Students in Student Roster
  const filteredStudents = useMemo(() => {
    if (!studentSearch.trim()) return students
    const query = studentSearch.toLowerCase().trim()
    return students.filter(
      (s) =>
        s.name.toLowerCase().includes(query) ||
        s.email.toLowerCase().includes(query) ||
        (s.student_id && s.student_id.toLowerCase().includes(query))
    )
  }, [students, studentSearch])

  // IDs of courses the selected student is already enrolled in
  const enrolledCourseIds = useMemo(() => {
    return new Set(studentEnrollments.map((e) => e.course_id))
  }, [studentEnrollments])

  // Is selected course already enrolled?
  const isCourseAlreadyEnrolled = useMemo(() => {
    if (!assignCourseId) return false
    return enrolledCourseIds.has(Number(assignCourseId))
  }, [assignCourseId, enrolledCourseIds])

  // 3. Assign Course to Student (from Left Panel / Main View)
  const handleAssignCourse = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedStudent || !assignCourseId) return
    if (isCourseAlreadyEnrolled) {
      setErrorMsg('Student is already enrolled in this course.')
      return
    }

    setAssigning(true)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      const res = await API.post<{ message: string; enrollment: EnrollmentItem }>('/admin/enrollments', {
        user_id: selectedStudent.id,
        course_id: Number(assignCourseId),
        status: assignStatus,
      })

      const assignedTitle =
        res.data.enrollment?.course?.title ||
        courses.find((c) => c.id === Number(assignCourseId))?.title ||
        'Course'

      setSuccessMsg(`✓ Successfully assigned "${assignedTitle}" to ${selectedStudent.name}!`)
      setAssignCourseId('')
      setAssignStatus('active')

      // Refresh student's enrollments and global stats
      fetchStudentEnrollments(selectedStudent.id)
      fetchStats()
      fetchAllEnrollments()
      fetchStudents()

      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to assign course.')
    } finally {
      setAssigning(false)
    }
  }

  // 4. Quick Assign Modal Submission (from Master Table View)
  const handleQuickAssignSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!modalStudentId || !modalCourseId) {
      setErrorMsg('Please select both a student and a course.')
      return
    }

    setAssigning(true)
    setErrorMsg('')

    try {
      await API.post<{ message: string; enrollment: EnrollmentItem }>('/admin/enrollments', {
        user_id: Number(modalStudentId),
        course_id: Number(modalCourseId),
        status: modalStatus,
      })

      const studentName = students.find((s) => s.id === Number(modalStudentId))?.name || 'Student'
      const courseTitle = courses.find((c) => c.id === Number(modalCourseId))?.title || 'Course'

      setSuccessMsg(`✓ Assigned "${courseTitle}" to ${studentName} successfully!`)
      setShowQuickAssignModal(false)
      setModalStudentId('')
      setModalCourseId('')
      setModalStatus('active')

      fetchStats()
      fetchAllEnrollments()
      fetchStudents()
      if (selectedStudent && selectedStudent.id === Number(modalStudentId)) {
        fetchStudentEnrollments(selectedStudent.id)
      }

      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to assign course.')
    } finally {
      setAssigning(false)
    }
  }

  // 5. Update Enrollment Status
  const handleUpdateStatus = async (enrollmentId: number, newStatus: string) => {
    setUpdatingEnrollmentId(enrollmentId)
    setErrorMsg('')

    try {
      await API.put(`/admin/enrollments/${enrollmentId}`, {
        status: newStatus,
      })

      setSuccessMsg('✓ Enrollment status updated successfully.')

      // Update in studentEnrollments
      setStudentEnrollments((prev) =>
        prev.map((item) => (item.id === enrollmentId ? { ...item, status: newStatus } : item))
      )
      // Update in allEnrollments
      setAllEnrollments((prev) =>
        prev.map((item) => (item.id === enrollmentId ? { ...item, status: newStatus } : item))
      )

      fetchStats()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to update enrollment status.')
    } finally {
      setUpdatingEnrollmentId(null)
    }
  }

  // 6. Delete Enrollment / Unenroll
  const confirmDeleteEnrollment = async () => {
    if (!enrollmentToDelete) return
    setDeleting(true)
    setErrorMsg('')

    try {
      await API.delete(`/admin/enrollments/${enrollmentToDelete.id}`)
      const studentName = enrollmentToDelete.user?.name || selectedStudent?.name || 'Student'
      const courseTitle = enrollmentToDelete.course?.title || 'Course'

      setSuccessMsg(`✓ Removed enrollment for "${courseTitle}" from ${studentName}.`)

      // Remove from local lists
      setStudentEnrollments((prev) => prev.filter((item) => item.id !== enrollmentToDelete.id))
      setAllEnrollments((prev) => prev.filter((item) => item.id !== enrollmentToDelete.id))

      setEnrollmentToDelete(null)
      fetchStats()
      fetchStudents()

      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to remove enrollment.')
    } finally {
      setDeleting(false)
    }
  }

  // Master Table Filtered List
  const filteredMasterEnrollments = useMemo(() => {
    return allEnrollments.filter((item) => {
      // Status filter
      if (masterStatusFilter !== 'all' && item.status !== masterStatusFilter) {
        return false
      }
      // Course filter
      if (masterCourseFilter !== 'all' && String(item.course_id) !== masterCourseFilter) {
        return false
      }
      // Search filter
      if (masterSearch.trim()) {
        const query = masterSearch.toLowerCase().trim()
        const matchStudent =
          item.user?.name?.toLowerCase().includes(query) ||
          item.user?.email?.toLowerCase().includes(query) ||
          item.user?.student_id?.toLowerCase().includes(query)
        const matchCourse =
          item.course?.title?.toLowerCase().includes(query) ||
          item.course?.category?.toLowerCase().includes(query)
        if (!matchStudent && !matchCourse) return false
      }
      return true
    })
  }, [allEnrollments, masterStatusFilter, masterCourseFilter, masterSearch])

  return (
    <div className="space-y-8">
      {/* 1. Header Banner */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight flex items-center gap-2">
              <span className="p-2 rounded-xl bg-purple-950/80 border border-purple-800 text-purple-400 text-xl shadow-inner">
                🎓
              </span>
              Student Enrollments & Course Assignment
            </h1>
          </div>
          <p className="text-slate-400 text-sm mt-1">
            Search students, assign courses, inspect real-time progress, and manage platform enrollment statuses.
          </p>
        </div>

        {/* View Switcher Tabs */}
        <div className="flex items-center gap-2 bg-slate-950 p-1.5 rounded-2xl border border-slate-800 self-start md:self-auto">
          <button
            type="button"
            onClick={() => setViewMode('by_student')}
            className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 ${
              viewMode === 'by_student'
                ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                : 'text-slate-400 hover:text-white hover:bg-slate-800'
            }`}
          >
            <span>👤</span>
            <span>By Student Assignment</span>
          </button>
          <button
            type="button"
            onClick={() => {
              setViewMode('master_table')
              fetchAllEnrollments()
            }}
            className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 ${
              viewMode === 'master_table'
                ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                : 'text-slate-400 hover:text-white hover:bg-slate-800'
            }`}
          >
            <span>📋</span>
            <span>All Enrollments Table</span>
          </button>
        </div>
      </div>

      {/* 2. Platform Summary Metrics KPI Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-purple-500/50 transition">
          <div className="absolute top-0 right-0 w-24 h-24 bg-purple-600/5 rounded-full blur-2xl group-hover:bg-purple-600/10 transition" />
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Total Enrollments</span>
            <span className="text-xl">📚</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-white">
              {stats?.total_enrollments ?? (loadingInitial ? '...' : allEnrollments.length)}
            </span>
            <span className="text-xs font-semibold text-purple-400">active & past</span>
          </div>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-emerald-500/50 transition">
          <div className="absolute top-0 right-0 w-24 h-24 bg-emerald-600/5 rounded-full blur-2xl group-hover:bg-emerald-600/10 transition" />
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Active Learners</span>
            <span className="text-xl">⚡</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-emerald-400">
              {stats?.active_enrollments ?? '...'}
            </span>
            <span className="text-xs font-semibold text-slate-400">in progress</span>
          </div>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-blue-500/50 transition">
          <div className="absolute top-0 right-0 w-24 h-24 bg-blue-600/5 rounded-full blur-2xl group-hover:bg-blue-600/10 transition" />
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Completed Courses</span>
            <span className="text-xl">🏆</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-blue-400">
              {stats?.completed_enrollments ?? '...'}
            </span>
            <span className="text-xs font-semibold text-slate-400">graduated</span>
          </div>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-indigo-500/50 transition">
          <div className="absolute top-0 right-0 w-24 h-24 bg-indigo-600/5 rounded-full blur-2xl group-hover:bg-indigo-600/10 transition" />
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Enrolled Students</span>
            <span className="text-xl">👥</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-indigo-400">
              {stats?.unique_students ?? students.length}
            </span>
            <span className="text-xs font-semibold text-slate-400">unique students</span>
          </div>
        </div>
      </div>

      {/* 3. Alerts */}
      {successMsg && (
        <div className="bg-emerald-950/80 border border-emerald-800 text-emerald-200 px-5 py-3.5 rounded-2xl text-sm font-semibold flex items-center justify-between shadow-lg animate-in fade-in duration-200">
          <div className="flex items-center gap-3">
            <span className="text-lg">✓</span>
            <span>{successMsg}</span>
          </div>
          <button
            type="button"
            onClick={() => setSuccessMsg('')}
            className="text-emerald-400 hover:text-white text-xs font-bold px-2 py-1 rounded-lg hover:bg-emerald-900 transition"
          >
            ✕
          </button>
        </div>
      )}

      {errorMsg && (
        <div className="bg-rose-950/80 border border-rose-800 text-rose-200 px-5 py-3.5 rounded-2xl text-sm font-semibold flex items-center justify-between shadow-lg animate-in fade-in duration-200">
          <div className="flex items-center gap-3">
            <span className="text-lg">⚠️</span>
            <span>{errorMsg}</span>
          </div>
          <button
            type="button"
            onClick={() => setErrorMsg('')}
            className="text-rose-400 hover:text-white text-xs font-bold px-2 py-1 rounded-lg hover:bg-rose-900 transition"
          >
            ✕
          </button>
        </div>
      )}

      {/* 4. MAIN VIEW: STUDENT ASSIGNMENT CONSOLE */}
      {viewMode === 'by_student' && (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
          {/* Left Column: Searchable Student Roster (4 cols) */}
          <div className="lg:col-span-4 bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-5 shadow-xl flex flex-col gap-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800/80">
              <div>
                <h2 className="font-extrabold text-base text-white flex items-center gap-2">
                  <span>👥</span> Student Roster
                </h2>
                <p className="text-xs text-slate-400 mt-0.5">Select a student to manage courses</p>
              </div>
              <span className="text-xs font-extrabold px-2.5 py-1 rounded-full bg-slate-900 text-purple-400 border border-slate-800">
                {filteredStudents.length} Students
              </span>
            </div>

            {/* Student Search Input */}
            <div className="relative">
              <span className="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500 text-sm">
                🔍
              </span>
              <input
                type="text"
                value={studentSearch}
                onChange={(e) => setStudentSearch(e.target.value)}
                placeholder="Search by name, email, STU-ID..."
                className="w-full pl-10 pr-4 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition"
              />
              {studentSearch && (
                <button
                  type="button"
                  onClick={() => setStudentSearch('')}
                  className="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-white text-xs"
                >
                  ✕
                </button>
              )}
            </div>

            {/* Student List */}
            <div className="space-y-2 max-h-[580px] overflow-y-auto pr-1">
              {loadingInitial ? (
                <div className="py-12 text-center text-slate-500 text-xs flex flex-col items-center gap-2">
                  <div className="w-6 h-6 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
                  <span>Loading students...</span>
                </div>
              ) : filteredStudents.length === 0 ? (
                <div className="py-10 text-center text-slate-500 text-xs">
                  <span>No students match "{studentSearch}"</span>
                </div>
              ) : (
                filteredStudents.map((student) => {
                  const isSelected = selectedStudent?.id === student.id
                  return (
                    <button
                      key={student.id}
                      type="button"
                      onClick={() => handleSelectStudent(student)}
                      className={`w-full text-left p-3 rounded-2xl transition border flex items-center gap-3 relative ${
                        isSelected
                          ? 'bg-purple-950/60 border-purple-600 text-white shadow-md shadow-purple-900/20'
                          : 'bg-slate-900/60 border-slate-800/80 text-slate-300 hover:bg-slate-900 hover:border-slate-700'
                      }`}
                    >
                      {/* Avatar / Initial */}
                      <div
                        className={`w-10 h-10 rounded-xl flex items-center justify-center font-black text-sm shrink-0 uppercase ${
                          isSelected
                            ? 'bg-purple-600 text-white shadow-inner'
                            : 'bg-slate-800 text-slate-300'
                        }`}
                      >
                        {student.name.charAt(0)}
                      </div>

                      {/* Info */}
                      <div className="min-w-0 flex-grow">
                        <div className="flex items-center justify-between gap-1">
                          <p className="font-bold text-xs truncate text-white">{student.name}</p>
                          {student.student_id && (
                            <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-purple-300 shrink-0">
                              {student.student_id}
                            </span>
                          )}
                        </div>
                        <p className="text-[11px] text-slate-400 truncate mt-0.5">{student.email}</p>
                      </div>

                      {isSelected && (
                        <span className="text-purple-400 text-sm font-bold shrink-0">▶</span>
                      )}
                    </button>
                  )
                })
              )}
            </div>
          </div>

          {/* Right Column: Selected Student's Workspace & Assigned Courses (8 cols) */}
          <div className="lg:col-span-8 space-y-6">
            {selectedStudent ? (
              <>
                {/* Selected Student Banner Card */}
                <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl relative overflow-hidden">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                      <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-purple-700 to-indigo-600 flex items-center justify-center font-black text-xl text-white shadow-lg shadow-purple-600/30 shrink-0 uppercase">
                        {selectedStudent.name.charAt(0)}
                      </div>
                      <div>
                        <div className="flex items-center gap-2.5 flex-wrap">
                          <h3 className="font-black text-lg text-white">{selectedStudent.name}</h3>
                          {selectedStudent.student_id && (
                            <span className="px-2 py-0.5 rounded-full text-xs font-mono font-bold bg-purple-950 text-purple-300 border border-purple-800">
                              {selectedStudent.student_id}
                            </span>
                          )}
                          <span
                            className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                              selectedStudent.status === 'active' || !selectedStudent.status
                                ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                                : 'bg-slate-800 text-slate-400 border border-slate-700'
                            }`}
                          >
                            {selectedStudent.status || 'Active'}
                          </span>
                        </div>
                        <p className="text-xs text-slate-400 mt-1 flex items-center gap-3 flex-wrap">
                          <span>📧 {selectedStudent.email}</span>
                          <span>•</span>
                          <span>
                            🎓 {studentEnrollments.length}{' '}
                            {studentEnrollments.length === 1 ? 'Course Enrolled' : 'Courses Enrolled'}
                          </span>
                        </p>
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={() => fetchStudentEnrollments(selectedStudent.id)}
                      disabled={loadingStudentEnrollments}
                      className="self-start sm:self-auto px-3.5 py-2 rounded-xl text-xs font-bold text-slate-300 bg-slate-900 hover:bg-slate-800 border border-slate-800 transition flex items-center gap-2"
                    >
                      <span className={loadingStudentEnrollments ? 'animate-spin' : ''}>🔄</span>
                      <span>Refresh</span>
                    </button>
                  </div>
                </div>

                {/* Section A: Assign New Course Card */}
                <div className="bg-slate-950/80 backdrop-blur border border-purple-900/40 rounded-3xl p-6 shadow-xl relative overflow-hidden">
                  <div className="absolute top-0 right-0 w-40 h-40 bg-purple-600/5 rounded-full blur-3xl" />
                  <div className="flex items-center gap-2 mb-4">
                    <span className="p-1.5 rounded-lg bg-purple-600/20 text-purple-400 text-sm">➕</span>
                    <h4 className="font-extrabold text-sm text-white">Assign New Course to {selectedStudent.name}</h4>
                  </div>

                  <form onSubmit={handleAssignCourse} className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-12 gap-4">
                      {/* Course Selector (8 cols) */}
                      <div className="sm:col-span-8">
                        <label className="block text-xs font-bold text-slate-400 mb-1.5 uppercase tracking-wider">
                          Select Course to Assign
                        </label>
                        <select
                          value={assignCourseId}
                          onChange={(e) => {
                            setAssignCourseId(e.target.value)
                            setErrorMsg('')
                          }}
                          className="w-full px-4 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition"
                        >
                          <option value="">-- Select an Existing Course --</option>
                          {courses.map((course) => {
                            const isEnrolled = enrolledCourseIds.has(course.id)
                            return (
                              <option key={course.id} value={course.id} disabled={isEnrolled}>
                                {course.title} {course.category ? `(${course.category})` : ''}{' '}
                                {isEnrolled ? '— [Already Enrolled]' : ''}
                              </option>
                            )
                          })}
                        </select>
                      </div>

                      {/* Status Selector (4 cols) */}
                      <div className="sm:col-span-4">
                        <label className="block text-xs font-bold text-slate-400 mb-1.5 uppercase tracking-wider">
                          Initial Status
                        </label>
                        <select
                          value={assignStatus}
                          onChange={(e) => setAssignStatus(e.target.value)}
                          className="w-full px-4 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition"
                        >
                          <option value="active">Active (Enrolled)</option>
                          <option value="completed">Completed (Graduated)</option>
                          <option value="pending">Pending</option>
                        </select>
                      </div>
                    </div>

                    {/* Warning if already enrolled */}
                    {isCourseAlreadyEnrolled && (
                      <p className="text-xs font-bold text-amber-400 bg-amber-950/60 border border-amber-900 p-2.5 rounded-xl flex items-center gap-2">
                        <span>⚠️</span>
                        <span>{selectedStudent.name} is already enrolled in this course.</span>
                      </p>
                    )}

                    {/* Action Button */}
                    <div className="flex justify-end pt-1">
                      <button
                        type="submit"
                        disabled={assigning || !assignCourseId || isCourseAlreadyEnrolled}
                        className="px-6 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-purple-600/30 transition flex items-center gap-2"
                      >
                        {assigning ? (
                          <>
                            <div className="w-4 h-4 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                            <span>Assigning Course...</span>
                          </>
                        ) : (
                          <>
                            <span>⚡</span>
                            <span>Assign Course Now</span>
                          </>
                        )}
                      </button>
                    </div>
                  </form>
                </div>

                {/* Section B: Current Course Enrollments List */}
                <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-4">
                  <div className="flex items-center justify-between pb-3 border-b border-slate-800/80">
                    <div>
                      <h4 className="font-extrabold text-base text-white flex items-center gap-2">
                        <span>📚</span> Current Course Enrollments
                      </h4>
                      <p className="text-xs text-slate-400 mt-0.5">
                        Assigned courses for {selectedStudent.name} (Courses appear immediately on student's dashboard)
                      </p>
                    </div>
                    <span className="text-xs font-bold px-3 py-1 rounded-full bg-purple-950 text-purple-300 border border-purple-800">
                      {studentEnrollments.length} Active Courses
                    </span>
                  </div>

                  {loadingStudentEnrollments ? (
                    <div className="py-16 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
                      <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
                      <span>Loading course enrollments...</span>
                    </div>
                  ) : studentEnrollments.length === 0 ? (
                    <div className="py-16 text-center text-slate-500 text-xs flex flex-col items-center gap-2">
                      <span className="text-3xl">📭</span>
                      <span className="font-bold text-slate-400">No Courses Assigned Yet</span>
                      <p className="text-slate-500 max-w-sm">
                        Use the "Assign New Course" form above to assign a course to {selectedStudent.name}.
                      </p>
                    </div>
                  ) : (
                    <div className="space-y-3">
                      {studentEnrollments.map((enrollment) => {
                        const progress =
                          enrollment.calculated_progress_percentage !== undefined
                            ? Number(enrollment.calculated_progress_percentage)
                            : Number(enrollment.progress_percentage || 0)

                        const isUpdating = updatingEnrollmentId === enrollment.id

                        return (
                          <div
                            key={enrollment.id}
                            className="bg-slate-900/80 border border-slate-800 rounded-2xl p-4.5 hover:border-slate-700 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4"
                          >
                            {/* Course Left Metadata */}
                            <div className="flex items-center gap-3.5 min-w-0">
                              {enrollment.course?.thumbnail ? (
                                <img
                                  src={enrollment.course.thumbnail}
                                  alt={enrollment.course.title}
                                  className="w-12 h-12 rounded-xl object-cover border border-slate-800 shrink-0"
                                />
                              ) : (
                                <div className="w-12 h-12 rounded-xl bg-purple-950 border border-purple-800/80 flex items-center justify-center text-lg font-black text-purple-400 shrink-0">
                                  📖
                                </div>
                              )}

                              <div className="min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                  <h5 className="font-bold text-sm text-white truncate">
                                    {enrollment.course?.title || `Course #${enrollment.course_id}`}
                                  </h5>
                                  {enrollment.course?.category && (
                                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-800 text-slate-300 border border-slate-700">
                                      {enrollment.course.category}
                                    </span>
                                  )}
                                </div>

                                <div className="flex items-center gap-3 text-[11px] text-slate-400 mt-1 flex-wrap">
                                  <span>
                                    Enrolled on: {new Date(enrollment.enrolled_at).toLocaleDateString()}
                                  </span>
                                  {enrollment.course?.instructor && (
                                    <>
                                      <span>•</span>
                                      <span>👨‍🏫 {enrollment.course.instructor}</span>
                                    </>
                                  )}
                                </div>
                              </div>
                            </div>

                            {/* Progress & Actions (Right) */}
                            <div className="flex items-center gap-4 sm:shrink-0 justify-between sm:justify-end border-t sm:border-t-0 pt-3 sm:pt-0 border-slate-800">
                              {/* Progress Display */}
                              <div className="w-32 text-right">
                                <div className="flex items-center justify-between text-[11px] font-bold text-slate-400 mb-1">
                                  <span>Progress</span>
                                  <span className="text-white">{progress}%</span>
                                </div>
                                <div className="w-full h-2 rounded-full bg-slate-800 overflow-hidden">
                                  <div
                                    className={`h-full rounded-full transition-all duration-500 ${
                                      progress === 100
                                        ? 'bg-emerald-500'
                                        : progress > 0
                                        ? 'bg-purple-500'
                                        : 'bg-slate-700'
                                    }`}
                                    style={{ width: `${progress}%` }}
                                  />
                                </div>
                                {enrollment.total_lessons !== undefined && (
                                  <p className="text-[10px] text-slate-500 mt-0.5">
                                    {enrollment.completed_lessons ?? 0} / {enrollment.total_lessons} lessons
                                  </p>
                                )}
                              </div>

                              {/* Status Select */}
                              <div className="relative">
                                <select
                                  value={enrollment.status}
                                  onChange={(e) => handleUpdateStatus(enrollment.id, e.target.value)}
                                  disabled={isUpdating}
                                  className={`px-3 py-1.5 rounded-xl text-xs font-bold border transition focus:outline-none ${
                                    enrollment.status === 'completed'
                                      ? 'bg-emerald-950/80 text-emerald-300 border-emerald-800'
                                      : enrollment.status === 'cancelled'
                                      ? 'bg-rose-950/80 text-rose-300 border-rose-800'
                                      : 'bg-purple-950/80 text-purple-300 border-purple-800'
                                  }`}
                                >
                                  <option value="active">Active</option>
                                  <option value="completed">Completed</option>
                                  <option value="pending">Pending</option>
                                  <option value="cancelled">Cancelled</option>
                                </select>
                              </div>

                              {/* Remove Enrollment Action */}
                              <button
                                type="button"
                                onClick={() => setEnrollmentToDelete(enrollment)}
                                title="Remove Enrollment"
                                className="p-2 rounded-xl text-xs font-bold text-rose-400 hover:text-white bg-rose-950/50 hover:bg-rose-900 border border-rose-900/60 transition"
                              >
                                🗑️
                              </button>
                            </div>
                          </div>
                        )
                      })}
                    </div>
                  )}
                </div>
              </>
            ) : (
              <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl">
                <span className="text-4xl">👥</span>
                <h4 className="font-extrabold text-base text-slate-300 mt-2">No Student Selected</h4>
                <p className="text-xs text-slate-400 mt-1">
                  Please pick a student from the roster on the left to view and assign courses.
                </p>
              </div>
            )}
          </div>
        </div>
      )}

      {/* 5. MASTER TABLE VIEW: ALL PLATFORM ENROLLMENTS */}
      {viewMode === 'master_table' && (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-6">
          {/* Top Bar: Search, Filters & Quick Assign CTA */}
          <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div className="flex flex-col sm:flex-row sm:items-center gap-3 flex-grow">
              {/* Search */}
              <div className="relative flex-grow max-w-md">
                <span className="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500 text-sm">
                  🔍
                </span>
                <input
                  type="text"
                  value={masterSearch}
                  onChange={(e) => setMasterSearch(e.target.value)}
                  placeholder="Search by student name, email, ID, or course..."
                  className="w-full pl-10 pr-4 py-2 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                />
              </div>

              {/* Status Filter */}
              <select
                value={masterStatusFilter}
                onChange={(e) => setMasterStatusFilter(e.target.value)}
                className="px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
              >
                <option value="all">All Statuses</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="pending">Pending</option>
                <option value="cancelled">Cancelled</option>
              </select>

              {/* Course Filter */}
              <select
                value={masterCourseFilter}
                onChange={(e) => setMasterCourseFilter(e.target.value)}
                className="px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition max-w-[220px] truncate"
              >
                <option value="all">All Courses</option>
                {courses.map((c) => (
                  <option key={c.id} value={String(c.id)}>
                    {c.title}
                  </option>
                ))}
              </select>
            </div>

            {/* Quick Assign CTA */}
            <button
              type="button"
              onClick={() => setShowQuickAssignModal(true)}
              className="px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-2 self-start lg:self-auto shrink-0"
            >
              <span>➕</span>
              <span>Assign Course</span>
            </button>
          </div>

          {/* Table */}
          <div className="overflow-x-auto rounded-2xl border border-slate-800">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                <tr>
                  <th className="py-3.5 px-4">Student</th>
                  <th className="py-3.5 px-4">Course</th>
                  <th className="py-3.5 px-4">Enrolled Date</th>
                  <th className="py-3.5 px-4">Progress</th>
                  <th className="py-3.5 px-4">Status</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/80 bg-slate-950/40">
                {filteredMasterEnrollments.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500 font-semibold">
                      No enrollments found matching criteria.
                    </td>
                  </tr>
                ) : (
                  filteredMasterEnrollments.map((item) => {
                    const progress = Number(item.progress_percentage || 0)
                    return (
                      <tr key={item.id} className="hover:bg-slate-900/50 transition">
                        {/* Student */}
                        <td className="py-3.5 px-4">
                          <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-lg bg-purple-950 border border-purple-800 flex items-center justify-center font-bold text-xs text-purple-300 uppercase shrink-0">
                              {item.user?.name ? item.user.name.charAt(0) : 'S'}
                            </div>
                            <div>
                              <p className="font-bold text-white leading-tight">{item.user?.name || 'Unknown'}</p>
                              <p className="text-[11px] text-slate-400">{item.user?.email}</p>
                            </div>
                          </div>
                        </td>

                        {/* Course */}
                        <td className="py-3.5 px-4">
                          <p className="font-bold text-slate-200">{item.course?.title || `Course #${item.course_id}`}</p>
                          {item.course?.category && (
                            <span className="text-[10px] text-slate-500">{item.course.category}</span>
                          )}
                        </td>

                        {/* Enrolled Date */}
                        <td className="py-3.5 px-4 text-slate-400">
                          {new Date(item.enrolled_at).toLocaleDateString()}
                        </td>

                        {/* Progress */}
                        <td className="py-3.5 px-4">
                          <div className="w-24">
                            <div className="flex items-center justify-between text-[10px] font-bold text-slate-400 mb-0.5">
                              <span>{progress}%</span>
                            </div>
                            <div className="w-full h-1.5 rounded-full bg-slate-800 overflow-hidden">
                              <div
                                className={`h-full rounded-full ${
                                  progress === 100 ? 'bg-emerald-500' : progress > 0 ? 'bg-purple-500' : 'bg-slate-700'
                                }`}
                                style={{ width: `${progress}%` }}
                              />
                            </div>
                          </div>
                        </td>

                        {/* Status */}
                        <td className="py-3.5 px-4">
                          <select
                            value={item.status}
                            onChange={(e) => handleUpdateStatus(item.id, e.target.value)}
                            disabled={updatingEnrollmentId === item.id}
                            className={`px-2.5 py-1 rounded-lg text-[11px] font-bold border transition focus:outline-none ${
                              item.status === 'completed'
                                ? 'bg-emerald-950/80 text-emerald-300 border-emerald-800'
                                : item.status === 'cancelled'
                                ? 'bg-rose-950/80 text-rose-300 border-rose-800'
                                : 'bg-purple-950/80 text-purple-300 border-purple-800'
                            }`}
                          >
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="pending">Pending</option>
                            <option value="cancelled">Cancelled</option>
                          </select>
                        </td>

                        {/* Actions */}
                        <td className="py-3.5 px-4 text-right">
                          <button
                            type="button"
                            onClick={() => setEnrollmentToDelete(item)}
                            title="Remove Enrollment"
                            className="p-1.5 rounded-lg text-rose-400 hover:text-white bg-rose-950/40 hover:bg-rose-900 border border-rose-900/50 transition text-xs"
                          >
                            🗑️
                          </button>
                        </td>
                      </tr>
                    )
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* 6. MODAL: QUICK ASSIGN COURSE (FOR MASTER TABLE VIEW) */}
      {showQuickAssignModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 max-w-lg w-full shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>🎓</span> Assign Course to Student
              </h3>
              <button
                type="button"
                onClick={() => setShowQuickAssignModal(false)}
                className="text-slate-400 hover:text-white text-sm p-1 rounded-lg hover:bg-slate-800 transition"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleQuickAssignSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Select Student
                </label>
                <select
                  value={modalStudentId}
                  onChange={(e) => setModalStudentId(e.target.value)}
                  required
                  className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="">-- Choose an Existing Student --</option>
                  {students.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name} ({s.email}) {s.student_id ? `[${s.student_id}]` : ''}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Select Course
                </label>
                <select
                  value={modalCourseId}
                  onChange={(e) => setModalCourseId(e.target.value)}
                  required
                  className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="">-- Choose an Existing Course --</option>
                  {courses.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.title} {c.category ? `(${c.category})` : ''}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Initial Status
                </label>
                <select
                  value={modalStatus}
                  onChange={(e) => setModalStatus(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="active">Active (Enrolled)</option>
                  <option value="completed">Completed (Graduated)</option>
                  <option value="pending">Pending</option>
                </select>
              </div>

              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowQuickAssignModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={assigning || !modalStudentId || !modalCourseId}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 transition flex items-center gap-2"
                >
                  {assigning ? (
                    <>
                      <div className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                      <span>Assigning...</span>
                    </>
                  ) : (
                    <span>Confirm Assignment</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 7. MODAL: REMOVE ENROLLMENT CONFIRMATION */}
      {enrollmentToDelete && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-rose-900/60 rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4 animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center gap-3 text-rose-400">
              <span className="p-2 rounded-xl bg-rose-950 border border-rose-800 text-lg">⚠️</span>
              <h3 className="font-black text-base text-white">Confirm Remove Enrollment</h3>
            </div>

            <p className="text-xs text-slate-300 leading-relaxed">
              Are you sure you want to unenroll{' '}
              <strong className="text-white">
                {enrollmentToDelete.user?.name || selectedStudent?.name || 'this student'}
              </strong>{' '}
              from{' '}
              <strong className="text-white">
                "{enrollmentToDelete.course?.title || `Course #${enrollmentToDelete.course_id}`}"
              </strong>
              ? They will immediately lose access to this course on their Student Dashboard.
            </p>

            <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
              <button
                type="button"
                onClick={() => setEnrollmentToDelete(null)}
                disabled={deleting}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={confirmDeleteEnrollment}
                disabled={deleting}
                className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-rose-600 hover:bg-rose-500 disabled:opacity-50 transition flex items-center gap-2 shadow-lg shadow-rose-600/30"
              >
                {deleting ? (
                  <>
                    <div className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                    <span>Removing...</span>
                  </>
                ) : (
                  <span>Yes, Remove Enrollment</span>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
