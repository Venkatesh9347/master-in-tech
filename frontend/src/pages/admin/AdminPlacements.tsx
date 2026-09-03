import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import MockInterviewsTab from '../../components/admin/placement/MockInterviewsTab'
import InterviewersTab from '../../components/admin/placement/InterviewersTab'
import SlotsTab from '../../components/admin/placement/SlotsTab'
import EligibilityTab from '../../components/admin/placement/EligibilityTab'

export type PlacementStatus =
  | 'applied'
  | 'under_review'
  | 'shortlisted'
  | 'interview_scheduled'
  | 'selected'
  | 'rejected'
  | 'joined'

export interface AdminPlacementStats {
  total_opportunities: number
  active_drives: number
  total_applications: number
  under_review: number
  shortlisted: number
  interview_scheduled: number
  selected: number
  joined: number
  rejected: number
}

export interface AdminOpportunityItem {
  id: number
  company_id?: number | null
  title: string
  company_name: string
  company_logo?: string | null
  job_code?: string | null
  location: string
  employment_type: string
  work_mode?: string | null
  salary_package?: string | null
  experience_required?: string | null
  eligibility?: string | null
  skills_required?: string[] | null
  description: string
  selection_process?: string | null
  openings_count: number
  deadline_date?: string | null
  status: 'published' | 'pending_approval' | 'draft' | 'closed' | 'rejected'
  is_featured: boolean
  applications_count?: number
  created_at: string
  company?: { id: number; name: string; email: string; hr_name: string } | null
}

export interface AdminApplicationItem {
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
  opportunity?: AdminOpportunityItem | null
  user?: { id: number; name: string; email: string; phone?: string; student_id?: string } | null
  batch?: { id: number; name: string; code: string; start_date?: string } | null
  course?: { id: number; title: string; code?: string } | null
}

export interface CorporatePartnerItem {
  id: number
  name: string
  logo?: string | null
  website?: string | null
  industry?: string | null
  company_size?: string | null
  location?: string | null
  hr_name: string
  email: string
  phone: string
  status: 'pending' | 'under_review' | 'approved' | 'rejected' | 'suspended'
  rejection_reason?: string | null
  approved_at?: string | null
  opportunities_count?: number
  interviews_count?: number
  created_at: string
}

const statusBadgeStyles: Record<PlacementStatus, string> = {
  applied: 'bg-blue-950/80 text-blue-300 border-blue-700',
  under_review: 'bg-amber-950/80 text-amber-300 border-amber-700',
  shortlisted: 'bg-purple-950/80 text-purple-300 border-purple-700',
  interview_scheduled: 'bg-cyan-950/80 text-cyan-300 border-cyan-700',
  selected: 'bg-emerald-950/80 text-emerald-300 border-emerald-600',
  rejected: 'bg-rose-950/80 text-rose-300 border-rose-800',
  joined: 'bg-teal-950/80 text-teal-300 border-teal-600',
}

const partnerStatusStyles: Record<string, string> = {
  pending: 'bg-amber-950/80 text-amber-300 border-amber-700',
  under_review: 'bg-blue-950/80 text-blue-300 border-blue-700',
  approved: 'bg-emerald-950/80 text-emerald-300 border-emerald-700',
  rejected: 'bg-rose-950/80 text-rose-300 border-rose-800',
  suspended: 'bg-slate-900 text-slate-400 border-slate-700',
}

export default function AdminPlacements() {
  // Main Tab
  const [activeTab, setActiveTab] = useState<
    | 'applications'
    | 'opportunities'
    | 'mock_interviews'
    | 'interviewers'
    | 'slots'
    | 'eligibility'
    | 'partners'
    | 'pending_jobs'
    | 'settings'
  >('applications')

  // Global Data
  const [stats, setStats] = useState<AdminPlacementStats | null>(null)
  const [applications, setApplications] = useState<AdminApplicationItem[]>([])
  const [opportunities, setOpportunities] = useState<AdminOpportunityItem[]>([])
  const [partners, setPartners] = useState<CorporatePartnerItem[]>([])
  const [pendingJobs, setPendingJobs] = useState<AdminOpportunityItem[]>([])

  // Placement Control Settings
  const [placementEnabled, setPlacementEnabled] = useState(true)
  const [mockInterviewRequired, setMockInterviewRequired] = useState(true)
  const [jobApplicationsEnabled, setJobApplicationsEnabled] = useState(true)
  const [settingsLoading, setSettingsLoading] = useState(false)

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  // Applications Filters
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [opportunityFilter, setOpportunityFilter] = useState('all')

  // Partners Filters
  const [partnerSearch, setPartnerSearch] = useState('')
  const [partnerStatusFilter, setPartnerStatusFilter] = useState('all')

  // Opportunity Modal State
  const [showOpportunityModal, setShowOpportunityModal] = useState(false)
  const [editingOpportunity, setEditingOpportunity] = useState<AdminOpportunityItem | null>(null)
  const [formTitle, setFormTitle] = useState('')
  const [formCompany, setFormCompany] = useState('')
  const [formLocation, setFormLocation] = useState('')
  const [formEmploymentType, setFormEmploymentType] = useState('Full-time')
  const [formSalary, setFormSalary] = useState('')
  const [formExperience, setFormExperience] = useState('')
  const [formEligibility, setFormEligibility] = useState('')
  const [formSkills, setFormSkills] = useState('')
  const [formOpenings, setFormOpenings] = useState<number>(1)
  const [formDeadline, setFormDeadline] = useState('')
  const [formDescription, setFormDescription] = useState('')
  const [formSelectionProcess, setFormSelectionProcess] = useState('')
  const [formStatus, setFormStatus] = useState<'published' | 'draft' | 'closed'>('published')
  const [formFeatured, setFormFeatured] = useState(false)

  // Partner Approve Modal
  const [approvingPartner, setApprovingPartner] = useState<CorporatePartnerItem | null>(null)
  const [partnerTempPassword, setPartnerTempPassword] = useState('')

  // Schedule Interview Modal State
  const [schedulingApp, setSchedulingApp] = useState<AdminApplicationItem | null>(null)
  const [interviewDate, setInterviewDate] = useState('')
  const [interviewNotes, setInterviewNotes] = useState('')

  // 1. Fetch Stats
  const fetchStats = useCallback(async () => {
    try {
      const res = await API.get<AdminPlacementStats>('/admin/placements/stats')
      setStats(res.data)
    } catch {
      // Non-blocking
    }
  }, [])

  // 2. Fetch Opportunities
  const fetchOpportunities = useCallback(async () => {
    try {
      const res = await API.get<{ data?: AdminOpportunityItem[] } | AdminOpportunityItem[]>(
        '/admin/placements/opportunities?per_page=100'
      )
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setOpportunities(items)
    } catch {
      // Non-blocking
    }
  }, [])

  // 3. Fetch Applications
  const fetchApplications = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      if (search.trim()) params.append('search', search.trim())
      if (statusFilter !== 'all') params.append('status', statusFilter)
      if (opportunityFilter !== 'all') params.append('placement_opportunity_id', opportunityFilter)

      const res = await API.get<{ data?: AdminApplicationItem[] } | AdminApplicationItem[]>(
        `/admin/placements/applications?${params.toString()}`
      )
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setApplications(items)
    } catch {
      setErrorMsg('Failed to load placement applications.')
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter, opportunityFilter])

  // 4. Fetch Partners
  const fetchPartners = useCallback(async () => {
    try {
      const params = new URLSearchParams()
      if (partnerSearch.trim()) params.append('search', partnerSearch.trim())
      if (partnerStatusFilter !== 'all') params.append('status', partnerStatusFilter)

      const res = await API.get<{ data?: CorporatePartnerItem[] } | CorporatePartnerItem[]>(
        `/admin/placements/partners?${params.toString()}`
      )
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setPartners(items)
    } catch {
      // Non-blocking
    }
  }, [partnerSearch, partnerStatusFilter])

  // 5. Fetch Pending Company Jobs
  const fetchPendingJobs = useCallback(async () => {
    try {
      const res = await API.get<{ data?: AdminOpportunityItem[] } | AdminOpportunityItem[]>(
        '/admin/placements/jobs/pending'
      )
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setPendingJobs(items)
    } catch {
      // Non-blocking
    }
  }, [])

  // 6. Fetch Placement Settings
  const fetchPlacementSettings = useCallback(async () => {
    setSettingsLoading(true)
    try {
      const res = await API.get<{
        placement_enabled?: boolean
        mock_interview_required?: boolean
        job_applications_enabled?: boolean
        placementEnabled?: boolean
        mockInterviewRequired?: boolean
        jobApplicationsEnabled?: boolean
      }>('/admin/placements/settings')
      if (res.data) {
        setPlacementEnabled(res.data.placement_enabled ?? res.data.placementEnabled ?? true)
        setMockInterviewRequired(res.data.mock_interview_required ?? res.data.mockInterviewRequired ?? true)
        setJobApplicationsEnabled(res.data.job_applications_enabled ?? res.data.jobApplicationsEnabled ?? true)
      }
    } catch {
      // Non-blocking
    } finally {
      setSettingsLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchStats()
    fetchOpportunities()
    fetchApplications()
    fetchPartners()
    fetchPendingJobs()
    fetchPlacementSettings()
  }, [fetchStats, fetchOpportunities, fetchApplications, fetchPartners, fetchPendingJobs, fetchPlacementSettings])

  // Save Placement Settings
  const handleSavePlacementSettings = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')
    try {
      await API.put('/admin/placements/settings', {
        placement_enabled: placementEnabled,
        mock_interview_required: mockInterviewRequired,
        job_applications_enabled: jobApplicationsEnabled,
      })
      setSuccessMsg('✓ Placement settings updated successfully!')
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to update placement settings.')
    } finally {
      setSaving(false)
    }
  }

  // Approve Partner
  const handleApprovePartner = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!approvingPartner) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.post(`/admin/placements/partners/${approvingPartner.id}/approve`, {
        password: partnerTempPassword,
      })
      setSuccessMsg(`✓ Corporate Partner '${approvingPartner.name}' approved! Recruiter account activated.`)
      setApprovingPartner(null)
      fetchPartners()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to approve partner.')
    } finally {
      setSaving(false)
    }
  }

  // Reject / Suspend / Reactivate Partner
  const handlePartnerAction = async (partnerId: number, action: 'reject' | 'suspend' | 'reactivate', name: string) => {
    if (!window.confirm(`Confirm ${action} for '${name}'?`)) return
    try {
      await API.post(`/admin/placements/partners/${partnerId}/${action}`)
      setSuccessMsg(`✓ Partner '${name}' ${action}ed.`)
      fetchPartners()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg(`Failed to ${action} partner.`)
    }
  }

  // Approve Pending Job
  const handleApproveJob = async (job: AdminOpportunityItem) => {
    try {
      await API.post(`/admin/placements/jobs/${job.id}/approve`)
      setSuccessMsg(`✓ Job '${job.title}' approved and published live to Placement Portal!`)
      fetchPendingJobs()
      fetchOpportunities()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch {
      setErrorMsg('Failed to approve job.')
    }
  }

  // Reject Pending Job
  const handleRejectJob = async (job: AdminOpportunityItem) => {
    const reason = window.prompt(`Rejection reason for '${job.title}':`, 'Job requirements need additional clarification.')
    if (reason === null) return
    try {
      await API.post(`/admin/placements/jobs/${job.id}/reject`, { reason })
      setSuccessMsg(`✓ Job '${job.title}' rejected.`)
      fetchPendingJobs()
      fetchOpportunities()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to reject job.')
    }
  }

  // Handle Application Status Update
  const handleStatusChange = async (appId: number, newStatus: PlacementStatus) => {
    try {
      await API.put(`/admin/placements/applications/${appId}/status`, {
        status: newStatus,
      })
      setSuccessMsg(`✓ Status updated to ${newStatus.toUpperCase()}!`)
      fetchApplications()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to update application status.')
    }
  }

  // Open Create Opportunity Modal
  const openCreateOpportunityModal = () => {
    setEditingOpportunity(null)
    setFormTitle('')
    setFormCompany('')
    setFormLocation('Hyderabad / Bangalore')
    setFormEmploymentType('Full-time')
    setFormSalary('8.5 - 12.0 LPA')
    setFormExperience('Freshers / 0-2 Years')
    setFormEligibility('B.Tech / MCA / MasterInTech Cohort Graduates with 60%+')
    setFormSkills('Python, React, AWS, Docker')
    setFormOpenings(1)
    setFormDeadline(new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0])
    setFormDescription('')
    setFormSelectionProcess('1. Resume Screening -> 2. Technical Coding -> 3. HR Fitment')
    setFormStatus('published')
    setFormFeatured(false)
    setShowOpportunityModal(true)
  }

  // Open Edit Opportunity Modal
  const openEditOpportunityModal = (opp: AdminOpportunityItem) => {
    setEditingOpportunity(opp)
    setFormTitle(opp.title)
    setFormCompany(opp.company_name)
    setFormLocation(opp.location)
    setFormEmploymentType(opp.employment_type)
    setFormSalary(opp.salary_package || '')
    setFormExperience(opp.experience_required || '')
    setFormEligibility(opp.eligibility || '')
    setFormSkills(Array.isArray(opp.skills_required) ? opp.skills_required.join(', ') : '')
    setFormOpenings(opp.openings_count || 1)
    setFormDeadline(opp.deadline_date ? opp.deadline_date.split('T')[0] : '')
    setFormDescription(opp.description)
    setFormSelectionProcess(opp.selection_process || '')
    setFormStatus(opp.status === 'pending_approval' ? 'published' : opp.status === 'rejected' ? 'draft' : opp.status)
    setFormFeatured(opp.is_featured)
    setShowOpportunityModal(true)
  }

  // Handle Save Opportunity
  const handleSaveOpportunity = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const skillsArray = formSkills
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)

    const payload = {
      title: formTitle.trim(),
      company_name: formCompany.trim(),
      location: formLocation.trim(),
      employment_type: formEmploymentType,
      salary_package: formSalary.trim() || null,
      experience_required: formExperience.trim() || null,
      eligibility: formEligibility.trim() || null,
      skills_required: skillsArray.length > 0 ? skillsArray : null,
      openings_count: Number(formOpenings) || 1,
      deadline_date: formDeadline || null,
      description: formDescription.trim(),
      selection_process: formSelectionProcess.trim() || null,
      status: formStatus,
      is_featured: formFeatured,
    }

    try {
      if (editingOpportunity) {
        await API.put(`/admin/placements/opportunities/${editingOpportunity.id}`, payload)
        setSuccessMsg(`✓ Opportunity '${payload.title}' updated successfully!`)
      } else {
        await API.post('/admin/placements/opportunities', payload)
        setSuccessMsg(`✓ Opportunity '${payload.title}' created and published!`)
      }
      setShowOpportunityModal(false)
      fetchOpportunities()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to save opportunity.')
    } finally {
      setSaving(false)
    }
  }

  // Delete Opportunity
  const handleDeleteOpportunity = async (opp: AdminOpportunityItem) => {
    if (!window.confirm(`Are you sure you want to delete the job opportunity '${opp.title}'?`)) {
      return
    }

    try {
      await API.delete(`/admin/placements/opportunities/${opp.id}`)
      setSuccessMsg(`✓ Opportunity '${opp.title}' deleted.`)
      fetchOpportunities()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to delete opportunity.')
    }
  }

  // Handle Schedule Interview
  const handleScheduleInterview = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!schedulingApp || !interviewDate) return

    setSaving(true)
    setErrorMsg('')
    try {
      await API.put(`/admin/placements/applications/${schedulingApp.id}/status`, {
        status: 'interview_scheduled',
        interview_date: interviewDate,
        admin_notes: interviewNotes.trim() || undefined,
      })
      setSuccessMsg(`✓ Interview scheduled for ${schedulingApp.student_name}!`)
      setSchedulingApp(null)
      setInterviewDate('')
      setInterviewNotes('')
      fetchApplications()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to schedule interview.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-8">
      {/* 1. Header Banner */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight flex items-center gap-2.5">
            <span className="p-2 rounded-xl bg-purple-950/80 border border-purple-800 text-purple-400 text-xl shadow-inner">
              💼
            </span>
            Placement Desk & Corporate Partner Network
          </h1>
          <p className="text-slate-400 text-sm mt-1">
            Manage partner companies, review company-posted jobs, track verified student candidate pipelines, and oversee interview scorecards.
          </p>
        </div>

        <div className="flex items-center gap-3 self-start md:self-auto flex-wrap">
          <button
            type="button"
            onClick={openCreateOpportunityModal}
            className="px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-2"
          >
            <span>➕</span>
            <span>Create Placement Drive</span>
          </button>

          <button
            type="button"
            onClick={() => {
              fetchStats()
              fetchOpportunities()
              fetchApplications()
              fetchPartners()
              fetchPendingJobs()
            }}
            className="px-3.5 py-2.5 rounded-xl text-xs font-bold text-slate-300 bg-slate-950 hover:bg-slate-900 border border-slate-800 hover:text-white transition flex items-center gap-1.5"
          >
            <span>🔄</span>
            <span>Refresh</span>
          </button>
        </div>
      </div>

      {/* 2. KPI Metrics Bar */}
      <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Total Drives</span>
          <p className="text-2xl font-black text-white mt-1">{stats?.total_opportunities ?? '...'}</p>
          <span className="text-[10px] font-semibold text-purple-300 mt-0.5 block">{stats?.active_drives ?? 0} Active</span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Total Applicants</span>
          <p className="text-2xl font-black text-blue-400 mt-1">{stats?.total_applications ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Submissions</span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Under Review</span>
          <p className="text-2xl font-black text-amber-400 mt-1">{stats?.under_review ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Screening</span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Shortlisted</span>
          <p className="text-2xl font-black text-purple-400 mt-1">{stats?.shortlisted ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Qualified</span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Interviews</span>
          <p className="text-2xl font-black text-cyan-400 mt-1">{stats?.interview_scheduled ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Scheduled</span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Selected</span>
          <p className="text-2xl font-black text-emerald-400 mt-1">{stats?.selected ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Offers Made</span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Joined</span>
          <p className="text-2xl font-black text-teal-400 mt-1">{stats?.joined ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Onboarded</span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Rejected</span>
          <p className="text-2xl font-black text-rose-400 mt-1">{stats?.rejected ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Closed</span>
        </div>
      </div>

      {/* Alerts */}
      {successMsg && (
        <div className="bg-emerald-950/80 border border-emerald-800 text-emerald-200 px-5 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg">
          <span>{successMsg}</span>
          <button type="button" onClick={() => setSuccessMsg('')} className="text-emerald-400 hover:text-white">✕</button>
        </div>
      )}

      {errorMsg && (
        <div className="bg-rose-950/80 border border-rose-800 text-rose-200 px-5 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg">
          <span>{errorMsg}</span>
          <button type="button" onClick={() => setErrorMsg('')} className="text-rose-400 hover:text-white">✕</button>
        </div>
      )}

      {/* 3. Navigation Tabs */}
      <div className="flex items-center gap-2 border-b border-slate-800 pb-2 overflow-x-auto scrollbar-none">
        <button
          type="button"
          onClick={() => setActiveTab('applications')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'applications'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>📋</span>
          <span>Candidate Applications ({applications.length})</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('opportunities')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'opportunities'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>💼</span>
          <span>Placement Drives ({opportunities.length})</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('mock_interviews')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'mock_interviews'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>🎙️</span>
          <span>Mock Interviews</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('interviewers')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'interviewers'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>👨‍💼</span>
          <span>Interviewers</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('slots')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'slots'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>🕒</span>
          <span>Interview Slots</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('eligibility')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'eligibility'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>🎓</span>
          <span>Placement Eligibility</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('partners')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'partners'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>🏢</span>
          <span>Corporate Partners ({partners.length})</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('pending_jobs')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'pending_jobs'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>⏳</span>
          <span>Pending Job Approvals ({pendingJobs.length})</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('settings')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 shrink-0 ${
            activeTab === 'settings'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>⚙️</span>
          <span>Placement Control</span>
        </button>
      </div>

      {/* Mock Interviews Tab View */}
      {activeTab === 'mock_interviews' && <MockInterviewsTab />}

      {/* Professional Interviewers Tab View */}
      {activeTab === 'interviewers' && <InterviewersTab />}

      {/* Slots Management Tab View */}
      {activeTab === 'slots' && <SlotsTab />}

      {/* Placement Eligibility Manager Tab View */}
      {activeTab === 'eligibility' && <EligibilityTab />}

      {/* 4. TAB CONTENT: CORPORATE PARTNERS */}
      {activeTab === 'partners' && (
        <div className="space-y-6">
          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-5 shadow-xl">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">
              <div className="lg:col-span-8 relative">
                <input
                  type="text"
                  value={partnerSearch}
                  onChange={(e) => setPartnerSearch(e.target.value)}
                  placeholder="Search company name, HR contact, official email, or location..."
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="lg:col-span-4">
                <select
                  value={partnerStatusFilter}
                  onChange={(e) => setPartnerStatusFilter(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500"
                >
                  <option value="all">All Partner Statuses</option>
                  <option value="pending">Pending Approval</option>
                  <option value="approved">Approved & Active</option>
                  <option value="rejected">Rejected</option>
                  <option value="suspended">Suspended</option>
                </select>
              </div>
            </div>
          </div>

          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                  <tr>
                    <th className="py-3.5 px-4">Company Name</th>
                    <th className="py-3.5 px-4">HR Recruiter Contact</th>
                    <th className="py-3.5 px-4">Industry & Location</th>
                    <th className="py-3.5 px-4">Jobs Posted</th>
                    <th className="py-3.5 px-4">Status</th>
                    <th className="py-3.5 px-4 text-right">Admin Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/70">
                  {partners.map((p) => {
                    const badge = partnerStatusStyles[p.status] || 'bg-slate-900 text-slate-400'
                    return (
                      <tr key={p.id} className="hover:bg-slate-900/50 transition">
                        <td className="py-3.5 px-4">
                          <p className="font-bold text-white leading-tight">{p.name}</p>
                          {p.website && (
                            <a href={p.website} target="_blank" rel="noreferrer" className="text-[11px] text-purple-400 hover:underline">
                              {p.website} ↗
                            </a>
                          )}
                        </td>

                        <td className="py-3.5 px-4">
                          <p className="font-semibold text-slate-200">{p.hr_name}</p>
                          <p className="text-[11px] text-slate-400 font-mono">{p.email}</p>
                          <p className="text-[10px] text-slate-500 font-mono">{p.phone}</p>
                        </td>

                        <td className="py-3.5 px-4">
                          <p className="text-slate-200">{p.industry || 'IT & Services'}</p>
                          <span className="text-[10px] text-slate-400">{p.location}</span>
                        </td>

                        <td className="py-3.5 px-4">
                          <span className="font-mono font-bold text-purple-300 px-2 py-0.5 rounded bg-purple-950/80 border border-purple-800">
                            {p.opportunities_count ?? 0}
                          </span>
                        </td>

                        <td className="py-3.5 px-4">
                          <span className={`px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase border ${badge}`}>
                            {p.status}
                          </span>
                        </td>

                        <td className="py-3.5 px-4 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            {p.status !== 'approved' && (
                              <button
                                type="button"
                                onClick={() => setApprovingPartner(p)}
                                className="px-2.5 py-1 rounded-lg text-xs font-bold text-emerald-300 bg-emerald-950/80 hover:bg-emerald-900 border border-emerald-800 transition"
                              >
                                Approve
                              </button>
                            )}

                            {p.status === 'approved' && (
                              <button
                                type="button"
                                onClick={() => handlePartnerAction(p.id, 'suspend', p.name)}
                                className="px-2.5 py-1 rounded-lg text-xs font-bold text-amber-300 bg-amber-950/80 hover:bg-amber-900 border border-amber-800 transition"
                              >
                                Suspend
                              </button>
                            )}

                            {p.status === 'suspended' && (
                              <button
                                type="button"
                                onClick={() => handlePartnerAction(p.id, 'reactivate', p.name)}
                                className="px-2.5 py-1 rounded-lg text-xs font-bold text-cyan-300 bg-cyan-950/80 hover:bg-cyan-900 border border-cyan-800 transition"
                              >
                                Reactivate
                              </button>
                            )}

                            {p.status === 'pending' && (
                              <button
                                type="button"
                                onClick={() => handlePartnerAction(p.id, 'reject', p.name)}
                                className="px-2.5 py-1 rounded-lg text-xs font-bold text-rose-300 bg-rose-950/80 hover:bg-rose-900 border border-rose-800 transition"
                              >
                                Reject
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* 5. TAB CONTENT: PENDING COMPANY JOBS */}
      {activeTab === 'pending_jobs' && (
        <div className="space-y-6">
          {pendingJobs.length === 0 ? (
            <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-3">
              <span className="text-4xl block">✓</span>
              <h3 className="font-extrabold text-base text-slate-300">No Pending Job Approvals</h3>
              <p className="text-xs text-slate-400 max-w-md mx-auto">
                All company job postings have been reviewed. When partner companies submit new job drives, they will appear here for administrative approval.
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {pendingJobs.map((job) => (
                <div key={job.id} className="bg-slate-950/90 border border-amber-800/60 rounded-3xl p-6 shadow-xl space-y-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <span className="text-[10px] font-bold text-amber-400 uppercase tracking-widest block">
                        ⏳ Pending Admin Review
                      </span>
                      <h3 className="font-black text-lg text-white mt-0.5">{job.title}</h3>
                      <p className="text-xs text-slate-400 font-semibold">{job.company_name} • {job.location}</p>
                    </div>
                    <span className="px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase bg-purple-950 text-purple-300 border border-purple-800">
                      {job.employment_type}
                    </span>
                  </div>

                  <div className="bg-slate-900/80 p-3.5 rounded-2xl border border-slate-800 text-xs space-y-1.5">
                    <p className="text-slate-300"><strong className="text-slate-400">Package:</strong> {job.salary_package || 'Not specified'}</p>
                    <p className="text-slate-300"><strong className="text-slate-400">Experience:</strong> {job.experience_required || 'Freshers'}</p>
                    <p className="text-slate-300"><strong className="text-slate-400">Eligibility:</strong> {job.eligibility || 'All MasterInTech Batches'}</p>
                    <div className="pt-1">
                      <span className="text-slate-400 block mb-1">Required Skills:</span>
                      <div className="flex flex-wrap gap-1">
                        {Array.isArray(job.skills_required) && job.skills_required.map((s) => (
                          <span key={s} className="px-2 py-0.5 rounded bg-slate-950 text-purple-300 border border-slate-800 text-[10px] font-mono">
                            {s}
                          </span>
                        ))}
                      </div>
                    </div>
                  </div>

                  <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                    <button
                      type="button"
                      onClick={() => handleRejectJob(job)}
                      className="px-4 py-2 rounded-xl text-xs font-bold text-rose-300 bg-rose-950/60 hover:bg-rose-900 border border-rose-800 transition"
                    >
                      Reject
                    </button>
                    <button
                      type="button"
                      onClick={() => handleApproveJob(job)}
                      className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 transition shadow-lg shadow-emerald-600/30"
                    >
                      ✓ Approve & Publish to Placement Portal
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* 6. TAB CONTENT: CANDIDATE APPLICATIONS */}
      {activeTab === 'applications' && (
        <div className="space-y-6">
          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-5 shadow-xl">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">
              <div className="lg:col-span-6 relative">
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search candidate name, batch code (RIT...), email, phone..."
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="lg:col-span-3">
                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500"
                >
                  <option value="all">All Application Stages</option>
                  <option value="applied">Applied</option>
                  <option value="under_review">Under Review</option>
                  <option value="shortlisted">Shortlisted</option>
                  <option value="interview_scheduled">Interview Scheduled</option>
                  <option value="selected">Selected</option>
                  <option value="joined">Joined</option>
                  <option value="rejected">Rejected</option>
                </select>
              </div>

              <div className="lg:col-span-3">
                <select
                  value={opportunityFilter}
                  onChange={(e) => setOpportunityFilter(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500"
                >
                  <option value="all">All Placement Drives</option>
                  {opportunities.map((opp) => (
                    <option key={opp.id} value={opp.id}>
                      {opp.title} ({opp.company_name})
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          {loading ? (
            <div className="py-20 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
              <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
              <span>Loading applications...</span>
            </div>
          ) : applications.length === 0 ? (
            <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-3">
              <span className="text-4xl block">📭</span>
              <h3 className="font-extrabold text-base text-slate-300">No Candidate Applications Found</h3>
              <p className="text-xs text-slate-400 max-w-md mx-auto">
                No placement applications match the selected criteria.
              </p>
            </div>
          ) : (
            <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                    <tr>
                      <th className="py-3.5 px-4">Candidate & Cohort</th>
                      <th className="py-3.5 px-4">Placement Opportunity</th>
                      <th className="py-3.5 px-4">Batch Number</th>
                      <th className="py-3.5 px-4">Resume</th>
                      <th className="py-3.5 px-4">Application Stage</th>
                      <th className="py-3.5 px-4 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/70">
                    {applications.map((app) => (
                    <tr key={app.id} className="hover:bg-slate-900/50 transition">
                      <td className="py-3.5 px-4">
                        <p className="font-bold text-white leading-tight">{app.student_name}</p>
                        <p className="text-[11px] text-slate-400 font-mono">{app.email}</p>
                      </td>

                      <td className="py-3.5 px-4">
                        <p className="font-semibold text-slate-200">{app.opportunity?.title || 'Drive'}</p>
                        <span className="text-[10px] text-purple-300 font-bold">{app.opportunity?.company_name}</span>
                      </td>

                      <td className="py-3.5 px-4">
                        <span className="font-mono font-bold text-purple-300 px-2 py-0.5 rounded bg-purple-950/80 border border-purple-800">
                          {app.batch_code}
                        </span>
                      </td>

                      <td className="py-3.5 px-4">
                        <a
                          href={app.resume_url}
                          target="_blank"
                          rel="noreferrer"
                          className="px-2 py-1 rounded bg-purple-950/80 text-purple-300 border border-purple-800 hover:text-white transition inline-flex items-center gap-1 font-mono text-[10px]"
                        >
                          <span>📄</span>
                          <span>View CV</span>
                        </a>
                      </td>

                      <td className="py-3.5 px-4">
                        <select
                          value={app.status}
                          onChange={(e) => handleStatusChange(app.id, e.target.value as PlacementStatus)}
                          className={`px-2.5 py-1 rounded-xl text-[10px] font-extrabold uppercase border focus:outline-none transition cursor-pointer ${
                            statusBadgeStyles[app.status] || 'bg-slate-900 text-slate-400 border-slate-800'
                          }`}
                        >
                          <option value="applied">Applied</option>
                          <option value="under_review">Under Review</option>
                          <option value="shortlisted">Shortlisted</option>
                          <option value="interview_scheduled">Interview Scheduled</option>
                          <option value="selected">Selected</option>
                          <option value="joined">Joined</option>
                          <option value="rejected">Rejected</option>
                        </select>
                      </td>

                      <td className="py-3.5 px-4 text-right">
                        <button
                          type="button"
                          onClick={() => setSchedulingApp(app)}
                          className="px-2.5 py-1 rounded-lg text-xs font-bold text-cyan-300 bg-cyan-950/80 hover:bg-cyan-900 border border-cyan-800 transition"
                        >
                          📅 Schedule
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
          )}
        </div>
      )}

      {/* 7. TAB CONTENT: OPPORTUNITIES */}
      {activeTab === 'opportunities' && (
        <div className="space-y-6">
          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                  <tr>
                    <th className="py-3.5 px-4">Job Title & Company</th>
                    <th className="py-3.5 px-4">Location & Mode</th>
                    <th className="py-3.5 px-4">Package</th>
                    <th className="py-3.5 px-4">Openings</th>
                    <th className="py-3.5 px-4">Applicants</th>
                    <th className="py-3.5 px-4">Status</th>
                    <th className="py-3.5 px-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/70">
                  {opportunities.map((opp) => (
                    <tr key={opp.id} className="hover:bg-slate-900/50 transition">
                      <td className="py-3.5 px-4">
                        <p className="font-bold text-white leading-tight">{opp.title}</p>
                        <p className="text-[11px] text-purple-300 font-semibold">{opp.company_name}</p>
                      </td>

                      <td className="py-3.5 px-4">
                        <p className="text-slate-200">{opp.location}</p>
                        <span className="text-[10px] text-slate-400">{opp.employment_type}</span>
                      </td>

                      <td className="py-3.5 px-4 font-bold text-emerald-400">
                        {opp.salary_package || 'Not disclosed'}
                      </td>

                      <td className="py-3.5 px-4 font-mono font-bold text-slate-200">
                        {opp.openings_count}
                      </td>

                      <td className="py-3.5 px-4">
                        <span className="font-mono font-bold text-blue-400 bg-blue-950 px-2 py-0.5 rounded border border-blue-800">
                          {opp.applications_count ?? 0}
                        </span>
                      </td>

                      <td className="py-3.5 px-4">
                        <span className={`px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase border ${
                          opp.status === 'published'
                            ? 'bg-emerald-950 text-emerald-300 border-emerald-700'
                            : opp.status === 'pending_approval'
                            ? 'bg-amber-950 text-amber-300 border-amber-700'
                            : 'bg-slate-900 text-slate-400 border-slate-800'
                        }`}>
                          {opp.status}
                        </span>
                      </td>

                      <td className="py-3.5 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            type="button"
                            onClick={() => openEditOpportunityModal(opp)}
                            className="p-1 rounded-lg text-slate-400 hover:text-white bg-slate-900 border border-slate-800"
                          >
                            ✏️
                          </button>
                          <button
                            type="button"
                            onClick={() => handleDeleteOpportunity(opp)}
                            className="p-1 rounded-lg text-rose-400 hover:text-white bg-rose-950/60 border border-rose-900"
                          >
                            🗑️
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* 8. TAB CONTENT: PLACEMENT CONTROL SETTINGS */}
      {activeTab === 'settings' && (
        <div className="space-y-6">
          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl space-y-6">
            <div className="border-b border-slate-800 pb-4">
              <h2 className="text-lg sm:text-xl font-black text-white flex items-center gap-2.5">
                <span>⚙️</span> PLACEMENT CONTROL
              </h2>
              <p className="text-xs text-slate-400 mt-1">
                Configure global Placement Portal visibility, mock interview eligibility requirements, and student job application submission policies.
              </p>
            </div>

            {settingsLoading ? (
              <div className="py-12 text-center text-slate-400">Loading placement settings...</div>
            ) : (
              <form onSubmit={handleSavePlacementSettings} className="space-y-6">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                  {/* 1. Placement Portal Enabled */}
                  <div className="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-4 flex flex-col justify-between">
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-black uppercase tracking-wider text-slate-200">
                          Placement Portal
                        </span>
                        <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase border ${
                          placementEnabled
                            ? 'bg-emerald-950/80 text-emerald-300 border-emerald-700'
                            : 'bg-rose-950/80 text-rose-300 border-rose-800'
                        }`}>
                          {placementEnabled ? 'Enabled' : 'Disabled'}
                        </span>
                      </div>
                      <p className="text-[11px] text-slate-400 leading-relaxed">
                        Toggle visibility and access to the dedicated Placement Portal across the public website and student dashboard.
                      </p>
                    </div>

                    <div className="pt-3 border-t border-slate-800/80 flex items-center justify-between">
                      <span className="text-xs font-bold text-slate-300">Portal Access:</span>
                      <button
                        type="button"
                        onClick={() => setPlacementEnabled(!placementEnabled)}
                        className={`px-3.5 py-1.5 rounded-xl text-xs font-black transition ${
                          placementEnabled
                            ? 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-md shadow-emerald-600/30'
                            : 'bg-slate-800 hover:bg-slate-700 text-slate-300'
                        }`}
                      >
                        {placementEnabled ? '✓ Enabled' : '✕ Disabled'}
                      </button>
                    </div>
                  </div>

                  {/* 2. Mandatory Mock Interview */}
                  <div className="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-4 flex flex-col justify-between">
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-black uppercase tracking-wider text-slate-200">
                          Mandatory Mock Interview
                        </span>
                        <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase border ${
                          mockInterviewRequired
                            ? 'bg-purple-950/80 text-purple-300 border-purple-700'
                            : 'bg-slate-800 text-slate-400 border-slate-700'
                        }`}>
                          {mockInterviewRequired ? 'Mandatory' : 'Optional'}
                        </span>
                      </div>
                      <p className="text-[11px] text-slate-400 leading-relaxed">
                        Enforce prerequisite mock interview evaluation and clearance before students can submit drive applications.
                      </p>
                    </div>

                    <div className="pt-3 border-t border-slate-800/80 flex items-center justify-between">
                      <span className="text-xs font-bold text-slate-300">Mock Policy:</span>
                      <button
                        type="button"
                        onClick={() => setMockInterviewRequired(!mockInterviewRequired)}
                        className={`px-3.5 py-1.5 rounded-xl text-xs font-black transition ${
                          mockInterviewRequired
                            ? 'bg-purple-600 hover:bg-purple-500 text-white shadow-md shadow-purple-600/30'
                            : 'bg-slate-800 hover:bg-slate-700 text-slate-300'
                        }`}
                      >
                        {mockInterviewRequired ? '✓ Required' : '✕ Optional'}
                      </button>
                    </div>
                  </div>

                  {/* 3. Job Applications Submissions */}
                  <div className="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-4 flex flex-col justify-between">
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-black uppercase tracking-wider text-slate-200">
                          Job Applications
                        </span>
                        <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase border ${
                          jobApplicationsEnabled
                            ? 'bg-cyan-950/80 text-cyan-300 border-cyan-700'
                            : 'bg-amber-950/80 text-amber-300 border-amber-800'
                        }`}>
                          {jobApplicationsEnabled ? 'Enabled' : 'Paused'}
                        </span>
                      </div>
                      <p className="text-[11px] text-slate-400 leading-relaxed">
                        Allow active student cohort candidate submissions or temporarily pause applications across placement drives.
                      </p>
                    </div>

                    <div className="pt-3 border-t border-slate-800/80 flex items-center justify-between">
                      <span className="text-xs font-bold text-slate-300">Submissions:</span>
                      <button
                        type="button"
                        onClick={() => setJobApplicationsEnabled(!jobApplicationsEnabled)}
                        className={`px-3.5 py-1.5 rounded-xl text-xs font-black transition ${
                          jobApplicationsEnabled
                            ? 'bg-cyan-600 hover:bg-cyan-500 text-white shadow-md shadow-cyan-600/30'
                            : 'bg-slate-800 hover:bg-slate-700 text-slate-300'
                        }`}
                      >
                        {jobApplicationsEnabled ? '✓ Enabled' : '✕ Paused'}
                      </button>
                    </div>
                  </div>
                </div>

                <div className="pt-4 border-t border-slate-800 flex items-center justify-between">
                  <div className="flex items-center gap-2 text-slate-400 text-xs">
                    <span>💡</span>
                    <span>Changes take effect immediately and are saved to persistent website settings.</span>
                  </div>

                  <button
                    type="submit"
                    disabled={saving}
                    className="px-6 py-2.5 rounded-xl text-xs font-black text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 transition shadow-lg shadow-purple-600/30 disabled:opacity-50"
                  >
                    {saving ? 'Saving...' : '💾 Save Placement Settings'}
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}

      {/* MODAL: APPROVE PARTNER */}
      {approvingPartner && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-emerald-800/80 rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-base text-white flex items-center gap-2">
                <span>🏢</span> Approve Corporate Partner
              </h3>
              <button
                type="button"
                onClick={() => setApprovingPartner(null)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleApprovePartner} className="space-y-3.5 text-xs">
              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800 space-y-1">
                <p className="text-slate-400">Company: <strong className="text-white">{approvingPartner.name}</strong></p>
                <p className="text-slate-400">HR Contact: <strong className="text-purple-300">{approvingPartner.hr_name}</strong></p>
                <p className="text-slate-400">Login Email: <strong className="text-emerald-400 font-mono">{approvingPartner.email}</strong></p>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Assign Initial Recruiter Portal Password *
                </label>
                <input
                  type="text"
                  value={partnerTempPassword}
                  onChange={(e) => setPartnerTempPassword(e.target.value)}
                  required
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono"
                />
                <span className="text-[10px] text-slate-500 mt-1 block">
                  A company account will be provisioned with role 'company'. The recruiter can log in and manage postings.
                </span>
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setApprovingPartner(null)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 transition shadow-lg shadow-emerald-600/30"
                >
                  {saving ? 'Approving...' : 'Confirm Approval & Activate'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: CREATE / EDIT OPPORTUNITY */}
      {showOpportunityModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 max-w-2xl w-full shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-base text-white flex items-center gap-2">
                <span>💼</span> {editingOpportunity ? 'Edit Placement Drive' : 'Create Placement Drive'}
              </h3>
              <button
                type="button"
                onClick={() => setShowOpportunityModal(false)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSaveOpportunity} className="space-y-3.5 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Role Title *
                  </label>
                  <input
                    type="text"
                    value={formTitle}
                    onChange={(e) => setFormTitle(e.target.value)}
                    required
                    placeholder="e.g. Cloud AI Engineer"
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Company Name *
                  </label>
                  <input
                    type="text"
                    value={formCompany}
                    onChange={(e) => setFormCompany(e.target.value)}
                    required
                    placeholder="e.g. Google Cloud / Microsoft"
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Location *
                  </label>
                  <input
                    type="text"
                    value={formLocation}
                    onChange={(e) => setFormLocation(e.target.value)}
                    required
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Package / CTC
                  </label>
                  <input
                    type="text"
                    value={formSalary}
                    onChange={(e) => setFormSalary(e.target.value)}
                    placeholder="e.g. 12 - 16 LPA"
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Openings Count
                  </label>
                  <input
                    type="number"
                    min={1}
                    value={formOpenings}
                    onChange={(e) => setFormOpenings(Number(e.target.value))}
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Required Skills (Comma separated)
                </label>
                <input
                  type="text"
                  value={formSkills}
                  onChange={(e) => setFormSkills(e.target.value)}
                  placeholder="Python, React, AWS, Docker"
                  className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Job Description *
                </label>
                <textarea
                  rows={3}
                  value={formDescription}
                  onChange={(e) => setFormDescription(e.target.value)}
                  required
                  className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Publish Status
                  </label>
                  <select
                    value={formStatus}
                    onChange={(e) => setFormStatus(e.target.value as 'published' | 'draft' | 'closed')}
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="published">Published Live to Students</option>
                    <option value="draft">Draft (Unpublished)</option>
                    <option value="closed">Closed</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Application Deadline
                  </label>
                  <input
                    type="date"
                    value={formDeadline}
                    onChange={(e) => setFormDeadline(e.target.value)}
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowOpportunityModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-6 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition shadow-lg shadow-purple-600/30"
                >
                  {saving ? 'Saving...' : editingOpportunity ? 'Update Drive' : 'Publish Placement Drive'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: SCHEDULE INTERVIEW */}
      {schedulingApp && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-cyan-800/80 rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-base text-white flex items-center gap-2">
                <span>📅</span> Schedule Candidate Interview
              </h3>
              <button
                type="button"
                onClick={() => setSchedulingApp(null)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleScheduleInterview} className="space-y-3.5 text-xs">
              <div className="bg-slate-950 p-3 rounded-2xl border border-slate-800 space-y-1">
                <p className="text-slate-400">Candidate: <strong className="text-white">{schedulingApp.student_name}</strong></p>
                <p className="text-slate-400">Drive: <strong className="text-purple-300">{schedulingApp.opportunity?.title}</strong></p>
                <p className="text-slate-400">Batch Code: <strong className="text-emerald-400 font-mono">{schedulingApp.batch_code}</strong></p>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Interview Date & Time *
                </label>
                <input
                  type="datetime-local"
                  value={interviewDate}
                  onChange={(e) => setInterviewDate(e.target.value)}
                  required
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Interview Meeting Link / Venue & Notes
                </label>
                <textarea
                  rows={2}
                  value={interviewNotes}
                  onChange={(e) => setInterviewNotes(e.target.value)}
                  placeholder="e.g. Google Meet URL or Office Venue instructions..."
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setSchedulingApp(null)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !interviewDate}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-cyan-600 hover:bg-cyan-500 transition shadow-md shadow-cyan-600/30"
                >
                  {saving ? 'Scheduling...' : 'Confirm Schedule'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
