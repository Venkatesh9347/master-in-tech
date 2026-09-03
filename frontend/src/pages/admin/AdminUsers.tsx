import { useEffect, useState, useCallback } from 'react'
import { Link } from 'react-router-dom'
import API from '../../services/api'
import type { UserRole } from '../../context/auth-context'
import type { Course } from '../../types/course'

interface AdminUserItem {
  id: number
  student_id?: string | null
  name: string
  email: string
  role: UserRole
  status?: string | null
  phone?: string | null
  headline?: string | null
  expertise?: string | null
  bio?: string | null
  created_at: string
  enrollments_count?: number
  taught_courses_count?: number
  enrollments?: { id: number; course?: { id: number; title: string } }[]
}

export default function AdminUsers() {
  const [users, setUsers] = useState<AdminUserItem[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [activeTab, setActiveTab] = useState<'all' | 'student' | 'tutor' | 'admin'>('all')

  // Create Student Modal State
  const [showCreateStudentModal, setShowCreateStudentModal] = useState(false)
  const [studentName, setStudentName] = useState('')
  const [studentEmail, setStudentEmail] = useState('')
  const [studentPhone, setStudentPhone] = useState('')
  const [studentIdInput, setStudentIdInput] = useState('')
  const [studentStatus, setStudentStatus] = useState('active')
  const [assignedCourseId, setAssignedCourseId] = useState<string | number>('')
  const [creatingStudent, setCreatingStudent] = useState(false)

  // Create Tutor Modal State
  const [showCreateTutorModal, setShowCreateTutorModal] = useState(false)
  const [createRole, setCreateRole] = useState<UserRole>('tutor')
  const [createName, setCreateName] = useState('')
  const [createEmail, setCreateEmail] = useState('')
  const [createPassword, setCreatePassword] = useState('')
  const [createPhone, setCreatePhone] = useState('')
  const [createHeadline, setCreateHeadline] = useState('')
  const [createExpertise, setCreateExpertise] = useState('')
  const [createBio, setCreateBio] = useState('')
  const [creatingTutor, setCreatingTutor] = useState(false)

  // Edit User Modal State
  const [editingUser, setEditingUser] = useState<AdminUserItem | null>(null)
  const [editName, setEditName] = useState('')
  const [editEmail, setEditEmail] = useState('')
  const [editStudentId, setEditStudentId] = useState('')
  const [editStatus, setEditStatus] = useState('active')
  const [editRole, setEditRole] = useState<UserRole>('student')
  const [editPhone, setEditPhone] = useState('')
  const [editHeadline, setEditHeadline] = useState('')
  const [editExpertise, setEditExpertise] = useState('')
  const [editBio, setEditBio] = useState('')
  const [editPassword, setEditPassword] = useState('')
  const [savingEdit, setSavingEdit] = useState(false)

  const [updatingId, setUpdatingId] = useState<number | null>(null)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadUsers = useCallback(() => {
    setLoading(true)
    let url = '/admin/users'
    const params = new URLSearchParams()
    if (activeTab !== 'all') params.append('role', activeTab)
    if (search.trim()) params.append('search', search.trim())

    if (params.toString()) {
      url += `?${params.toString()}`
    }

    API.get<AdminUserItem[]>(url)
      .then((res) => {
        setUsers(Array.isArray(res.data) ? res.data : [])
      })
      .catch(() => setErrorMsg('Failed to load user list.'))
      .finally(() => setLoading(false))
  }, [activeTab, search])

  useEffect(() => {
    loadUsers()
    API.get<Course[]>('/courses')
      .then((res) => setCourses(Array.isArray(res.data) ? res.data : []))
      .catch(() => {})
  }, [loadUsers])

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    loadUsers()
  }

  const handleRoleChange = async (userId: number, newRole: UserRole) => {
    setUpdatingId(userId)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      await API.put(`/admin/users/${userId}/role`, { role: newRole })
      setSuccessMsg(`User role successfully changed to ${newRole}.`)
      loadUsers()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to update user role.')
    } finally {
      setUpdatingId(null)
    }
  }

  // Handle Admin Student Account Provisioning
  const handleCreateStudent = async (e: React.FormEvent) => {
    e.preventDefault()
    setCreatingStudent(true)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      const res = await API.post<{ message: string; user: AdminUserItem }>('/admin/users', {
        name: studentName.trim(),
        email: studentEmail.trim(),
        phone: studentPhone.trim() || undefined,
        student_id: studentIdInput.trim() || undefined,
        status: studentStatus,
        role: 'student',
        course_id: assignedCourseId || undefined,
      })

      setSuccessMsg(
        `✓ Student account for ${res.data.user.name} (${res.data.user.email}) created successfully with ID ${
          res.data.user.student_id || 'STU'
        }!`
      )
      setShowCreateStudentModal(false)
      // Reset
      setStudentName('')
      setStudentEmail('')
      setStudentPhone('')
      setStudentIdInput('')
      setStudentStatus('active')
      setAssignedCourseId('')
      loadUsers()
      setTimeout(() => setSuccessMsg(''), 6000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const errDetail =
        response.response?.data?.errors?.email?.[0] ||
        response.response?.data?.errors?.phone?.[0] ||
        response.response?.data?.errors?.student_id?.[0] ||
        response.response?.data?.message ||
        'Failed to create student account.'
      setErrorMsg(errDetail)
    } finally {
      setCreatingStudent(false)
    }
  }

  // Handle Admin Tutor Account Provisioning
  const handleCreateTutor = async (e: React.FormEvent) => {
    e.preventDefault()
    setCreatingTutor(true)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      await API.post('/admin/users', {
        name: createName.trim(),
        email: createEmail.trim(),
        password: createPassword,
        role: createRole,
        phone: createPhone.trim() || undefined,
        headline: createHeadline.trim() || undefined,
        expertise: createExpertise.trim() || undefined,
        bio: createBio.trim() || undefined,
      })

      setSuccessMsg(`Tutor account (${createName}) provisioned successfully!`)
      setShowCreateTutorModal(false)
      // Reset form
      setCreateName('')
      setCreateEmail('')
      setCreatePassword('')
      setCreatePhone('')
      setCreateHeadline('')
      setCreateExpertise('')
      setCreateBio('')
      loadUsers()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setErrorMsg(response.response?.data?.message || 'Failed to create tutor. Verify email uniqueness.')
    } finally {
      setCreatingTutor(false)
    }
  }

  const openEditModal = (u: AdminUserItem) => {
    setEditingUser(u)
    setEditName(u.name)
    setEditEmail(u.email)
    setEditStudentId(u.student_id || '')
    setEditStatus(u.status || 'active')
    setEditRole(u.role || 'student')
    setEditPhone(u.phone || '')
    setEditHeadline(u.headline || '')
    setEditExpertise(u.expertise || '')
    setEditBio(u.bio || '')
    setEditPassword('')
  }

  const handleSaveEdit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!editingUser) return

    setSavingEdit(true)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      await API.put(`/admin/users/${editingUser.id}`, {
        name: editName.trim(),
        email: editEmail.trim(),
        student_id: editStudentId.trim() || null,
        status: editStatus,
        role: editRole,
        phone: editPhone.trim() || null,
        headline: editHeadline.trim() || null,
        expertise: editExpertise.trim() || null,
        bio: editBio.trim() || null,
        password: editPassword || undefined,
      })

      setSuccessMsg(`Profile updated successfully for ${editName}.`)
      setEditingUser(null)
      loadUsers()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setErrorMsg(response.response?.data?.message || 'Failed to update user details.')
    } finally {
      setSavingEdit(false)
    }
  }

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Student & User Management</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Administer approved student accounts, onboard faculty tutors, or manage system permissions.
          </p>
        </div>

        <div className="flex items-center gap-3 flex-wrap">
          <Link
            to="/admin/enrollments"
            className="px-4 py-2.5 rounded-xl bg-purple-950/80 hover:bg-purple-900 border border-purple-800 text-purple-300 hover:text-white text-xs font-bold shadow-md transition flex items-center gap-2"
          >
            <span>🎓</span> Student Enrollments
          </Link>
          <button
            type="button"
            onClick={() => setShowCreateStudentModal(true)}
            className="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white shadow-md shadow-blue-600/30 transition flex items-center gap-2"
          >
            <span>+</span> Create Student Account
          </button>
          <button
            type="button"
            onClick={() => {
              setCreateRole('tutor')
              setShowCreateTutorModal(true)
            }}
            className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition flex items-center gap-2"
          >
            <span>+</span> Create Tutor
          </button>
        </div>
      </div>

      {successMsg && (
        <div className="p-4 bg-emerald-950/80 border border-emerald-800 text-emerald-300 rounded-2xl text-xs font-bold">
          ✓ {successMsg}
        </div>
      )}

      {errorMsg && (
        <div className="p-4 bg-red-950/80 border border-red-800 text-red-300 rounded-2xl text-xs font-bold">
          ⚠️ {errorMsg}
        </div>
      )}

      {/* Role Navigation Tabs */}
      <div className="flex items-center gap-2 overflow-x-auto pb-1">
        {[
          { id: 'all', label: 'All Users' },
          { id: 'student', label: 'Students' },
          { id: 'tutor', label: 'Tutors / Faculty' },
          { id: 'admin', label: 'Administrators' },
        ].map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setActiveTab(tab.id as typeof activeTab)}
            className={`px-4 py-2 rounded-xl text-xs font-bold transition shrink-0 ${
              activeTab === tab.id
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                : 'bg-slate-950 text-slate-400 hover:text-white border border-slate-800'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Filter and Search Bar */}
      <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-4">
        <form onSubmit={handleSearchSubmit} className="w-full sm:max-w-md flex items-center gap-2">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by student name, email, phone, or ID..."
            className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-xs text-white placeholder:text-slate-500 focus:ring-2 focus:ring-blue-500 outline-none"
          />
          <button
            type="submit"
            className="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white transition shrink-0"
          >
            Search
          </button>
        </form>

        <div className="text-xs text-slate-400 font-semibold">
          Showing <strong className="text-white">{users.length}</strong> accounts
        </div>
      </div>

      {/* Users Table */}
      <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8">
        {loading ? (
          <p className="text-xs text-slate-500 py-8 text-center">Loading platform users...</p>
        ) : users.length === 0 ? (
          <div className="py-10 text-center text-slate-500 text-xs">
            <span className="text-3xl mb-2 block">👥</span>
            <p className="font-bold text-slate-300">No users found</p>
            <p className="mt-1">Try clearing your active search filter or create a new student account.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                  <th className="py-3 px-4">Student / User</th>
                  <th className="py-3 px-4">Student ID</th>
                  <th className="py-3 px-4">Role & Status</th>
                  <th className="py-3 px-4">Mobile / Contact</th>
                  <th className="py-3 px-4">Assigned Courses</th>
                  <th className="py-3 px-4">Joined</th>
                  <th className="py-3 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60">
                {users.map((u) => {
                  const currentRole = (u.role || 'student') as UserRole
                  const isStudent = currentRole === 'student'
                  return (
                    <tr key={u.id} className="hover:bg-slate-900/60 transition">
                      <td className="py-4 px-4">
                        <p className="font-bold text-white">{u.name}</p>
                        <p className="text-[11px] text-slate-400">{u.email}</p>
                      </td>
                      <td className="py-4 px-4">
                        {u.student_id ? (
                          <span className="px-2 py-0.5 rounded-md bg-blue-950/80 border border-blue-800 text-blue-300 font-mono text-[11px] font-bold">
                            {u.student_id}
                          </span>
                        ) : isStudent ? (
                          <span className="text-slate-500 font-mono text-[10px]">Auto (STU-{1000 + u.id})</span>
                        ) : (
                          <span className="text-slate-600">—</span>
                        )}
                      </td>
                      <td className="py-4 px-4 space-y-1">
                        <div>
                          <span
                            className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                              currentRole === 'admin'
                                ? 'bg-purple-950 text-purple-300 border border-purple-800'
                                : currentRole === 'tutor'
                                ? 'bg-amber-950 text-amber-300 border border-amber-800'
                                : 'bg-blue-950 text-blue-300 border border-blue-800'
                            }`}
                          >
                            {currentRole}
                          </span>
                        </div>
                        <div>
                          <span
                            className={`px-2 py-0.2 text-[9px] font-bold rounded ${
                              u.status === 'disabled'
                                ? 'bg-red-950 text-red-400 border border-red-800'
                                : 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                            }`}
                          >
                            {u.status === 'disabled' ? 'Inactive' : 'Active'}
                          </span>
                        </div>
                      </td>
                      <td className="py-4 px-4 text-slate-300">
                        {u.phone ? (
                          <span className="font-mono text-slate-200">{u.phone}</span>
                        ) : (
                          <span className="text-slate-500 text-[10px]">No mobile</span>
                        )}
                      </td>
                      <td className="py-4 px-4 text-slate-300">
                        {currentRole === 'tutor' ? (
                          <span>{u.taught_courses_count || 0} taught</span>
                        ) : (
                          <span>{u.enrollments_count || 0} enrolled</span>
                        )}
                      </td>
                      <td className="py-4 px-4 text-slate-400">
                        {new Date(u.created_at).toLocaleDateString()}
                      </td>
                      <td className="py-4 px-4 text-right space-x-2">
                        <button
                          type="button"
                          onClick={() => openEditModal(u)}
                          className="px-2.5 py-1 rounded-lg bg-slate-900 text-slate-300 hover:text-white hover:bg-slate-800 border border-slate-700 font-bold text-[11px] transition"
                        >
                          Edit Profile
                        </button>
                        <select
                          value={currentRole}
                          disabled={updatingId === u.id}
                          onChange={(e) => handleRoleChange(u.id, e.target.value as UserRole)}
                          className="px-2 py-1 rounded-lg bg-slate-900 border border-slate-700 text-white font-semibold text-[11px] outline-none"
                        >
                          <option value="student">Student</option>
                          <option value="tutor">Tutor</option>
                          <option value="admin">Admin</option>
                        </select>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* CREATE STUDENT ACCOUNT MODAL */}
      {showCreateStudentModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-800 space-y-5 text-white max-h-[90vh] overflow-y-auto">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-blue-950 text-blue-300 border border-blue-800 rounded-md">
                Admissions Cockpit
              </span>
              <h3 className="text-lg font-bold text-white mt-1">Create Approved Student Account</h3>
              <p className="text-xs text-slate-400">
                Provision a verified student account. Students will sign in securely using Google OAuth or Mobile OTP.
              </p>
            </div>

            <form onSubmit={handleCreateStudent} className="space-y-4 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Student Full Name <span className="text-red-400">*</span>
                  </label>
                  <input
                    type="text"
                    value={studentName}
                    onChange={(e) => setStudentName(e.target.value)}
                    placeholder="e.g. Rahul Sharma"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                    required
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Gmail / Student Email <span className="text-red-400">*</span>
                  </label>
                  <input
                    type="email"
                    value={studentEmail}
                    onChange={(e) => setStudentEmail(e.target.value)}
                    placeholder="rahul@gmail.com"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                    required
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Registered Mobile Number
                  </label>
                  <input
                    type="tel"
                    value={studentPhone}
                    onChange={(e) => setStudentPhone(e.target.value)}
                    placeholder="+91 9876543210"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                  />
                  <p className="text-[10px] text-slate-500 mt-0.5">Used for Mobile OTP login</p>
                </div>

                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Custom Student ID (Optional)
                  </label>
                  <input
                    type="text"
                    value={studentIdInput}
                    onChange={(e) => setStudentIdInput(e.target.value)}
                    placeholder="e.g. STU-1001 (Auto if empty)"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none font-mono"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Assign Course (LMS Enrollment)
                  </label>
                  <select
                    value={assignedCourseId}
                    onChange={(e) => setAssignedCourseId(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                  >
                    <option value="">No Course (Admissions Only)</option>
                    {courses.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.title}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Account Access Status
                  </label>
                  <select
                    value={studentStatus}
                    onChange={(e) => setStudentStatus(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                  >
                    <option value="active">Active (Access Granted)</option>
                    <option value="pending">Pending Counselling</option>
                    <option value="disabled">Disabled (No Access)</option>
                  </select>
                </div>
              </div>

              <div className="p-3 bg-blue-950/40 border border-blue-800/60 rounded-xl text-[11px] text-blue-200">
                💡 <strong>Dual Authentication:</strong> The student can log in via Google OAuth or their mobile number. Both methods will automatically link to this student identity without creating duplicate records.
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowCreateStudentModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-400 hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={creatingStudent}
                  className="px-5 py-2 rounded-xl font-bold bg-blue-600 hover:bg-blue-500 text-white shadow-md transition disabled:opacity-50"
                >
                  {creatingStudent ? 'Creating Student...' : 'Create Student Account'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* CREATE TUTOR MODAL */}
      {showCreateTutorModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-800 space-y-5 text-white max-h-[90vh] overflow-y-auto">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-950 text-purple-300 border border-purple-800 rounded-md">
                Admin Console
              </span>
              <h3 className="text-lg font-bold text-white mt-1">Create Tutor / Faculty Account</h3>
              <p className="text-xs text-slate-400">
                Provision a verified faculty profile to author courses and grade student capstones.
              </p>
            </div>

            <form onSubmit={handleCreateTutor} className="space-y-4 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Faculty Full Name
                  </label>
                  <input
                    type="text"
                    value={createName}
                    onChange={(e) => setCreateName(e.target.value)}
                    placeholder="e.g. Dr. Robert Smith"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                    required
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Email Address
                  </label>
                  <input
                    type="email"
                    value={createEmail}
                    onChange={(e) => setCreateEmail(e.target.value)}
                    placeholder="robert@example.com"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                    required
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Temporary Password
                  </label>
                  <input
                    type="password"
                    value={createPassword}
                    onChange={(e) => setCreatePassword(e.target.value)}
                    placeholder="At least 6 characters"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                    required
                    minLength={6}
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Phone (Optional)
                  </label>
                  <input
                    type="text"
                    value={createPhone}
                    onChange={(e) => setCreatePhone(e.target.value)}
                    placeholder="+91 9876543210"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Professional Title / Headline
                </label>
                <input
                  type="text"
                  value={createHeadline}
                  onChange={(e) => setCreateHeadline(e.target.value)}
                  placeholder="e.g. Principal Cloud Architect & Senior Educator"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Areas of Expertise (comma separated)
                </label>
                <input
                  type="text"
                  value={createExpertise}
                  onChange={(e) => setCreateExpertise(e.target.value)}
                  placeholder="e.g. React, Next.js, Node.js, Cloud, DevOps"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Short Teaching Bio
                </label>
                <textarea
                  value={createBio}
                  onChange={(e) => setCreateBio(e.target.value)}
                  rows={3}
                  placeholder="Faculty background and course specializations..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none leading-relaxed"
                />
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowCreateTutorModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-400 hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={creatingTutor}
                  className="px-5 py-2 rounded-xl font-bold bg-purple-600 hover:bg-purple-700 text-white shadow-md transition disabled:opacity-50"
                >
                  {creatingTutor ? 'Creating Tutor...' : 'Provision Tutor Account'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Edit User Modal */}
      {editingUser && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-800 space-y-5 text-white max-h-[90vh] overflow-y-auto">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-blue-950 text-blue-300 border border-blue-800 rounded-md">
                Admin Editor
              </span>
              <h3 className="text-lg font-bold text-white mt-1">Edit User Profile ({editingUser.name})</h3>
              <p className="text-xs text-slate-400">Update credentials, student ID, access status, or contact details.</p>
            </div>

            <form onSubmit={handleSaveEdit} className="space-y-4 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Full Name
                  </label>
                  <input
                    type="text"
                    value={editName}
                    onChange={(e) => setEditName(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                    required
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Email Address
                  </label>
                  <input
                    type="email"
                    value={editEmail}
                    onChange={(e) => setEditEmail(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                    required
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Role</label>
                  <select
                    value={editRole}
                    onChange={(e) => setEditRole(e.target.value as UserRole)}
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                  >
                    <option value="student">Student</option>
                    <option value="tutor">Tutor</option>
                    <option value="admin">Administrator</option>
                  </select>
                </div>

                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Student ID</label>
                  <input
                    type="text"
                    value={editStudentId}
                    onChange={(e) => setEditStudentId(e.target.value)}
                    placeholder="e.g. STU-1001"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Access Status</label>
                  <select
                    value={editStatus}
                    onChange={(e) => setEditStatus(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                  >
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="disabled">Disabled</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Mobile / Phone</label>
                <input
                  type="text"
                  value={editPhone}
                  onChange={(e) => setEditPhone(e.target.value)}
                  placeholder="+91 9876543210"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Headline / Professional Title
                </label>
                <input
                  type="text"
                  value={editHeadline}
                  onChange={(e) => setEditHeadline(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Areas of Expertise
                </label>
                <input
                  type="text"
                  value={editExpertise}
                  onChange={(e) => setEditExpertise(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Teaching Bio</label>
                <textarea
                  value={editBio}
                  onChange={(e) => setEditBio(e.target.value)}
                  rows={3}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none leading-relaxed"
                />
              </div>

              <div className="pt-2">
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Reset Password (Leave blank to keep unchanged)
                </label>
                <input
                  type="password"
                  value={editPassword}
                  onChange={(e) => setEditPassword(e.target.value)}
                  placeholder="Enter new password"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-blue-500 outline-none"
                  minLength={6}
                />
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setEditingUser(null)}
                  className="px-4 py-2 rounded-xl text-slate-400 hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingEdit}
                  className="px-5 py-2 rounded-xl font-bold bg-blue-600 hover:bg-blue-500 text-white shadow-md transition disabled:opacity-50"
                >
                  {savingEdit ? 'Saving Changes...' : 'Save User Profile'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
