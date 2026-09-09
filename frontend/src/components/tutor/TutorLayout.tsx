import { Link, useLocation, useNavigate, Outlet } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'

export default function TutorLayout() {
  const { user, logout } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  const navLinks = [
    { label: 'Overview & Metrics', to: '/tutor', icon: '📊' },
    { label: 'My Courses', to: '/tutor/courses', icon: '📚' },
    { label: 'Learning Materials', to: '/tutor/materials', icon: '📄' },
    { label: 'Quizzes & Tests', to: '/tutor/quizzes', icon: '📝' },
    { label: 'Grading Desk', to: '/tutor/submissions', icon: '✍️' },
  ]

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-600 selection:text-white">
      {/* Tutor Top Navigation */}
      <header className="bg-white border-b border-slate-200/80 sticky top-0 z-50 shadow-xs">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-18 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link to="/tutor" className="flex items-center gap-2.5 group">
              <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center text-white font-black text-xl shadow-md shadow-blue-500/20 group-hover:scale-105 transition-transform">
                M
              </div>
              <div className="flex flex-col">
                <span className="text-lg font-extrabold tracking-tight text-slate-900 leading-tight">
                  Master<span className="text-blue-600">In</span>Tech
                </span>
                <span className="text-[10px] tracking-widest text-blue-600 font-bold uppercase">
                  Instructor Studio
                </span>
              </div>
            </Link>
          </div>

          <div className="flex items-center gap-4">
            <Link
              to="/"
              className="text-xs font-semibold text-slate-500 hover:text-slate-900 transition flex items-center gap-1"
            >
              <span>🌐</span> Public Site
            </Link>

            <div className="h-4 w-[1px] bg-slate-200" />

            <div className="flex items-center gap-3">
              <Link
                to="/tutor/profile"
                className="flex items-center gap-3 p-1 rounded-xl hover:bg-slate-100/80 transition group"
                title="View Faculty Profile"
              >
                <div className="w-8 h-8 rounded-full bg-blue-100 text-blue-700 font-bold text-xs flex items-center justify-center border border-blue-200 group-hover:border-blue-400 group-hover:scale-105 transition">
                  {user?.name ? user.name.charAt(0).toUpperCase() : 'T'}
                </div>
                <div className="text-left hidden sm:block">
                  <p className="text-xs font-bold text-slate-900 leading-tight group-hover:text-blue-600 transition">
                    {user?.name}
                  </p>
                  <span className="bg-blue-50 text-blue-700 text-[9px] font-extrabold uppercase px-2 py-0.5 rounded-full border border-blue-200">
                    {user?.role === 'faculty' || user?.role === 'tutor' ? 'Faculty Instructor' : 'Faculty'}
                  </span>
                </div>
              </Link>
              <button
                type="button"
                onClick={handleLogout}
                className="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 hover:text-red-600 hover:bg-red-50 border border-slate-200 transition"
              >
                Logout
              </button>
            </div>
          </div>
        </div>
      </header>

      {/* Tutor Body Container */}
      <div className="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full space-y-6">
        {/* Navigation Tabs Bar */}
        <nav className="flex items-center gap-2 overflow-x-auto pb-2 border-b border-slate-200/80">
          {navLinks.map((link) => {
            const isActive =
              link.to === '/tutor'
                ? location.pathname === '/tutor'
                : location.pathname.startsWith(link.to)
            return (
              <Link
                key={link.to}
                to={link.to}
                className={`px-4 py-2.5 rounded-2xl text-xs font-bold transition flex items-center gap-2 shrink-0 ${
                  isActive
                    ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20'
                    : 'bg-white text-slate-600 hover:text-slate-900 hover:bg-slate-100/80 border border-slate-200/80'
                }`}
              >
                <span>{link.icon}</span>
                <span>{link.label}</span>
              </Link>
            )
          })}
        </nav>

        {/* Nested Outlet for Tutor Sub-pages */}
        <Outlet />
      </div>
    </div>
  )
}
