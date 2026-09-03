import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

export interface CompanyJobItem {
  id: number
  title: string
  employment_type: string
  work_mode: string
  location: string
  salary_package?: string | null
  experience_required?: string | null
  eligibility?: string | null
  minimum_qualification?: string | null
  skills_required: string[]
  preferred_skills?: string[] | null
  description: string
  selection_process?: string | null
  additional_requirements?: string | null
  openings_count: number
  deadline_date?: string | null
  status: 'draft' | 'pending_approval' | 'published' | 'closed' | 'rejected'
  applications_count?: number
  interviews_count?: number
  created_at: string
}

const statusBadges: Record<string, { label: string; style: string }> = {
  published: { label: '✓ Published Live', style: 'bg-emerald-950 text-emerald-300 border-emerald-700' },
  pending_approval: { label: '⏳ Awaiting Admin Approval', style: 'bg-amber-950 text-amber-300 border-amber-700' },
  draft: { label: '📝 Draft', style: 'bg-slate-900 text-slate-400 border-slate-800' },
  closed: { label: '🔒 Closed', style: 'bg-slate-950 text-slate-500 border-slate-800' },
  rejected: { label: '✕ Rejected', style: 'bg-rose-950 text-rose-300 border-rose-800' },
}

export default function CompanyJobs() {
  const [jobs, setJobs] = useState<CompanyJobItem[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')

  // Modal State
  const [showModal, setShowModal] = useState(false)
  const [editingJob, setEditingJob] = useState<CompanyJobItem | null>(null)
  const [formTitle, setFormTitle] = useState('')
  const [formEmploymentType, setFormEmploymentType] = useState('Full-time')
  const [formWorkMode, setFormWorkMode] = useState('On-site')
  const [formLocation, setFormLocation] = useState('Hyderabad / Bangalore')
  const [formSalary, setFormSalary] = useState('')
  const [formExperience, setFormExperience] = useState('Freshers / 0-2 Years')
  const [formEligibility, setFormEligibility] = useState('')
  const [formMinQual, setFormMinQual] = useState('B.Tech / MCA / B.Sc IT or MasterInTech Certified')
  const [formSkills, setFormSkills] = useState('')
  const [formPreferredSkills, setFormPreferredSkills] = useState('')
  const [formOpenings, setFormOpenings] = useState<number>(2)
  const [formDeadline, setFormDeadline] = useState('')
  const [formDescription, setFormDescription] = useState('')
  const [formSelectionProcess, setFormSelectionProcess] = useState('')
  const [formAdditionalReq, setFormAdditionalReq] = useState('')
  const [formStatus, setFormStatus] = useState<'draft' | 'pending_approval'>('pending_approval')

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const fetchJobs = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      if (search.trim()) params.append('search', search.trim())
      if (statusFilter !== 'all') params.append('status', statusFilter)

      const res = await API.get<{ data?: CompanyJobItem[] } | CompanyJobItem[]>(
        `/company/jobs?${params.toString()}`
      )
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setJobs(items)
    } catch {
      setErrorMsg('Failed to load company job postings.')
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter])

  useEffect(() => {
    fetchJobs()
  }, [fetchJobs])

  const openCreateModal = () => {
    setEditingJob(null)
    setFormTitle('')
    setFormEmploymentType('Full-time')
    setFormWorkMode('On-site')
    setFormLocation('Hyderabad / Bangalore')
    setFormSalary('8.5 - 12.0 LPA')
    setFormExperience('Freshers / 0-2 Years')
    setFormEligibility('B.Tech / MCA / MasterInTech Cohort Graduates')
    setFormMinQual('B.Tech / MCA / B.Sc CS / MasterInTech Certified')
    setFormSkills('Python, Docker, React, PostgreSQL')
    setFormPreferredSkills('Kubernetes, AWS, Redis')
    setFormOpenings(2)
    setFormDeadline(new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0])
    setFormDescription('')
    setFormSelectionProcess('1. Resume Screening -> 2. Technical Round -> 3. HR Fitment')
    setFormAdditionalReq('')
    setFormStatus('pending_approval')
    setShowModal(true)
  }

  const openEditModal = (job: CompanyJobItem) => {
    setEditingJob(job)
    setFormTitle(job.title)
    setFormEmploymentType(job.employment_type)
    setFormWorkMode(job.work_mode || 'On-site')
    setFormLocation(job.location)
    setFormSalary(job.salary_package || '')
    setFormExperience(job.experience_required || '')
    setFormEligibility(job.eligibility || '')
    setFormMinQual(job.minimum_qualification || '')
    setFormSkills(Array.isArray(job.skills_required) ? job.skills_required.join(', ') : '')
    setFormPreferredSkills(Array.isArray(job.preferred_skills) ? job.preferred_skills.join(', ') : '')
    setFormOpenings(job.openings_count || 1)
    setFormDeadline(job.deadline_date ? job.deadline_date.split('T')[0] : '')
    setFormDescription(job.description)
    setFormSelectionProcess(job.selection_process || '')
    setFormAdditionalReq(job.additional_requirements || '')
    setFormStatus(job.status === 'draft' ? 'draft' : 'pending_approval')
    setShowModal(true)
  }

  const handleSaveJob = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const skillsArray = formSkills
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)

    const preferredArray = formPreferredSkills
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)

    const payload = {
      title: formTitle.trim(),
      employment_type: formEmploymentType,
      work_mode: formWorkMode,
      location: formLocation.trim(),
      salary_package: formSalary.trim() || null,
      experience_required: formExperience.trim() || null,
      eligibility: formEligibility.trim() || null,
      minimum_qualification: formMinQual.trim() || null,
      skills_required: skillsArray,
      preferred_skills: preferredArray.length > 0 ? preferredArray : null,
      openings_count: Number(formOpenings) || 1,
      deadline_date: formDeadline || null,
      description: formDescription.trim(),
      selection_process: formSelectionProcess.trim() || null,
      additional_requirements: formAdditionalReq.trim() || null,
      status: formStatus,
    }

    try {
      if (editingJob) {
        await API.put(`/company/jobs/${editingJob.id}`, payload)
        setSuccessMsg(`✓ Job '${payload.title}' updated successfully!`)
      } else {
        await API.post('/company/jobs', payload)
        setSuccessMsg(
          payload.status === 'pending_approval'
            ? `✓ Job '${payload.title}' submitted for MasterInTech admin review and approval.`
            : `✓ Job draft saved successfully.`
        )
      }
      setShowModal(false)
      fetchJobs()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to save job posting.')
    } finally {
      setSaving(false)
    }
  }

  const handleDeleteJob = async (job: CompanyJobItem) => {
    if (!window.confirm(`Are you sure you want to delete '${job.title}'?`)) return
    try {
      await API.delete(`/company/jobs/${job.id}`)
      setSuccessMsg(`✓ Job '${job.title}' deleted.`)
      fetchJobs()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to delete job.')
    }
  }

  return (
    <div className="space-y-6">
      {/* Top Banner */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white flex items-center gap-2">
            <span>💼</span> Company Job Postings ({jobs.length})
          </h1>
          <p className="text-xs text-slate-400 mt-1">
            Create and manage recruitment drives. Company-submitted jobs are reviewed and approved by the placement cell before publishing live to student cohorts.
          </p>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-2 self-start sm:self-auto"
        >
          <span>➕</span>
          <span>Post New Job Opening</span>
        </button>
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

      {/* Search & Filters */}
      <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-5 shadow-xl">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">
          <div className="lg:col-span-8 relative">
            <span className="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500 text-xs">
              🔍
            </span>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by job title, skill tags, or keywords..."
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

          <div className="lg:col-span-4">
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
            >
              <option value="all">All Statuses</option>
              <option value="pending_approval">Pending Approval</option>
              <option value="published">Published Live</option>
              <option value="draft">Draft</option>
              <option value="closed">Closed</option>
            </select>
          </div>
        </div>
      </div>

      {/* Jobs Table */}
      {loading ? (
        <div className="py-20 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
          <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
          <span>Loading job postings...</span>
        </div>
      ) : jobs.length === 0 ? (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-4">
          <span className="text-4xl block">💼</span>
          <h3 className="font-extrabold text-base text-slate-300">No Job Postings Found</h3>
          <p className="text-xs text-slate-400 max-w-md mx-auto">
            You haven't posted any jobs matching this filter yet. Create your first opening to receive candidate applications.
          </p>
          <button
            type="button"
            onClick={openCreateModal}
            className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition shadow-md shadow-purple-600/30"
          >
            Create Job Opening
          </button>
        </div>
      ) : (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                <tr>
                  <th className="py-3.5 px-4">Role Title</th>
                  <th className="py-3.5 px-4">Work Mode & Location</th>
                  <th className="py-3.5 px-4">Package</th>
                  <th className="py-3.5 px-4">Openings</th>
                  <th className="py-3.5 px-4">Applications</th>
                  <th className="py-3.5 px-4">Status</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/70">
                {jobs.map((job) => {
                  const badge = statusBadges[job.status] || statusBadges.draft

                  return (
                    <tr key={job.id} className="hover:bg-slate-900/50 transition">
                      <td className="py-3.5 px-4">
                        <p className="font-bold text-white leading-tight">{job.title}</p>
                        <p className="text-[11px] text-slate-400 font-mono mt-0.5">
                          {job.employment_type} • {job.experience_required || 'Freshers'}
                        </p>
                      </td>

                      <td className="py-3.5 px-4">
                        <p className="font-medium text-slate-200">{job.location}</p>
                        <span className="text-[10px] text-purple-300 font-semibold">{job.work_mode}</span>
                      </td>

                      <td className="py-3.5 px-4 font-bold text-emerald-400">
                        {job.salary_package || 'Not disclosed'}
                      </td>

                      <td className="py-3.5 px-4 font-mono font-bold text-slate-200">
                        {job.openings_count}
                      </td>

                      <td className="py-3.5 px-4">
                        <span className="font-mono font-bold text-blue-400 bg-blue-950 px-2 py-0.5 rounded border border-blue-800">
                          {job.applications_count ?? 0}
                        </span>
                      </td>

                      <td className="py-3.5 px-4">
                        <span className={`px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase border ${badge.style}`}>
                          {badge.label}
                        </span>
                      </td>

                      <td className="py-3.5 px-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            type="button"
                            onClick={() => openEditModal(job)}
                            className="p-1 rounded-lg text-slate-400 hover:text-white bg-slate-900 hover:bg-slate-800 border border-slate-800 transition"
                            title="Edit Job"
                          >
                            ✏️
                          </button>
                          <button
                            type="button"
                            onClick={() => handleDeleteJob(job)}
                            className="p-1 rounded-lg text-rose-400 hover:text-white bg-rose-950/60 hover:bg-rose-900 border border-rose-900 transition"
                            title="Delete Job"
                          >
                            🗑️
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* MODAL: POST / EDIT JOB */}
      {showModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 max-w-2xl w-full shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>💼</span> {editingJob ? 'Edit Job Opening' : 'Post New Job Opening'}
              </h3>
              <button
                type="button"
                onClick={() => setShowModal(false)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSaveJob} className="space-y-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Job Title / Role *
                  </label>
                  <input
                    type="text"
                    value={formTitle}
                    onChange={(e) => setFormTitle(e.target.value)}
                    required
                    placeholder="e.g. Full Stack AI Engineer"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Employment Type *
                  </label>
                  <select
                    value={formEmploymentType}
                    onChange={(e) => setFormEmploymentType(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="Full-time">Full-time</option>
                    <option value="Internship">Internship + PPO</option>
                    <option value="Contract">Contract</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Work Mode
                  </label>
                  <select
                    value={formWorkMode}
                    onChange={(e) => setFormWorkMode(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="On-site">On-site</option>
                    <option value="Hybrid">Hybrid</option>
                    <option value="Remote">Remote</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Location *
                  </label>
                  <input
                    type="text"
                    value={formLocation}
                    onChange={(e) => setFormLocation(e.target.value)}
                    required
                    placeholder="e.g. Hyderabad / Bangalore"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Salary / CTC Package
                  </label>
                  <input
                    type="text"
                    value={formSalary}
                    onChange={(e) => setFormSalary(e.target.value)}
                    placeholder="e.g. 10.0 - 14.0 LPA"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Experience Level
                  </label>
                  <input
                    type="text"
                    value={formExperience}
                    onChange={(e) => setFormExperience(e.target.value)}
                    placeholder="e.g. Freshers / 0-2 Years"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Number of Openings
                  </label>
                  <input
                    type="number"
                    value={formOpenings}
                    onChange={(e) => setFormOpenings(Number(e.target.value))}
                    min={1}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Application Deadline
                  </label>
                  <input
                    type="date"
                    value={formDeadline}
                    onChange={(e) => setFormDeadline(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Eligibility Criteria
                  </label>
                  <input
                    type="text"
                    value={formEligibility}
                    onChange={(e) => setFormEligibility(e.target.value)}
                    placeholder="e.g. B.Tech / MCA / MasterInTech Graduates with 60%+"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Minimum Qualification
                  </label>
                  <input
                    type="text"
                    value={formMinQual}
                    onChange={(e) => setFormMinQual(e.target.value)}
                    placeholder="e.g. Bachelor's / Master's in CS / IT"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Required Skills * (Comma separated)
                </label>
                <input
                  type="text"
                  value={formSkills}
                  onChange={(e) => setFormSkills(e.target.value)}
                  required
                  placeholder="e.g. React, Node.js, Python, PostgreSQL, Docker"
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Preferred / Good-to-Have Skills (Comma separated)
                </label>
                <input
                  type="text"
                  value={formPreferredSkills}
                  onChange={(e) => setFormPreferredSkills(e.target.value)}
                  placeholder="e.g. Kubernetes, AWS, Redis, GraphQL"
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Job Description & Key Responsibilities *
                </label>
                <textarea
                  rows={4}
                  value={formDescription}
                  onChange={(e) => setFormDescription(e.target.value)}
                  required
                  placeholder="Describe the day-to-day responsibilities, project scopes, team structure..."
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Selection Process / Interview Rounds
                  </label>
                  <input
                    type="text"
                    value={formSelectionProcess}
                    onChange={(e) => setFormSelectionProcess(e.target.value)}
                    placeholder="e.g. 1. Screening -> 2. Technical Live Coding -> 3. HR Fitment"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Submission Action
                  </label>
                  <select
                    value={formStatus}
                    onChange={(e) => setFormStatus(e.target.value as 'draft' | 'pending_approval')}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="pending_approval">Submit for Admin Approval & Publishing</option>
                    <option value="draft">Save as Draft (Internal)</option>
                  </select>
                </div>
              </div>

              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-6 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition shadow-lg shadow-purple-600/30"
                >
                  {saving ? 'Saving...' : editingJob ? 'Update Job' : formStatus === 'pending_approval' ? 'Submit for Admin Approval' : 'Save Draft'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
