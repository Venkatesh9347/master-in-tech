import { useState, useEffect, useRef } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import API from '../services/api'
import PublicAccessGateModal from './PublicAccessGateModal'

interface NotificationItem {
  id: string
  title: string
  message: string
  time: string
  type: 'assignment' | 'quiz' | 'certificate' | 'event' | 'announcement'
  link?: string
  read: boolean
}

export default function Navbar() {
  const { user, logout } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)
  const [userDropdownOpen, setUserDropdownOpen] = useState(false)
  const [notifDropdownOpen, setNotifDropdownOpen] = useState(false)
  const [notifications, setNotifications] = useState<NotificationItem[]>([])
  const [enquiryOpen, setEnquiryOpen] = useState(false)
  const [placementEnabled, setPlacementEnabled] = useState(true)
  const [studentPlacementDashboardEnabled, setStudentPlacementDashboardEnabled] = useState(false)

  const userMenuRef = useRef<HTMLDivElement>(null)
  const notifMenuRef = useRef<HTMLDivElement>(null)

  // Fetch placement portal availability flag
  useEffect(() => {
    API.get<{ placement_enabled?: boolean; placementEnabled?: boolean }>('/placements/settings')
      .then((res) => {
        if (res.data) {
          const enabled = res.data.placement_enabled ?? res.data.placementEnabled ?? true
          setPlacementEnabled(enabled)
        }
      })
      .catch(() => {
        // Link stays visible; the Placements page itself fail-closes to
        // maintenance when settings are unreachable.
      })
  }, [])

  // Check student placement dashboard activation
  useEffect(() => {
    if (user && (user.role === 'student' || !user.role)) {
      API.get<{ placement_dashboard_enabled?: boolean }>('/student/placement-dashboard/status')
        .then((res) => {
          if (res.data?.placement_dashboard_enabled) {
            setStudentPlacementDashboardEnabled(true)
          } else {
            setStudentPlacementDashboardEnabled(false)
          }
        })
        .catch(() => {
          setStudentPlacementDashboardEnabled(false)
        })
    } else {
      setStudentPlacementDashboardEnabled(false)
    }
  }, [user])

  // Close dropdowns on click outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (userMenuRef.current && !userMenuRef.current.contains(event.target as Node)) {
        setUserDropdownOpen(false)
      }
      if (notifMenuRef.current && !notifMenuRef.current.contains(event.target as Node)) {
        setNotifDropdownOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  // Load student contextual notifications
  useEffect(() => {
    if (!user || user.role !== 'student') {
      setNotifications([])
      return
    }

    Promise.allSettled([
      API.get('/my-courses'),
      API.get('/my-events'),
    ]).then((results) => {
      const [coursesRes, eventsRes] = results
      const rawEnroll = coursesRes.status === 'fulfilled' ? coursesRes.value.data as unknown : null
      const enrollments = Array.isArray(rawEnroll)
        ? rawEnroll
        : Array.isArray((rawEnroll as { data?: unknown[] } | null)?.data)
        ? (rawEnroll as { data: unknown[] }).data
        : []

      const rawEvents = eventsRes.status === 'fulfilled' ? eventsRes.value.data as unknown : null
      const myEvents = Array.isArray(rawEvents)
        ? rawEvents
        : Array.isArray((rawEvents as { data?: unknown[] } | null)?.data)
        ? (rawEvents as { data: unknown[] }).data
        : []

      // If both feeds failed, show nothing rather than a fake Welcome —
      // an outage must never masquerade as an empty inbox.
      if (coursesRes.status === 'rejected' && eventsRes.status === 'rejected') {
        setNotifications([])
        return
      }

      const items: NotificationItem[] = []

      enrollments.forEach((e: { course_id: number; progress_percentage: number; course?: { title: string } }) => {
        if (Number(e.progress_percentage) >= 100) {
          items.push({
            id: `cert-${e.course_id}`,
            title: '🎓 Certificate Unlocked',
            message: `Your certificate for ${e.course?.title || 'your course'} is ready to view.`,
            time: 'Completed',
            type: 'certificate',
            link: `/student`,
            read: false,
          })
        } else if (Number(e.progress_percentage) > 0) {
          items.push({
            id: `progress-${e.course_id}`,
            title: '📖 Course in Progress',
            message: `Continue ${e.course?.title || 'your course'} (${Math.round(e.progress_percentage)}% done).`,
            time: 'In Progress',
            type: 'announcement',
            link: `/student/courses/${e.course_id}/lessons`,
            read: false,
          })
        }
      })

      myEvents.forEach((ev: { id: number; title: string; event_date: string }) => {
        items.push({
          id: `ev-${ev.id}`,
          title: '📅 Masterclass Registered',
          message: `You are registered for "${ev.title}".`,
          time: ev.event_date || 'Upcoming',
          type: 'event',
          link: '/student/events',
          read: false,
        })
      })

      if (items.length === 0) {
        items.push({
          id: 'welcome-1',
          title: '👋 Welcome to MasterInTech',
          message: 'Explore our catalog of technology and engineering courses.',
          time: 'Active',
          type: 'announcement',
          link: '/courses',
          read: false,
        })
      }

      setNotifications(items)
    })
  }, [user])

  const unreadCount = notifications.filter((n) => !n.read).length

  const markAllRead = () => {
    setNotifications((prev) => prev.map((n) => ({ ...n, read: true })))
  }

  const handleLogout = async () => {
    setUserDropdownOpen(false)
    setMobileMenuOpen(false)
    await logout()
    navigate('/')
  }

  // Navigation Links
  const getNavLinks = () => {
    if (!user) {
      const guestLinks = [
        { label: 'Courses', to: '/courses' },
        ...(placementEnabled ? [{ label: 'Placements', to: '/placements' }] : []),
        { label: 'Hire From Us', to: '/corporate-partner' },
        { label: 'Events', to: '/events' },
        { label: 'Resources', to: '/resources' },
        { label: 'Contact Us', to: 'tel:+919063627775', isPhone: true },
      ]
      return guestLinks
    }

    if (user.role === 'company' || user.role === 'recruiter') {
      return [
        { label: 'Company Portal', to: '/company' },
        { label: 'Job Postings', to: '/company/jobs' },
        { label: 'Applications', to: '/company/applications' },
        { label: 'Interviews', to: '/company/interviews' },
        { label: 'Placements', to: '/placements' },
        { label: 'Contact Us', to: 'tel:+919063627775', isPhone: true },
      ]
    }

    if (user.role === 'tutor' || user.role === 'faculty') {
      return [
        { label: 'Courses', to: '/courses' },
        ...(placementEnabled ? [{ label: 'Placements', to: '/placements' }] : []),
        { label: 'My Teaching', to: '/tutor' },
        { label: 'Students', to: '/tutor/students' },
        { label: 'Resources', to: '/resources' },
        { label: 'Contact Us', to: 'tel:+919063627775', isPhone: true },
      ]
    }

    if (user.role === 'admin' || user.role === 'super_admin') {
      return [
        { label: 'Courses', to: '/courses' },
        { label: 'Placements', to: '/placements' },
        { label: 'Admin Studio', to: '/admin' },
        { label: 'Events', to: '/events' },
        { label: 'Resources', to: '/resources' },
        { label: 'Contact Us', to: 'tel:+919063627775', isPhone: true },
      ]
    }

    // Default: Student Navigation
    return [
      { label: 'Courses', to: '/courses' },
      ...(placementEnabled ? [{ label: 'Placements', to: '/placements' }] : []),
      { label: 'My Learning', to: '/student' },
      { label: 'Live Events', to: '/student/events' },
      { label: 'Resources', to: '/resources' },
      { label: 'Contact Us', to: 'tel:+919063627775', isPhone: true },
    ]
  }

  const navLinks = getNavLinks()

  const isActiveLink = (to: string) => {
    if (to.startsWith('tel:')) return false
    if (to === '/' && location.pathname === '/') return true
    if (to !== '/' && location.pathname.startsWith(to)) return true
    return false
  }

  return (
    <header className="bg-slate-900 border-b border-slate-800/90 sticky top-0 z-50 shadow-sm">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex h-16 justify-between items-center">
          {/* Brand Logo */}
          <div className="flex items-center gap-3">
            <Link to="/" className="flex items-center gap-2.5 group">
              <div className="w-9 h-9 rounded-lg bg-blue-600 flex items-center justify-center text-white font-black text-lg shadow-sm group-hover:bg-blue-500 transition-colors">
                M
              </div>
              <div className="flex flex-col">
                <span className="text-lg font-extrabold tracking-tight text-white leading-tight">
                  Master<span className="text-blue-400">In</span>Tech
                </span>
                <span className="text-[9px] tracking-wider text-slate-400 font-semibold uppercase">
                  Technology Education
                </span>
              </div>
            </Link>
          </div>

          {/* Desktop Navigation Links */}
          <nav className="hidden md:flex items-center space-x-6">
            {navLinks.map((link) => {
              if (link.isPhone || link.to.startsWith('tel:')) {
                return (
                  <a
                    key={link.label}
                    href={link.to}
                    aria-label="Contact MasterInTech Admissions at +91 9063627775"
                    className="text-xs font-semibold tracking-wide text-slate-300 hover:text-white transition-colors duration-150 flex items-center gap-1.5"
                  >
                    <span className="text-blue-400">📞</span>
                    <span>{link.label}</span>
                  </a>
                )
              }

              const active = isActiveLink(link.to)
              return (
                <Link
                  key={link.label}
                  to={link.to}
                  className={`text-xs font-semibold tracking-wide transition-colors duration-150 ${
                    active ? 'text-blue-400 font-bold' : 'text-slate-300 hover:text-white'
                  }`}
                >
                  {link.label}
                </Link>
              )
            })}
          </nav>

          {/* Desktop Right Actions */}
          <div className="hidden md:flex items-center gap-3">
            {user ? (
              <div className="flex items-center gap-3">
                {/* Notifications Dropdown */}
                {user.role === 'student' && (
                  <div className="relative" ref={notifMenuRef}>
                    <button
                      type="button"
                      onClick={() => setNotifDropdownOpen(!notifDropdownOpen)}
                      className="p-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition relative focus:outline-none"
                      aria-label="Notifications"
                    >
                      <span className="text-sm">🔔</span>
                      {unreadCount > 0 && (
                        <span className="absolute top-1 right-1 w-3.5 h-3.5 rounded-full bg-blue-500 text-white text-[8px] font-bold flex items-center justify-center">
                          {unreadCount}
                        </span>
                      )}
                    </button>

                    {notifDropdownOpen && (
                      <div className="absolute right-0 mt-2 w-80 bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl py-2 z-50 text-xs">
                        <div className="px-4 py-2 border-b border-slate-800 flex items-center justify-between">
                          <span className="font-bold text-white">Learning Notifications</span>
                          {unreadCount > 0 && (
                            <button
                              type="button"
                              onClick={markAllRead}
                              className="text-[10px] text-blue-400 hover:underline"
                            >
                              Mark all read
                            </button>
                          )}
                        </div>
                        <div className="max-h-64 overflow-y-auto divide-y divide-slate-800/60">
                          {notifications.map((n) => (
                            <div
                              key={n.id}
                              onClick={() => {
                                if (n.link) navigate(n.link)
                                setNotifDropdownOpen(false)
                              }}
                              className="p-3 hover:bg-slate-800/60 cursor-pointer transition flex items-start gap-2.5"
                            >
                              <span className="text-base shrink-0">
                                {n.type === 'certificate' ? '🎓' : n.type === 'event' ? '📅' : '📖'}
                              </span>
                              <div className="min-w-0">
                                <p className="font-bold text-slate-200 truncate">{n.title}</p>
                                <p className="text-slate-400 text-[11px] line-clamp-2">{n.message}</p>
                                <span className="text-[9px] text-slate-500 mt-0.5 block">{n.time}</span>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {/* User Profile Menu */}
                <div className="relative" ref={userMenuRef}>
                  <button
                    type="button"
                    onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                    className="flex items-center gap-2 p-1.5 rounded-xl bg-slate-800 border border-slate-700/70 hover:bg-slate-700/60 transition"
                  >
                    <div className="w-7 h-7 rounded-lg bg-blue-600 text-white font-bold flex items-center justify-center text-xs uppercase">
                      {user.name?.charAt(0) || 'U'}
                    </div>
                    <span className="text-xs font-bold text-slate-200 max-w-[100px] truncate pr-1">
                      {user.name}
                    </span>
                    <span className="text-[10px] text-slate-400">▼</span>
                  </button>

                  {userDropdownOpen && (
                    <div className="absolute right-0 mt-2 w-52 bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl py-2 z-50 text-xs">
                      <div className="px-4 py-2 border-b border-slate-800">
                        <p className="font-bold text-white truncate">{user.name}</p>
                        <p className="text-[10px] text-slate-400 truncate">{user.email}</p>
                        <span className="inline-block mt-1 px-2 py-0.5 rounded-full text-[9px] font-extrabold uppercase bg-blue-950 text-blue-300 border border-blue-800">
                          {user.role === 'super_admin' ? 'Super Admin' : user.role === 'admin' ? 'Admin' : user.role === 'tutor' || user.role === 'faculty' ? 'Faculty' : 'Student'}
                        </span>
                      </div>

                      {(user.role === 'admin' || user.role === 'super_admin') && (
                        <>
                          <Link
                            to="/admin"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            ⚙️ Admin Console
                          </Link>
                          <Link
                            to="/admin/users"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            👥 User Management
                          </Link>
                          <Link
                            to="/admin/enrollments"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            🎓 Student Enrollments
                          </Link>
                          <Link
                            to="/admin/batches"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            📦 Batch Management
                          </Link>
                        </>
                      )}

                      {(user.role === 'tutor' || user.role === 'faculty') && (
                        <>
                          <Link
                            to="/tutor"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            👨‍🏫 Faculty Studio
                          </Link>
                          <Link
                            to="/tutor/materials"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            📁 Learning Materials
                          </Link>
                          <Link
                            to="/tutor/quizzes"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            📝 Quizzes & Tests
                          </Link>
                          <Link
                            to="/tutor/submissions"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            📊 Grading Desk
                          </Link>
                        </>
                      )}

                      {user.role === 'student' && (
                        <>
                          <Link
                            to="/student"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            🎓 Student LMS
                          </Link>
                          <Link
                            to="/student/mock-interview"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-purple-300 hover:bg-slate-800 hover:text-purple-200 font-semibold"
                          >
                            🎙️ Mock Interview
                          </Link>
                          {studentPlacementDashboardEnabled && (
                            <Link
                              to="/placements"
                              onClick={() => setUserDropdownOpen(false)}
                              className="block px-4 py-2 text-emerald-300 hover:bg-slate-800 hover:text-emerald-200 font-semibold"
                            >
                              🚀 Placement Portal
                            </Link>
                          )}
                          <Link
                            to="/student/events"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            📅 Live Masterclasses
                          </Link>
                          <Link
                            to="/student/profile"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white"
                          >
                            👤 My Profile
                          </Link>
                          <Link
                            to="/ai-assistant"
                            onClick={() => setUserDropdownOpen(false)}
                            className="block px-4 py-2 text-blue-300 hover:bg-slate-800 hover:text-blue-200 font-semibold"
                          >
                            🤖 AI Assistant
                          </Link>
                        </>
                      )}

                      <div className="border-t border-slate-800 mt-1 pt-1">
                        <button
                          type="button"
                          onClick={handleLogout}
                          className="w-full text-left px-4 py-2 text-red-400 hover:bg-slate-800 hover:text-red-300 font-semibold"
                        >
                          Sign Out
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              </div>
            ) : (
              <div className="flex items-center gap-2">
                <Link
                  to="/login"
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white hover:bg-slate-800 transition"
                >
                  Sign In
                </Link>
                <button
                  type="button"
                  onClick={() => setEnquiryOpen(true)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-500 transition shadow-sm"
                >
                  Enquire Now
                </button>
              </div>
            )}
          </div>

          {/* Mobile Menu Button */}
          <div className="flex md:hidden items-center gap-2">
            <button
              type="button"
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              className="p-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800"
              aria-label="Toggle Menu"
            >
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                {mobileMenuOpen ? (
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                ) : (
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                )}
              </svg>
            </button>
          </div>
        </div>
      </div>

      {/* Mobile Drawer */}
      {mobileMenuOpen && (
        <div className="md:hidden bg-slate-900 border-b border-slate-800 px-4 pt-2 pb-4 space-y-2">
          {navLinks.map((link) => {
            if (link.isPhone || link.to.startsWith('tel:')) {
              return (
                <a
                  key={link.label}
                  href={link.to}
                  onClick={() => setMobileMenuOpen(false)}
                  aria-label="Call MasterInTech Admissions at +91 9063627775"
                  className="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-300 hover:bg-slate-800 hover:text-white flex items-center gap-2"
                >
                  <span className="text-blue-400">📞</span>
                  <span>{link.label} (+91 9063627775)</span>
                </a>
              )
            }

            return (
              <Link
                key={link.label}
                to={link.to}
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-300 hover:bg-slate-800 hover:text-white"
              >
                {link.label}
              </Link>
            )
          })}

          <div className="pt-2 border-t border-slate-800 space-y-2">
            {user ? (
              <>
                <div className="px-3 py-1 text-xs text-slate-400 font-medium">
                  Signed in as <span className="text-white font-bold">{user.name}</span>
                </div>
                <button
                  type="button"
                  onClick={handleLogout}
                  className="w-full text-left px-3 py-2 rounded-lg text-xs font-semibold text-red-400 hover:bg-slate-800"
                >
                  Sign Out
                </button>
              </>
            ) : (
              <div className="grid grid-cols-2 gap-2 pt-1">
                <Link
                  to="/login"
                  onClick={() => setMobileMenuOpen(false)}
                  className="text-center py-2 text-xs font-semibold text-slate-300 bg-slate-800 rounded-lg"
                >
                  Sign In
                </Link>
                <button
                  type="button"
                  onClick={() => {
                    setMobileMenuOpen(false)
                    setEnquiryOpen(true)
                  }}
                  className="text-center py-2 text-xs font-bold text-white bg-blue-600 rounded-lg"
                >
                  Enquire Now
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Public Enquiry Modal */}
      <PublicAccessGateModal
        isOpen={enquiryOpen}
        onClose={() => setEnquiryOpen(false)}
      />
    </header>
  )
}
