import { NavLink, Outlet, useNavigate, Link } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'

export default function CompanyLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const navLinks = [
    { label: 'Overview', to: '/company', icon: '📊', end: true },
    { label: 'Job Postings', to: '/company/jobs', icon: '💼' },
    { label: 'Applications', to: '/company/applications', icon: '👥' },
    { label: 'Interviews', to: '/company/interviews', icon: '📅' },
    { label: 'Company Profile', to: '/company/profile', icon: '🏢' },
  ]

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100 flex flex-col selection:bg-purple-600 selection:text-white">
      {/* Top Corporate Navigation Bar */}
      <header className="sticky top-0 z-40 bg-slate-950/90 backdrop-blur border-b border-slate-800/80">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link to="/company" className="flex items-center gap-2.5 group">
              <span className="w-8 h-8 rounded-xl bg-purple-600 flex items-center justify-center text-white font-black text-sm shadow-md shadow-purple-600/40 group-hover:scale-105 transition">
                M
              </span>
              <div>
                <span className="text-sm font-black text-white tracking-tight block">MasterInTech</span>
                <span className="text-[10px] font-bold text-purple-400 uppercase tracking-widest block">
                  Corporate Partner Portal
                </span>
              </div>
            </Link>
          </div>

          <div className="flex items-center gap-3">
            <Link
              to="/placements"
              target="_blank"
              className="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-850 border border-slate-800 text-xs font-bold text-slate-300 hover:text-white transition"
            >
              <span>🌐</span>
              <span>View Public Placement Portal ↗</span>
            </Link>

            <div className="flex items-center gap-2.5 pl-3 border-l border-slate-800">
              <div className="text-right hidden sm:block">
                <p className="text-xs font-bold text-white">{user?.name || 'Recruiter'}</p>
                <p className="text-[10px] text-purple-300 font-mono">{user?.email}</p>
              </div>

              <button
                type="button"
                onClick={handleLogout}
                className="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-rose-950/60 border border-slate-800 hover:border-rose-800 text-xs font-bold text-slate-300 hover:text-rose-300 transition"
              >
                Logout
              </button>
            </div>
          </div>
        </div>

        {/* Secondary Sub-Navbar for Navigation Tabs */}
        <div className="bg-slate-950 border-t border-slate-800/60 overflow-x-auto scrollbar-none">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center gap-1 py-1">
            {navLinks.map((link) => (
              <NavLink
                key={link.to}
                to={link.to}
                end={link.end}
                className={({ isActive }) =>
                  `px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 ${
                    isActive
                      ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30 font-black'
                      : 'text-slate-400 hover:text-white hover:bg-slate-900'
                  }`
                }
              >
                <span>{link.icon}</span>
                <span>{link.label}</span>
              </NavLink>
            ))}
          </div>
        </div>
      </header>

      {/* Main Page Body */}
      <main className="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
        <Outlet />
      </main>
    </div>
  )
}
