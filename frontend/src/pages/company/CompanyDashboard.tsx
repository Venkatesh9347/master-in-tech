import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import API from '../../services/api'

interface CompanyDashboardData {
  company: {
    id: number
    name: string
    logo?: string | null
    status: string
    industry?: string | null
  }
  active_jobs: number
  total_jobs: number
  total_applications: number
  under_review: number
  shortlisted: number
  upcoming_interviews: number
  selected_candidates: number
}

export default function CompanyDashboard() {
  const [data, setData] = useState<CompanyDashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const fetchDashboard = async () => {
    setLoading(true)
    setLoadError(false)
    try {
      const res = await API.get<CompanyDashboardData>('/company/dashboard')
      setData(res.data)
    } catch {
      setLoadError(true)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchDashboard()
  }, [])

  return (
    <div className="space-y-8">
      {/* Header Banner */}
      <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div className="space-y-2">
          {data && (
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-950/80 border border-emerald-800 text-emerald-300 text-xs font-bold uppercase tracking-wider">
              <span>✓</span> Verified MasterInTech Hiring Partner
            </div>
          )}
          <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight">
            Welcome, {data?.company?.name || 'Corporate Partner'}
          </h1>
          <p className="text-xs sm:text-sm text-slate-400 max-w-xl leading-relaxed">
            Manage your company job postings, review student applications, schedule technical interviews, and submit candidate evaluation scorecards.
          </p>
        </div>

        <div className="flex items-center gap-3 self-start md:self-auto flex-wrap">
          <Link
            to="/company/jobs"
            className="px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-2"
          >
            <span>➕</span>
            <span>Post New Job</span>
          </Link>

          <Link
            to="/company/applications"
            className="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-300 bg-slate-900 hover:bg-slate-850 border border-slate-800 hover:text-white transition flex items-center gap-2"
          >
            <span>👥</span>
            <span>Review Applications</span>
          </Link>
        </div>
      </div>

      {/* KPI Metrics Cards */}
      {loadError ? (
        <div className="bg-amber-950/40 border border-amber-800 rounded-2xl p-6 text-center">
          <p className="text-xs font-bold text-amber-300">
            ⚠️ Could not load dashboard metrics. Please check your connection and{' '}
            <button type="button" onClick={fetchDashboard} className="underline hover:text-amber-200">
              retry
            </button>
            .
          </p>
        </div>
      ) : (
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-5 shadow-sm">
          <div className="flex items-center justify-between text-slate-400 mb-2">
            <span className="text-[10px] font-extrabold uppercase tracking-wider">Active Job Drives</span>
            <span className="text-base">💼</span>
          </div>
          <p className="text-3xl font-black text-white">{loading ? '...' : data?.active_jobs ?? 0}</p>
          <span className="text-[10px] text-purple-400 font-semibold mt-1 block">
            {data?.total_jobs ?? 0} Total Listings
          </span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-5 shadow-sm">
          <div className="flex items-center justify-between text-slate-400 mb-2">
            <span className="text-[10px] font-extrabold uppercase tracking-wider">Candidate Applications</span>
            <span className="text-base">📝</span>
          </div>
          <p className="text-3xl font-black text-blue-400">{loading ? '...' : data?.total_applications ?? 0}</p>
          <span className="text-[10px] text-slate-400 font-semibold mt-1 block">
            {data?.under_review ?? 0} In Review
          </span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-5 shadow-sm">
          <div className="flex items-center justify-between text-slate-400 mb-2">
            <span className="text-[10px] font-extrabold uppercase tracking-wider">Shortlisted</span>
            <span className="text-base">⭐</span>
          </div>
          <p className="text-3xl font-black text-purple-400">{loading ? '...' : data?.shortlisted ?? 0}</p>
          <span className="text-[10px] text-purple-300 font-semibold mt-1 block">
            Qualified Profiles
          </span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-5 shadow-sm">
          <div className="flex items-center justify-between text-slate-400 mb-2">
            <span className="text-[10px] font-extrabold uppercase tracking-wider">Upcoming Interviews</span>
            <span className="text-base">📅</span>
          </div>
          <p className="text-3xl font-black text-cyan-400">{loading ? '...' : data?.upcoming_interviews ?? 0}</p>
          <span className="text-[10px] text-cyan-300 font-semibold mt-1 block">
            Technical Rounds
          </span>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-5 shadow-sm">
          <div className="flex items-center justify-between text-slate-400 mb-2">
            <span className="text-[10px] font-extrabold uppercase tracking-wider">Selected Candidates</span>
            <span className="text-base">🎉</span>
          </div>
          <p className="text-3xl font-black text-emerald-400">{loading ? '...' : data?.selected_candidates ?? 0}</p>
          <span className="text-[10px] text-emerald-300 font-semibold mt-1 block">
            Offers Extended
          </span>
        </div>
      </div>
      )}

      {/* Recruitment Quick Action Grid */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-3 flex flex-col justify-between">
          <div className="space-y-2">
            <span className="p-2.5 rounded-xl bg-purple-950 text-purple-400 border border-purple-800 inline-block text-lg">
              💼
            </span>
            <h3 className="font-extrabold text-base text-white">Create Job Openings</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Post technical requirements, CTC package, work mode, and eligibility. Submitted jobs are reviewed and published to student cohorts by the placement cell.
            </p>
          </div>
          <Link
            to="/company/jobs"
            className="text-xs font-extrabold text-purple-400 hover:text-purple-300 flex items-center gap-1 pt-2"
          >
            <span>Manage Job Postings</span>
            <span>→</span>
          </Link>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-3 flex flex-col justify-between">
          <div className="space-y-2">
            <span className="p-2.5 rounded-xl bg-blue-950 text-blue-400 border border-blue-800 inline-block text-lg">
              👥
            </span>
            <h3 className="font-extrabold text-base text-white">Review Student Applications</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Access verified batch numbers, resumes, course specializations, and candidate profiles for your published drives. Shortlist or reject with 1-click.
            </p>
          </div>
          <Link
            to="/company/applications"
            className="text-xs font-extrabold text-blue-400 hover:text-blue-300 flex items-center gap-1 pt-2"
          >
            <span>View Candidate Roster</span>
            <span>→</span>
          </Link>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-3 flex flex-col justify-between">
          <div className="space-y-2">
            <span className="p-2.5 rounded-xl bg-cyan-950 text-cyan-400 border border-cyan-800 inline-block text-lg">
              📅
            </span>
            <h3 className="font-extrabold text-base text-white">Interviews & Evaluations</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Schedule Online (Google Meet), Offline, or Phone technical interviews. Record evaluation scorecards (Technical, Communication, Overall) and select candidates.
            </p>
          </div>
          <Link
            to="/company/interviews"
            className="text-xs font-extrabold text-cyan-400 hover:text-cyan-300 flex items-center gap-1 pt-2"
          >
            <span>Interview Schedule & Scores</span>
            <span>→</span>
          </Link>
        </div>
      </div>
    </div>
  )
}
