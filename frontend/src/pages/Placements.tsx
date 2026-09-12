import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'

export type PlacementStatus =
  | 'applied'
  | 'under_review'
  | 'shortlisted'
  | 'interview_scheduled'
  | 'selected'
  | 'rejected'
  | 'joined'

export interface PlacementOpportunityItem {
  id: number
  title: string
  company_name: string
  company_logo?: string | null
  job_code?: string | null
  location: string
  employment_type: string
  salary_package?: string | null
  experience_required?: string | null
  eligibility?: string | null
  eligible_courses?: string[] | number[] | null
  eligible_batches?: string[] | null
  skills_required?: string[] | null
  description: string
  selection_process?: string | null
  openings_count: number
  deadline_date?: string | null
  status: 'published' | 'draft' | 'closed'
  is_featured: boolean
  applications_count?: number
  created_at: string
}

export interface StudentVerifiedPrefill {
  user_id: number
  student_name: string
  email: string
  phone: string
  student_id?: string | null
  enrolled_courses: { id: number; title: string; code?: string }[]
  active_batches: { id: number; name: string; code: string; course_id: number; course_title?: string; start_date?: string }[]
}

export interface PlacementApplicationItem {
  id: number
  placement_opportunity_id: number
  user_id: number
  batch_id: number
  batch_code: string
  student_name: string
  email: string
  phone: string
  course_id?: number | null
  course_title?: string | null
  resume_url: string
  cover_note?: string | null
  status: PlacementStatus
  interview_date?: string | null
  interview_notes?: string | null
  admin_notes?: string | null
  applied_at: string
  opportunity?: PlacementOpportunityItem | null
  batch?: { id: number; name: string; code: string; start_date?: string } | null
  course?: { id: number; title: string; code?: string } | null
}

const statusStepperConfig: Record<PlacementStatus, { step: number; label: string; color: string; icon: string }> = {
  applied: { step: 1, label: 'Applied', color: 'bg-blue-600 text-blue-100 border-blue-500', icon: '📝' },
  under_review: { step: 2, label: 'Under Review', color: 'bg-amber-600 text-amber-100 border-amber-500', icon: '🔍' },
  shortlisted: { step: 3, label: 'Shortlisted', color: 'bg-purple-600 text-purple-100 border-purple-500', icon: '⭐' },
  interview_scheduled: { step: 4, label: 'Interview Scheduled', color: 'bg-cyan-600 text-cyan-100 border-cyan-500', icon: '📅' },
  selected: { step: 5, label: 'Selected & Offered', color: 'bg-emerald-600 text-emerald-100 border-emerald-500', icon: '🎉' },
  rejected: { step: 2, label: 'Not Selected', color: 'bg-rose-600 text-rose-100 border-rose-500', icon: '✕' },
  joined: { step: 6, label: 'Joined Company', color: 'bg-teal-600 text-teal-100 border-teal-500', icon: '🚀' },
}

export default function Placements() {
  const { user } = useAuth()

  // Tabs: 'jobs_catalog' | 'my_applications'
  const [activeTab, setActiveTab] = useState<'jobs_catalog' | 'my_applications'>('jobs_catalog')

  // Opportunities List State
  const [opportunities, setOpportunities] = useState<PlacementOpportunityItem[]>([])
  const [loadingOpportunities, setLoadingOpportunities] = useState(true)
  const [search, setSearch] = useState('')
  const [locationFilter, setLocationFilter] = useState('all')
  const [employmentFilter, setEmploymentFilter] = useState('all')

  // Placement Control Settings
  const [placementEnabled, setPlacementEnabled] = useState(true)
  const [jobApplicationsEnabled, setJobApplicationsEnabled] = useState(true)
  const [mockInterviewRequired, setMockInterviewRequired] = useState(true)
  // Fail-closed: if the settings endpoint is unreachable we must not assume
  // the portal is open — show maintenance instead of an empty/mocked portal.
  const [settingsUnreachable, setSettingsUnreachable] = useState(false)

  // My Applications State
  const [myApplications, setMyApplications] = useState<PlacementApplicationItem[]>([])
  const [loadingMyApps, setLoadingMyApps] = useState(false)

  // Modals & Detail
  const [detailOpportunity, setDetailOpportunity] = useState<PlacementOpportunityItem | null>(null)
  const [applyingOpportunity, setApplyingOpportunity] = useState<PlacementOpportunityItem | null>(null)

  // Student Verified Prefill Data
  const [studentPrefill, setStudentPrefill] = useState<StudentVerifiedPrefill | null>(null)
  const [loadingPrefill, setLoadingPrefill] = useState(false)

  // Application Form State
  const [appPhone, setAppPhone] = useState('')
  const [appBatchId, setAppBatchId] = useState<string | number>('')
  const [appCustomBatchNumber, setAppCustomBatchNumber] = useState('')
  const [appCourseId, setAppCourseId] = useState<string | number>('')
  const [appResumeUrl, setAppResumeUrl] = useState('')
  const [appCoverNote, setAppCoverNote] = useState('')
  const [submittingApp, setSubmittingApp] = useState(false)
  const [appSuccessMsg, setAppSuccessMsg] = useState('')
  const [appErrorMsg, setAppErrorMsg] = useState('')

  // 0. Fetch Placement Settings
  const fetchSettings = useCallback(async () => {
    try {
      const res = await API.get<{
        placement_enabled?: boolean
        job_applications_enabled?: boolean
        mock_interview_required?: boolean
        placementEnabled?: boolean
        jobApplicationsEnabled?: boolean
        mockInterviewRequired?: boolean
      }>('/placements/settings')
      if (res.data) {
        setPlacementEnabled(res.data.placement_enabled ?? res.data.placementEnabled ?? true)
        setJobApplicationsEnabled(res.data.job_applications_enabled ?? res.data.jobApplicationsEnabled ?? true)
        setMockInterviewRequired(res.data.mock_interview_required ?? res.data.mockInterviewRequired ?? true)
        setSettingsUnreachable(false)
      }
    } catch {
      setSettingsUnreachable(true)
    }
  }, [])

  // 1. Fetch Published Job Opportunities
  const [opportunitiesError, setOpportunitiesError] = useState(false)
  const fetchOpportunities = useCallback(async () => {
    setLoadingOpportunities(true)
    setOpportunitiesError(false)
    try {
      const params = new URLSearchParams()
      if (search.trim()) params.append('search', search.trim())
      if (locationFilter !== 'all') params.append('location', locationFilter)
      if (employmentFilter !== 'all') params.append('employment_type', employmentFilter)

      const res = await API.get<{ data?: PlacementOpportunityItem[] } | PlacementOpportunityItem[]>(
        `/placements/opportunities?${params.toString()}`
      )
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setOpportunities(items)
    } catch {
      setOpportunitiesError(true)
    } finally {
      setLoadingOpportunities(false)
    }
  }, [search, locationFilter, employmentFilter])

  // 2. Fetch My Applications
  const fetchMyApplications = useCallback(async () => {
    if (!user) return
    setLoadingMyApps(true)
    try {
      const res = await API.get<PlacementApplicationItem[]>('/placements/my-applications')
      setMyApplications(Array.isArray(res.data) ? res.data : [])
    } catch {
      // Non-blocking
    } finally {
      setLoadingMyApps(false)
    }
  }, [user])

  // 3. Fetch Verified Student Profile Prefill
  const fetchPrefill = useCallback(async () => {
    if (!user) return
    setLoadingPrefill(true)
    try {
      const res = await API.get<StudentVerifiedPrefill>('/placements/profile-prefill')
      setStudentPrefill(res.data)
      setAppPhone(res.data.phone || '')
      if (res.data.active_batches && res.data.active_batches.length > 0) {
        setAppBatchId(res.data.active_batches[0].id)
        setAppCourseId(res.data.active_batches[0].course_id)
      } else if (res.data.enrolled_courses && res.data.enrolled_courses.length > 0) {
        setAppCourseId(res.data.enrolled_courses[0].id)
      }
    } catch {
      // Non-blocking
    } finally {
      setLoadingPrefill(false)
    }
  }, [user])

  useEffect(() => {
    fetchSettings()
    fetchOpportunities()
  }, [fetchSettings, fetchOpportunities])

  useEffect(() => {
    if (activeTab === 'my_applications') {
      fetchMyApplications()
    }
  }, [activeTab, fetchMyApplications])

  // Open Application Modal
  const handleOpenApplyModal = (opp: PlacementOpportunityItem) => {
    setApplyingOpportunity(opp)
    setAppSuccessMsg('')
    setAppErrorMsg('')
    setAppCoverNote('')
    setAppResumeUrl('')
    if (user) {
      fetchPrefill()
    }
  }

  // Submit Application
  const handleSubmitApplication = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!applyingOpportunity || !user) return

    setSubmittingApp(true)
    setAppErrorMsg('')
    setAppSuccessMsg('')

    const payload = {
      phone: appPhone.trim(),
      batch_id: appBatchId ? Number(appBatchId) : undefined,
      batch_number: !appBatchId && appCustomBatchNumber.trim() ? appCustomBatchNumber.trim() : undefined,
      course_id: appCourseId ? Number(appCourseId) : undefined,
      resume_url: appResumeUrl.trim(),
      cover_note: appCoverNote.trim() || undefined,
    }

    try {
      const res = await API.post<{ message: string }>(
        `/placements/opportunities/${applyingOpportunity.id}/apply`,
        payload
      )
      setAppSuccessMsg(res.data.message || 'Application submitted successfully!')
      fetchOpportunities()
      if (user) fetchMyApplications()
      setTimeout(() => {
        setApplyingOpportunity(null)
        setAppSuccessMsg('')
      }, 3000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const msg = resp.response?.data?.message || 'Failed to submit application.'
      setAppErrorMsg(msg)
    } finally {
      setSubmittingApp(false)
    }
  }

  // Filtered Locations List
  const locationOptions = useMemo(() => {
    const set = new Set<string>()
    opportunities.forEach((o) => {
      if (o.location) set.add(o.location)
    })
    return Array.from(set)
  }, [opportunities])

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100 flex flex-col selection:bg-purple-600 selection:text-white">
      <Navbar />

      {/* Hero Section */}
      <section className="relative overflow-hidden bg-slate-950 border-b border-slate-800/80 pt-12 pb-14">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_80%_80%_at_50%_-20%,rgba(120,119,198,0.18),rgba(255,255,255,0))]" />

        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div className="max-w-2xl">
              <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-950/80 border border-purple-800 text-purple-300 text-xs font-bold uppercase tracking-wider mb-4">
                <span>💼</span> MasterInTech Career & Placement Cell
              </div>
              <h1 className="text-3xl sm:text-4xl lg:text-5xl font-black text-white tracking-tight leading-tight">
                Placement Portal & <span className="text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-indigo-300">Hiring Drives</span>
              </h1>
              <p className="text-slate-400 text-sm sm:text-base mt-3 leading-relaxed">
                Connect with leading technology enterprises, top MNCs, and high-growth innovators. Apply directly with your verified MasterInTech batch cohort credentials.
              </p>
            </div>

            {/* Quick Portal Switcher */}
            <div className="flex items-center gap-3 bg-slate-900/90 p-2 rounded-2xl border border-slate-800 self-start md:self-auto shrink-0 shadow-lg">
              <button
                type="button"
                onClick={() => setActiveTab('jobs_catalog')}
                className={`px-4 py-2.5 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
                  activeTab === 'jobs_catalog'
                    ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-800'
                }`}
              >
                <span>🏢</span>
                <span>Active Opportunities ({opportunities.length})</span>
              </button>

              {user && (
                <button
                  type="button"
                  onClick={() => {
                    setActiveTab('my_applications')
                    fetchMyApplications()
                  }}
                  className={`px-4 py-2.5 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
                    activeTab === 'my_applications'
                      ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                      : 'text-slate-400 hover:text-white hover:bg-slate-800'
                  }`}
                >
                  <span>📋</span>
                  <span>My Applications</span>
                  {myApplications.length > 0 && (
                    <span className="px-1.5 py-0.5 rounded-full text-[10px] font-black bg-purple-900 text-purple-200">
                      {myApplications.length}
                    </span>
                  )}
                </button>
              )}
            </div>
          </div>
        </div>
      </section>

      {/* Main Content Area */}
      <main className="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full">
        {(!placementEnabled || settingsUnreachable) && (!user || (user.role !== 'admin' && user.role !== 'super_admin')) ? (
          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-12 text-center text-slate-300 shadow-xl space-y-4 max-w-2xl mx-auto my-8">
            <span className="text-5xl block">🔒</span>
            <h2 className="text-xl font-black text-white">Placement Portal Maintenance</h2>
            <p className="text-xs text-slate-400 leading-relaxed max-w-lg mx-auto">
              The Placement Portal is temporarily offline for scheduled cohort preparation and administrative updates. Please check back shortly or reach out to the MasterInTech Placement Cell for assistance.
            </p>
            <div className="pt-2">
              <Link to="/courses" className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-500 transition shadow-md shadow-blue-600/30 inline-block">
                Explore Courses & Cohorts
              </Link>
            </div>
          </div>
        ) : (
          <>
            {/* VIEW 1: ACTIVE JOBS CATALOG */}
            {activeTab === 'jobs_catalog' && (
              <div className="space-y-8">
                {!jobApplicationsEnabled && (
                  <div className="bg-amber-950/80 border border-amber-800 text-amber-300 px-5 py-3 rounded-2xl text-xs font-semibold flex items-center gap-2 shadow-lg">
                    <span>⏸️</span>
                    <span>Job applications are currently paused by administration. Active drives remain visible for curriculum alignment and candidate preparation.</span>
                  </div>
                )}

                {/* Search & Filter Bar */}
                <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-5 shadow-xl">
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">
                {/* Keyword Search */}
                <div className="lg:col-span-6 relative">
                  <span className="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500 text-xs">
                    🔍
                  </span>
                  <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search by job title, company name, required skill, or keywords..."
                    className="w-full pl-9 pr-8 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                  />
                  {search && (
                    <button
                      type="button"
                      onClick={() => setSearch('')}
                      className="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-white text-xs"
                    >
                      ✕
                    </button>
                  )}
                </div>

                {/* Location Filter */}
                <div className="lg:col-span-3">
                  <select
                    value={locationFilter}
                    onChange={(e) => setLocationFilter(e.target.value)}
                    className="w-full px-3 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                  >
                    <option value="all">All Job Locations</option>
                    {locationOptions.map((loc) => (
                      <option key={loc} value={loc}>
                        {loc}
                      </option>
                    ))}
                  </select>
                </div>

                {/* Employment Type Filter */}
                <div className="lg:col-span-3">
                  <select
                    value={employmentFilter}
                    onChange={(e) => setEmploymentFilter(e.target.value)}
                    className="w-full px-3 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                  >
                    <option value="all">All Employment Types</option>
                    <option value="Full-time">Full-time Roles</option>
                    <option value="Internship">Internship + PPO</option>
                    <option value="Contract">Contract / Specialist</option>
                  </select>
                </div>
              </div>
            </div>

            {/* Opportunities Grid */}
            {loadingOpportunities ? (
              <div className="py-20 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
                <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
                <span>Loading available placement opportunities...</span>
              </div>
            ) : opportunities.length === 0 ? (
              <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-3">
                <span className="text-4xl">{opportunitiesError ? '⚠️' : '📭'}</span>
                <h3 className="font-extrabold text-base text-slate-300">
                  {opportunitiesError ? 'Could Not Load Job Drives' : 'No Job Drives Found'}
                </h3>
                <p className="text-xs text-slate-400 max-w-md mx-auto">
                  {opportunitiesError
                    ? 'We could not reach the placement board. Please check your connection and try again.'
                    : 'No published job listings match your current filters. Check back soon for upcoming corporate drives.'}
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {opportunities.map((opp) => {
                  const deadlineFormatted = opp.deadline_date ? new Date(opp.deadline_date).toLocaleDateString() : null
                  const skills = Array.isArray(opp.skills_required) ? opp.skills_required : []

                  return (
                    <div
                      key={opp.id}
                      className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl hover:border-purple-500/60 transition flex flex-col justify-between group space-y-5"
                    >
                      {/* Card Top */}
                      <div className="space-y-4">
                        <div className="flex items-start justify-between gap-3">
                          <div className="flex items-center gap-3">
                            <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-900 to-indigo-900 border border-purple-700/60 flex items-center justify-center font-black text-white text-lg shadow-inner shrink-0">
                              {opp.company_logo ? (
                                <img src={opp.company_logo} alt={opp.company_name} className="w-full h-full object-cover rounded-2xl" />
                              ) : (
                                opp.company_name.charAt(0)
                              )}
                            </div>
                            <div>
                              <span className="text-xs font-bold text-purple-300 uppercase tracking-wider block">
                                {opp.company_name}
                              </span>
                              <h3 className="font-black text-base text-white leading-tight group-hover:text-purple-300 transition mt-0.5">
                                {opp.title}
                              </h3>
                            </div>
                          </div>

                          {opp.is_featured && (
                            <span className="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-amber-950/80 text-amber-300 border border-amber-700 shadow-xs">
                              ⭐ Featured
                            </span>
                          )}
                        </div>

                        {/* Metadata Tags */}
                        <div className="flex flex-wrap gap-2 text-[11px] font-medium text-slate-300">
                          <span className="px-2.5 py-1 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-1">
                            <span>📍</span> {opp.location}
                          </span>

                          <span className="px-2.5 py-1 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-1">
                            <span>⏳</span> {opp.employment_type}
                          </span>

                          {opp.salary_package && (
                            <span className="px-2.5 py-1 rounded-xl bg-emerald-950/80 text-emerald-300 border border-emerald-800/80 font-bold flex items-center gap-1">
                              <span>💰</span> {opp.salary_package}
                            </span>
                          )}
                        </div>

                        {/* Description excerpt */}
                        <p className="text-xs text-slate-400 line-clamp-3 leading-relaxed">
                          {opp.description}
                        </p>

                        {/* Skill Tags */}
                        {skills.length > 0 && (
                          <div className="flex flex-wrap gap-1.5 pt-1">
                            {skills.slice(0, 4).map((skill, idx) => (
                              <span
                                key={idx}
                                className="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-purple-950/60 text-purple-300 border border-purple-900"
                              >
                                {skill}
                              </span>
                            ))}
                            {skills.length > 4 && (
                              <span className="text-[10px] text-slate-500 font-bold self-center">
                                +{skills.length - 4} more
                              </span>
                            )}
                          </div>
                        )}
                      </div>

                      {/* Card Bottom / Actions */}
                      <div className="pt-4 border-t border-slate-800/80 flex items-center justify-between gap-3">
                        <div className="text-[10px] text-slate-500 font-mono">
                          {deadlineFormatted ? `Deadline: ${deadlineFormatted}` : 'Open Enrollment'}
                        </div>

                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => setDetailOpportunity(opp)}
                            className="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-900 hover:bg-slate-850 border border-slate-800 transition"
                          >
                            Details
                          </button>

                          <button
                            type="button"
                            disabled={!jobApplicationsEnabled}
                            onClick={() => handleOpenApplyModal(opp)}
                            className={`px-4 py-1.5 rounded-xl text-xs font-extrabold transition flex items-center gap-1 ${
                              jobApplicationsEnabled
                                ? 'text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30'
                                : 'text-slate-400 bg-slate-800 border border-slate-700 cursor-not-allowed'
                            }`}
                          >
                            <span>{jobApplicationsEnabled ? 'Apply Now' : 'Paused'}</span>
                            {jobApplicationsEnabled && <span>→</span>}
                          </button>
                        </div>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )}

        {/* VIEW 2: MY APPLICATIONS (STUDENT ONLY) */}
        {activeTab === 'my_applications' && (
          <div className="space-y-6">
            <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl flex items-center justify-between">
              <div>
                <h2 className="font-black text-xl text-white flex items-center gap-2">
                  <span>📋</span> My Job Applications ({myApplications.length})
                </h2>
                <p className="text-xs text-slate-400 mt-1">
                  Track the real-time recruitment pipeline for all corporate opportunities you applied to.
                </p>
              </div>

              <button
                type="button"
                onClick={fetchMyApplications}
                className="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-300 bg-slate-900 hover:text-white border border-slate-800 transition flex items-center gap-1.5"
              >
                <span>🔄</span>
                <span>Refresh Status</span>
              </button>
            </div>

            {loadingMyApps ? (
              <div className="py-16 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
                <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
                <span>Loading your applications...</span>
              </div>
            ) : myApplications.length === 0 ? (
              <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-4">
                <span className="text-4xl">📝</span>
                <h3 className="font-extrabold text-base text-slate-300">No Submitted Applications</h3>
                <p className="text-xs text-slate-400 max-w-md mx-auto">
                  You have not applied for any placement drives yet. Browse the active opportunities tab and submit your candidacy.
                </p>
                <button
                  type="button"
                  onClick={() => setActiveTab('jobs_catalog')}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition shadow-md shadow-purple-600/30"
                >
                  Explore Job Opportunities
                </button>
              </div>
            ) : (
              <div className="space-y-4">
                {myApplications.map((app) => {
                  const stepInfo = statusStepperConfig[app.status] || statusStepperConfig.applied
                  const opp = app.opportunity

                  return (
                    <div
                      key={app.id}
                      className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-5"
                    >
                      {/* Application Header */}
                      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-800/80">
                        <div>
                          <span className="text-[10px] font-extrabold text-purple-400 uppercase tracking-wider block">
                            {opp?.company_name || 'Corporate Partner'}
                          </span>
                          <h3 className="font-black text-lg text-white mt-0.5">
                            {opp?.title || 'Placement Role'}
                          </h3>
                          <p className="text-xs text-slate-400 mt-1">
                            Applied on: <strong className="text-slate-200 font-mono">{new Date(app.applied_at).toLocaleDateString()}</strong> • Batch Cohort: <strong className="text-purple-300 font-mono">{app.batch_code}</strong>
                          </p>
                        </div>

                        {/* Status Badge */}
                        <div className="flex items-center gap-2">
                          <span className={`px-3 py-1.5 rounded-full text-xs font-black uppercase border ${stepInfo.color} flex items-center gap-1.5 shadow-sm`}>
                            <span>{stepInfo.icon}</span>
                            <span>{stepInfo.label}</span>
                          </span>
                        </div>
                      </div>

                      {/* Application Pipeline Stepper */}
                      <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs pt-1">
                        {[
                          { key: 'applied', label: '1. Applied', icon: '📝' },
                          { key: 'under_review', label: '2. Under Review', icon: '🔍' },
                          { key: 'shortlisted', label: '3. Shortlisted', icon: '⭐' },
                          { key: 'interview_scheduled', label: '4. Interview', icon: '📅' },
                          { key: 'selected', label: '5. Selected & Offered', icon: '🎉' },
                        ].map((stage, sIdx) => {
                          const isPassed =
                            app.status === 'selected' ||
                            app.status === 'joined' ||
                            (app.status === 'interview_scheduled' && sIdx <= 3) ||
                            (app.status === 'shortlisted' && sIdx <= 2) ||
                            (app.status === 'under_review' && sIdx <= 1) ||
                            (app.status === 'applied' && sIdx === 0)

                          return (
                            <div
                              key={stage.key}
                              className={`p-2.5 rounded-2xl border text-center transition ${
                                isPassed
                                  ? 'bg-purple-950/70 border-purple-700/80 text-purple-200 font-extrabold'
                                  : 'bg-slate-900/60 border-slate-800/80 text-slate-500 font-medium'
                              }`}
                            >
                              <span className="block text-sm mb-1">{stage.icon}</span>
                              <span className="text-[11px] leading-tight block">{stage.label}</span>
                            </div>
                          )
                        })}
                      </div>

                      {/* Interview Details Alert Box if Scheduled */}
                      {app.status === 'interview_scheduled' && app.interview_date && (
                        <div className="bg-cyan-950/80 border border-cyan-800 p-4 rounded-2xl text-xs text-cyan-200 space-y-1.5 shadow-lg">
                          <p className="font-extrabold text-sm text-cyan-100 flex items-center gap-2">
                            <span>📅</span> Interview Scheduled!
                          </p>
                          <p>
                            Date & Time: <strong className="text-white font-mono">{new Date(app.interview_date).toLocaleString()}</strong>
                          </p>
                          {app.interview_notes && (
                            <p className="text-cyan-300">Notes: {app.interview_notes}</p>
                          )}
                        </div>
                      )}

                      {/* Offer Details if Selected */}
                      {app.status === 'selected' && (
                        <div className="bg-emerald-950/80 border border-emerald-800 p-4 rounded-2xl text-xs text-emerald-200 space-y-1.5 shadow-lg">
                          <p className="font-black text-sm text-emerald-100 flex items-center gap-2">
                            <span>🎉</span> Congratulations! You have received a selection offer!
                          </p>
                          <p className="text-emerald-300">
                            The placement cell and company HR team will contact you with onboarding instructions.
                          </p>
                        </div>
                      )}

                      {/* Application Details Summary */}
                      <div className="text-[11px] text-slate-400 pt-2 flex flex-wrap gap-4">
                        <span>Email: <strong className="text-slate-200">{app.email}</strong></span>
                        <span>Phone: <strong className="text-slate-200">{app.phone}</strong></span>
                        {app.resume_url && (
                          <a
                            href={app.resume_url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-purple-400 hover:text-purple-300 underline font-semibold"
                          >
                            View Submitted Resume ↗
                          </a>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )}
          </>
        )}
      </main>

      {/* MODAL 1: JOB DETAIL MODAL */}
      {detailOpportunity && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 max-w-2xl w-full shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto animate-in zoom-in-95 duration-200">
            <div className="flex items-start justify-between gap-4 pb-4 border-b border-slate-800">
              <div>
                <span className="text-xs font-extrabold text-purple-400 uppercase tracking-wider block">
                  {detailOpportunity.company_name}
                </span>
                <h3 className="font-black text-xl text-white mt-1">
                  {detailOpportunity.title}
                </h3>
                <p className="text-xs text-slate-400 mt-1">
                  📍 {detailOpportunity.location} • ⏳ {detailOpportunity.employment_type}
                  {detailOpportunity.salary_package && ` • 💰 ${detailOpportunity.salary_package}`}
                </p>
              </div>

              <button
                type="button"
                onClick={() => setDetailOpportunity(null)}
                className="text-slate-400 hover:text-white p-1 rounded-lg text-sm"
              >
                ✕
              </button>
            </div>

            <div className="space-y-4 text-xs">
              <div>
                <h4 className="font-bold uppercase tracking-wider text-slate-400 mb-1.5">Role Description</h4>
                <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800/80 text-slate-300 whitespace-pre-wrap leading-relaxed">
                  {detailOpportunity.description}
                </div>
              </div>

              {detailOpportunity.eligibility && (
                <div>
                  <h4 className="font-bold uppercase tracking-wider text-slate-400 mb-1.5">Eligibility Criteria</h4>
                  <p className="bg-slate-950 p-3 rounded-2xl border border-slate-800 text-purple-300 font-semibold">
                    🎓 {detailOpportunity.eligibility}
                  </p>
                </div>
              )}

              {detailOpportunity.selection_process && (
                <div>
                  <h4 className="font-bold uppercase tracking-wider text-slate-400 mb-1.5">Recruitment Stages</h4>
                  <p className="bg-slate-950 p-3 rounded-2xl border border-slate-800 text-slate-300">
                    {detailOpportunity.selection_process}
                  </p>
                </div>
              )}

              {Array.isArray(detailOpportunity.skills_required) && detailOpportunity.skills_required.length > 0 && (
                <div>
                  <h4 className="font-bold uppercase tracking-wider text-slate-400 mb-2">Technical Skills & Competencies</h4>
                  <div className="flex flex-wrap gap-2">
                    {detailOpportunity.skills_required.map((s, idx) => (
                      <span key={idx} className="px-3 py-1 rounded-xl bg-purple-950 border border-purple-800 text-purple-300 font-bold">
                        {s}
                      </span>
                    ))}
                  </div>
                </div>
              )}
            </div>

            <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
              <button
                type="button"
                onClick={() => setDetailOpportunity(null)}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
              >
                Close
              </button>

              <button
                type="button"
                disabled={!jobApplicationsEnabled}
                onClick={() => {
                  const opp = detailOpportunity
                  setDetailOpportunity(null)
                  handleOpenApplyModal(opp)
                }}
                className={`px-6 py-2 rounded-xl text-xs font-extrabold transition ${
                  jobApplicationsEnabled
                    ? 'text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30'
                    : 'text-slate-400 bg-slate-800 border border-slate-700 cursor-not-allowed'
                }`}
              >
                {jobApplicationsEnabled ? 'Proceed to Apply →' : 'Applications Paused'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL 2: APPLICATION WORKFLOW MODAL */}
      {applyingOpportunity && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-purple-800/80 rounded-3xl p-6 max-w-xl w-full shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <div>
                <h3 className="font-black text-lg text-white flex items-center gap-2">
                  <span>💼</span> Apply for {applyingOpportunity.title}
                </h3>
                <span className="text-xs text-purple-300 font-semibold">{applyingOpportunity.company_name}</span>
                {mockInterviewRequired && (
                  <span className="inline-block ml-2 px-2 py-0.5 rounded-full text-[9px] font-bold bg-purple-950 text-purple-300 border border-purple-800">
                    🎯 Mock Interview Clearance Active
                  </span>
                )}
              </div>

              <button
                type="button"
                onClick={() => setApplyingOpportunity(null)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            {/* If user is not authenticated, prompt sign-in */}
            {!user ? (
              <div className="bg-slate-950 p-6 rounded-2xl border border-slate-800 text-center space-y-4">
                <span className="text-3xl block">🔒</span>
                <h4 className="font-bold text-white text-sm">Authentication Required</h4>
                <p className="text-xs text-slate-400 max-w-sm mx-auto">
                  Please log in with your MasterInTech student account to automatically populate your verified cohort credentials and submit your placement application.
                </p>
                <div className="flex justify-center gap-3 pt-2">
                  <Link
                    to="/login"
                    className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition shadow-md shadow-purple-600/30"
                  >
                    Log In to Apply
                  </Link>
                </div>
              </div>
            ) : (
              <form onSubmit={handleSubmitApplication} className="space-y-4">
                {/* Verified Candidate Profile Banner */}
                <div className="bg-slate-950 p-4 rounded-2xl border border-slate-800 text-xs space-y-1.5">
                  <div className="flex items-center justify-between">
                    <span className="text-slate-400">Verified Candidate:</span>
                    <strong className="text-white font-bold">{user.name}</strong>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-slate-400">Verified Email:</span>
                    <strong className="text-purple-300 font-mono">{user.email}</strong>
                  </div>
                  {studentPrefill?.student_id && (
                    <div className="flex items-center justify-between">
                      <span className="text-slate-400">Student ID:</span>
                      <strong className="text-emerald-400 font-mono">{studentPrefill.student_id}</strong>
                    </div>
                  )}
                </div>

                {/* Batch Number & Cohort Selection */}
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Verified Batch Code * (Validated against existing batch records)
                  </label>
                  {loadingPrefill ? (
                    <div className="py-2 text-xs text-slate-500">Loading your verified batches...</div>
                  ) : studentPrefill?.active_batches && studentPrefill.active_batches.length > 0 ? (
                    <select
                      value={appBatchId}
                      onChange={(e) => {
                        setAppBatchId(e.target.value)
                        const selectedB = studentPrefill.active_batches.find((b) => b.id === Number(e.target.value))
                        if (selectedB) {
                          setAppCourseId(selectedB.course_id)
                        }
                      }}
                      required
                      className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                    >
                      {studentPrefill.active_batches.map((b) => (
                        <option key={b.id} value={b.id}>
                          {b.code} — {b.name} ({b.course_title || 'Cohort'})
                        </option>
                      ))}
                    </select>
                  ) : (
                    <div>
                      <input
                        type="text"
                        value={appCustomBatchNumber}
                        onChange={(e) => setAppCustomBatchNumber(e.target.value)}
                        placeholder="e.g. RIT(AI)BC230826"
                        required
                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                      />
                      <span className="text-[10px] text-slate-500 mt-1 block">
                        Enter your official 14-character MasterInTech batch code.
                      </span>
                    </div>
                  )}
                </div>

                {/* Phone & Resume */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Contact Phone *
                    </label>
                    <input
                      type="text"
                      value={appPhone}
                      onChange={(e) => setAppPhone(e.target.value)}
                      required
                      placeholder="+91 9876543210"
                      className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-purple-500 transition"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Resume Link / CV URL *
                    </label>
                    <input
                      type="url"
                      value={appResumeUrl}
                      onChange={(e) => setAppResumeUrl(e.target.value)}
                      required
                      placeholder="https://drive.google.com/... or CV URL"
                      className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-purple-500 transition"
                    />
                  </div>
                </div>

                {/* Cover Note / Remarks */}
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Cover Note & Highlights (Optional)
                  </label>
                  <textarea
                    rows={2}
                    value={appCoverNote}
                    onChange={(e) => setAppCoverNote(e.target.value)}
                    placeholder="Briefly highlight your technical projects or key skills..."
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-purple-500 transition"
                  />
                </div>

                {/* Alerts */}
                {appSuccessMsg && (
                  <div className="bg-emerald-950/80 border border-emerald-800 text-emerald-200 px-4 py-2.5 rounded-xl text-xs font-semibold">
                    {appSuccessMsg}
                  </div>
                )}

                {appErrorMsg && (
                  <div className="bg-rose-950/80 border border-rose-800 text-rose-200 px-4 py-2.5 rounded-xl text-xs font-semibold">
                    {appErrorMsg}
                  </div>
                )}

                <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => setApplyingOpportunity(null)}
                    className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={submittingApp || !appResumeUrl.trim() || (!appBatchId && !appCustomBatchNumber.trim())}
                    className="px-6 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 transition shadow-lg shadow-purple-600/30"
                  >
                    {submittingApp ? 'Submitting Application...' : 'Confirm & Submit Application'}
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}

      <Footer />
    </div>
  )
}
