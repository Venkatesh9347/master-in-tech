import { useEffect, useRef } from 'react'
import { Link, useLocation, useNavigate, Outlet } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'

export default function AdminLayout() {
  const { user, logout } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const activeTabRef = useRef<HTMLAnchorElement | null>(null)

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  const isCounsellor = user?.role === 'counsellor'

  const adminNavLinks = [
    { label: 'Overview & Catalog', to: '/admin', icon: '📊' },
    { label: 'CRM & Pipeline', to: '/admin/crm', icon: '🎯' },
    { label: 'Leads & Enquiries', to: '/admin/enquiries', icon: '📬' },
    { label: 'Users & Students', to: '/admin/users', icon: '👥' },
    { label: 'Student Enrollments', to: '/admin/enrollments', icon: '🎓' },
    { label: 'Batch Management', to: '/admin/batches', icon: '📦' },
    { label: 'Placement Drives', to: '/admin/placements', icon: '💼' },
    { label: 'Live Classes', to: '/admin/class-sessions', icon: '🎥' },
    { label: 'Class History', to: '/admin/class-history', icon: '📜' },
    { label: 'Faculty Permissions', to: '/admin/tutor-permissions', icon: '🛡️' },
    { label: 'Categories', to: '/admin/categories', icon: '🏷️' },
    { label: 'Home CMS', to: '/admin/home-cms', icon: '🏠' },
    { label: 'Faculty Mentors', to: '/admin/instructors', icon: '👨‍🏫' },
    { label: 'Roadmaps', to: '/admin/learning-paths', icon: '🗺️' },
    { label: 'Testimonials', to: '/admin/testimonials', icon: '💬' },
    { label: 'FAQs', to: '/admin/faqs', icon: '❓' },
    { label: 'Resources', to: '/admin/resources', icon: '💡' },
    { label: 'Settings', to: '/admin/settings', icon: '⚙️' },
    { label: 'Navigation', to: '/admin/navigation', icon: '🧭' },
    { label: 'Media Library', to: '/admin/media', icon: '🖼️' },
    { label: 'Events', to: '/admin/events', icon: '📅' },
    { label: 'Grading', to: '/admin/submissions', icon: '📝' },
    { label: 'Audit Logs', to: '/admin/audit-logs', icon: '📋' },
  ]

  const counsellorNavLinks = [
    { label: 'CRM & Pipeline', to: '/admin/crm', icon: '🎯' },
    { label: 'Leads & Enquiries', to: '/admin/enquiries', icon: '📬' },
  ]

  const navLinks = isCounsellor ? counsellorNavLinks : adminNavLinks

  useEffect(() => {
    if (activeTabRef.current) {
      activeTabRef.current.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'center',
      })
    }
  }, [location.pathname])

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100 flex flex-col selection:bg-purple-600 selection:text-white">
      {/* Admin Top Navigation */}
      <header className="bg-slate-950 border-b border-slate-800 sticky top-0 z-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link to="/admin" className="flex items-center gap-2">
              <span className="w-8 h-8 rounded-xl bg-purple-600 flex items-center justify-center text-white font-black text-sm shadow-md">
                A
              </span>
              <span className="font-extrabold text-lg tracking-tight text-white">
                Admin<span className="text-purple-400">Console</span>
              </span>
            </Link>
            <span className="hidden sm:inline-block px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-purple-950/80 text-purple-300 border border-purple-800">
              Master In Tech Core
            </span>
          </div>

          <div className="flex items-center gap-4">
            <Link
              to="/"
              className="text-xs font-semibold text-slate-400 hover:text-white transition flex items-center gap-1"
            >
              <span>🌐</span> Public Site
            </Link>

            <div className="h-4 w-[1px] bg-slate-800" />

            <div className="flex items-center gap-3">
              <div className="text-right hidden sm:block">
                <p className="text-xs font-bold text-white leading-tight">{user?.name}</p>
                <p className="text-[10px] text-purple-400 font-semibold uppercase">
                  {user?.role === 'super_admin'
                    ? 'Super Administrator'
                    : user?.role === 'counsellor'
                      ? 'Admissions Counsellor'
                      : 'Administrator'}
                </p>
              </div>
              <button
                type="button"
                onClick={handleLogout}
                className="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
              >
                Logout
              </button>
            </div>
          </div>
        </div>
      </header>

      {/* Admin Body */}
      <div className="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
        {/* Navigation Tabs Bar */}
        <nav className="flex flex-wrap items-center gap-2 pb-4 mb-8 border-b border-slate-800">
          {navLinks.map((link) => {
            const isActive =
              location.pathname === link.to ||
              (link.to === '/admin' && location.pathname === '/admin/dashboard')
            return (
              <Link
                key={link.to}
                to={link.to}
                ref={isActive ? activeTabRef : undefined}
                className={`px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2 ${
                  isActive
                    ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30 ring-1 ring-purple-400/50'
                    : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'
                }`}
              >
                <span>{link.icon}</span>
                <span>{link.label}</span>
              </Link>
            )
          })}
        </nav>

        {/* Outlet for Nested Admin Pages */}
        <Outlet />
      </div>
    </div>
  )
}
