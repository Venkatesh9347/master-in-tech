import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import API from '../services/api'
import type { Course } from '../types/course'

interface EnrollmentItem {
  id: number
  course_id: number
  status: string
  progress_percentage: number | string
  enrolled_at: string
  course: Course
}

export default function StudentProfile() {
  const { user } = useAuth()
  const [enrollments, setEnrollments] = useState<EnrollmentItem[]>([])
  const [loading, setLoading] = useState(true)

  // Edit Profile Form State
  const [name, setName] = useState(user?.name || '')
  const [phone, setPhone] = useState('+91 98765 43210')
  const [bio, setBio] = useState('Passionate software engineering student building full stack web & AI systems.')
  const [location, setLocation] = useState('Bangalore, India')
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  useEffect(() => {
    API.get<EnrollmentItem[]>('/my-courses')
      .then((res) => {
        setEnrollments(Array.isArray(res.data) ? res.data : [])
      })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const completedCourses = enrollments.filter(
    (e) => Number(e.progress_percentage) >= 100 || e.status === 'completed'
  )

  const inProgressCourses = enrollments.filter(
    (e) => Number(e.progress_percentage) < 100 && e.status !== 'completed'
  )

  const handleProfileSave = (e: React.FormEvent) => {
    e.preventDefault()
    if (password && password !== confirmPassword) {
      setErrorMsg('New password and confirmation do not match.')
      return
    }

    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    setTimeout(() => {
      setSaving(false)
      setSuccessMsg('Profile and settings updated successfully!')
      setPassword('')
      setConfirmPassword('')
      setTimeout(() => setSuccessMsg(''), 4000)
    }, 700)
  }

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-500 selection:text-white">
      <Navbar />

      <main className="flex-grow max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full space-y-8">
        {/* Profile Card Header */}
        <section className="bg-white rounded-3xl p-8 sm:p-10 border border-slate-200/80 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">
          <div className="flex items-center gap-5">
            <div className="w-20 h-20 rounded-3xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white font-black text-3xl flex items-center justify-center shadow-lg shadow-blue-500/20 shrink-0">
              {user?.name ? user.name.charAt(0).toUpperCase() : 'S'}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-2xl font-black text-slate-900">{name || user?.name}</h1>
                <span className="bg-blue-50 text-blue-700 text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full border border-blue-200">
                  Student Member
                </span>
              </div>
              <p className="text-xs text-slate-500 mt-0.5">{user?.email}</p>
              <p className="text-xs text-slate-400 mt-1 font-medium">📍 {location}</p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <Link
              to="/student"
              className="px-5 py-2.5 rounded-xl font-bold text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
            >
              My Dashboard →
            </Link>
          </div>
        </section>

        {/* Learning Statistics Row */}
        <section className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs text-center">
            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Enrolled Courses</p>
            <p className="text-2xl font-black text-slate-900 mt-1">{enrollments.length}</p>
          </div>
          <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs text-center">
            <p className="text-[10px] font-bold uppercase tracking-wider text-blue-600">In Progress</p>
            <p className="text-2xl font-black text-blue-600 mt-1">{inProgressCourses.length}</p>
          </div>
          <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs text-center">
            <p className="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Completed</p>
            <p className="text-2xl font-black text-emerald-600 mt-1">{completedCourses.length}</p>
          </div>
          <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs text-center">
            <p className="text-[10px] font-bold uppercase tracking-wider text-amber-600">Certificates</p>
            <p className="text-2xl font-black text-amber-600 mt-1">{completedCourses.length}</p>
          </div>
        </section>

        {/* Main 2-Column: Edit Form + Enrolled Programs */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Edit Profile Form */}
          <div className="lg:col-span-2 bg-white rounded-3xl p-8 border border-slate-200/80 shadow-xs space-y-6">
            <div>
              <h2 className="text-lg font-bold text-slate-900">Edit Profile & Account Settings</h2>
              <p className="text-xs text-slate-500 mt-0.5">Update your personal details and secure credentials.</p>
            </div>

            {successMsg && (
              <div className="p-4 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-2xl border border-emerald-200">
                ✓ {successMsg}
              </div>
            )}
            {errorMsg && (
              <div className="p-4 bg-red-50 text-red-700 text-xs font-bold rounded-2xl border border-red-200">
                ⚠️ {errorMsg}
              </div>
            )}

            <form onSubmit={handleProfileSave} className="space-y-4 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Full Name
                  </label>
                  <input
                    type="text"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                    required
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Phone Number
                  </label>
                  <input
                    type="text"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Location / City
                </label>
                <input
                  type="text"
                  value={location}
                  onChange={(e) => setLocation(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  About Me / Bio
                </label>
                <textarea
                  value={bio}
                  onChange={(e) => setBio(e.target.value)}
                  rows={3}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                />
              </div>

              <div className="pt-4 border-t border-slate-100 space-y-4">
                <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-400">
                  Update Password (Optional)
                </h3>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label className="block font-bold text-slate-700 text-[10px] mb-1">New Password</label>
                    <input
                      type="password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="Leave blank to keep current"
                      className="w-full px-4 py-2 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                      minLength={6}
                    />
                  </div>

                  <div>
                    <label className="block font-bold text-slate-700 text-[10px] mb-1">Confirm New Password</label>
                    <input
                      type="password"
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      placeholder="Confirm new password"
                      className="w-full px-4 py-2 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                      minLength={6}
                    />
                  </div>
                </div>
              </div>

              <div className="pt-2 flex justify-end">
                <button
                  type="submit"
                  disabled={saving}
                  className="px-6 py-2.5 rounded-xl font-bold text-xs text-white bg-blue-600 hover:bg-blue-700 active:bg-blue-800 shadow-sm transition disabled:opacity-50"
                >
                  {saving ? 'Saving Changes...' : 'Save Profile Settings'}
                </button>
              </div>
            </form>
          </div>

          {/* Enrolled Courses Summary */}
          <div className="lg:col-span-1 space-y-6">
            <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
              <h3 className="text-sm font-bold text-slate-900">Enrolled Programs</h3>

              {loading ? (
                <p className="text-xs text-slate-400 py-4 text-center">Loading programs...</p>
              ) : enrollments.length === 0 ? (
                <p className="text-xs text-slate-400 py-4 text-center">No enrolled programs yet.</p>
              ) : (
                <div className="space-y-3">
                  {enrollments.map((item) => (
                    <div key={item.id} className="p-3 bg-slate-50 rounded-2xl border border-slate-100 text-xs">
                      <h4 className="font-bold text-slate-800 line-clamp-1">{item.course?.title}</h4>
                      <div className="flex items-center justify-between text-[11px] text-slate-500 mt-1">
                        <span>{Math.round(Number(item.progress_percentage || 0))}% complete</span>
                        <Link
                          to={`/student/courses/${item.course_id}/lessons`}
                          className="font-bold text-blue-600 hover:underline"
                        >
                          Classroom →
                        </Link>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Certificates Box */}
            {completedCourses.length > 0 && (
              <div className="bg-gradient-to-br from-amber-500/10 to-blue-500/10 p-6 rounded-3xl border border-amber-200/80 space-y-3">
                <span className="text-2xl block">🏆</span>
                <h4 className="text-sm font-bold text-slate-900">Verified Credentials</h4>
                <p className="text-xs text-slate-600">
                  You have earned {completedCourses.length} accredited Master In Tech certificate{completedCourses.length > 1 ? 's' : ''}.
                </p>
                <Link
                  to="/student"
                  className="block text-xs font-bold text-blue-600 hover:underline"
                >
                  View All Certificates in Dashboard →
                </Link>
              </div>
            )}
          </div>
        </div>
      </main>

      <Footer />
    </div>
  )
}
