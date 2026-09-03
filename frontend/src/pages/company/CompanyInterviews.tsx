import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

export interface CompanyInterviewItem {
  id: number
  placement_application_id: number
  placement_opportunity_id: number
  company_id: number
  candidate_id: number
  interview_date: string
  interview_type: 'online' | 'offline' | 'phone'
  meeting_link?: string | null
  location?: string | null
  instructions?: string | null
  status: 'scheduled' | 'completed' | 'cancelled'
  technical_score?: number | null
  communication_score?: number | null
  overall_score?: number | null
  feedback?: string | null
  recommendation?: 'select' | 'reject' | 'further_round' | null
  interviewer_notes?: string | null
  candidate?: { id: number; name: string; email: string; student_id?: string } | null
  opportunity?: { id: number; title: string } | null
  application?: { id: number; batch_code: string; course_title?: string; resume_url?: string; status: string } | null
}

export default function CompanyInterviews() {
  const [interviews, setInterviews] = useState<CompanyInterviewItem[]>([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('all')

  // Feedback Evaluation Modal
  const [evaluatingInterview, setEvaluatingInterview] = useState<CompanyInterviewItem | null>(null)
  const [techScore, setTechScore] = useState<number>(8)
  const [commScore, setCommScore] = useState<number>(8)
  const [overallScore, setOverallScore] = useState<number>(8)
  const [feedbackNotes, setFeedbackNotes] = useState('')
  const [recommendation, setRecommendation] = useState<'select' | 'reject' | 'further_round'>('select')
  const [submittingEval, setSubmittingEval] = useState(false)

  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const fetchInterviews = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      if (statusFilter !== 'all') params.append('status', statusFilter)

      const res = await API.get<{ data?: CompanyInterviewItem[] } | CompanyInterviewItem[]>(
        `/company/interviews?${params.toString()}`
      )
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setInterviews(items)
    } catch {
      setErrorMsg('Failed to load company technical interviews.')
    } finally {
      setLoading(false)
    }
  }, [statusFilter])

  useEffect(() => {
    fetchInterviews()
  }, [fetchInterviews])

  const openEvaluationModal = (item: CompanyInterviewItem) => {
    setEvaluatingInterview(item)
    setTechScore(item.technical_score ?? 8)
    setCommScore(item.communication_score ?? 8)
    setOverallScore(item.overall_score ?? 8)
    setFeedbackNotes(item.feedback || '')
    setRecommendation(item.recommendation || 'select')
  }

  const handleSubmitEvaluation = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!evaluatingInterview) return

    setSubmittingEval(true)
    setErrorMsg('')
    try {
      await API.post(`/company/interviews/${evaluatingInterview.id}/feedback`, {
        technical_score: techScore,
        communication_score: commScore,
        overall_score: overallScore,
        feedback: feedbackNotes.trim(),
        recommendation: recommendation,
      })

      setSuccessMsg(
        recommendation === 'select'
          ? `✓ Candidate marked as SELECTED! Evaluation scorecard recorded.`
          : `✓ Evaluation scorecard recorded for ${evaluatingInterview.candidate?.name || 'candidate'}.`
      )
      setEvaluatingInterview(null)
      fetchInterviews()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to submit interview scorecard.')
    } finally {
      setSubmittingEval(false)
    }
  }

  return (
    <div className="space-y-6">
      {/* Header Banner */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white flex items-center gap-2">
            <span>📅</span> Technical Interviews & Evaluations ({interviews.length})
          </h1>
          <p className="text-xs text-slate-400 mt-1">
            Conduct technical assessments, record evaluation scorecards, and make hiring decisions.
          </p>
        </div>

        <div className="flex items-center gap-3 self-start sm:self-auto">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
          >
            <option value="all">All Interviews</option>
            <option value="scheduled">Scheduled / Upcoming</option>
            <option value="completed">Completed / Evaluated</option>
            <option value="cancelled">Cancelled</option>
          </select>

          <button
            type="button"
            onClick={fetchInterviews}
            className="px-3 py-2 rounded-xl text-xs font-bold text-slate-300 bg-slate-950 hover:bg-slate-900 border border-slate-800 hover:text-white transition"
          >
            🔄
          </button>
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

      {/* Interviews Table */}
      {loading ? (
        <div className="py-20 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
          <div className="w-8 h-8 border-2 border-cyan-500/20 border-t-cyan-500 rounded-full animate-spin" />
          <span>Loading interview schedule...</span>
        </div>
      ) : interviews.length === 0 ? (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-3">
          <span className="text-4xl block">📅</span>
          <h3 className="font-extrabold text-base text-slate-300">No Scheduled Interviews</h3>
          <p className="text-xs text-slate-400 max-w-md mx-auto">
            You have no interviews matching this filter. Schedule an interview from the Candidate Applications desk.
          </p>
        </div>
      ) : (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                <tr>
                  <th className="py-3.5 px-4">Candidate & Batch</th>
                  <th className="py-3.5 px-4">Job Role</th>
                  <th className="py-3.5 px-4">Interview Schedule</th>
                  <th className="py-3.5 px-4">Mode / Link</th>
                  <th className="py-3.5 px-4">Evaluation Scores</th>
                  <th className="py-3.5 px-4">Status</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/70">
                {interviews.map((item) => {
                  const isScheduled = item.status === 'scheduled'
                  const isCompleted = item.status === 'completed'

                  return (
                    <tr key={item.id} className="hover:bg-slate-900/50 transition">
                      <td className="py-3.5 px-4">
                        <p className="font-bold text-white leading-tight">{item.candidate?.name || 'Candidate'}</p>
                        <p className="text-[11px] text-purple-300 font-mono mt-0.5">
                          {item.application?.batch_code || 'Cohort'}
                        </p>
                      </td>

                      <td className="py-3.5 px-4">
                        <span className="font-semibold text-slate-200">{item.opportunity?.title || 'Job Drive'}</span>
                      </td>

                      <td className="py-3.5 px-4">
                        <p className="font-mono text-slate-200 font-semibold">
                          {new Date(item.interview_date).toLocaleDateString()}
                        </p>
                        <p className="text-[11px] text-slate-400 font-mono">
                          {new Date(item.interview_date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </p>
                      </td>

                      <td className="py-3.5 px-4">
                        {item.interview_type === 'online' && item.meeting_link ? (
                          <a
                            href={item.meeting_link}
                            target="_blank"
                            rel="noreferrer"
                            className="px-2 py-1 rounded bg-cyan-950 text-cyan-300 border border-cyan-800 hover:text-white transition inline-flex items-center gap-1 font-mono text-[10px]"
                          >
                            <span>🔗</span>
                            <span>Join Meet ↗</span>
                          </a>
                        ) : (
                          <span className="text-slate-300 uppercase text-[10px] font-bold">
                            {item.interview_type}
                          </span>
                        )}
                      </td>

                      <td className="py-3.5 px-4">
                        {isCompleted && item.overall_score ? (
                          <div className="flex items-center gap-1.5 font-mono text-[11px]">
                            <span className="text-purple-400 font-bold">Tech: {item.technical_score}/10</span>
                            <span className="text-slate-500">•</span>
                            <span className="text-emerald-400 font-bold">Score: {item.overall_score}/10</span>
                          </div>
                        ) : (
                          <span className="text-[11px] text-slate-500 italic">Pending evaluation</span>
                        )}
                      </td>

                      <td className="py-3.5 px-4">
                        <span
                          className={`px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase border ${
                            isScheduled
                              ? 'bg-cyan-950 text-cyan-300 border-cyan-700'
                              : isCompleted
                              ? 'bg-emerald-950 text-emerald-300 border-emerald-700'
                              : 'bg-slate-900 text-slate-400 border-slate-800'
                          }`}
                        >
                          {item.status}
                        </span>
                      </td>

                      <td className="py-3.5 px-4 text-right">
                        <button
                          type="button"
                          onClick={() => openEvaluationModal(item)}
                          className="px-3 py-1.5 rounded-xl text-xs font-bold text-white bg-purple-600 hover:bg-purple-500 transition shadow-sm"
                        >
                          {isCompleted ? 'Edit Scorecard' : 'Submit Scorecard'}
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* MODAL: INTERVIEW EVALUATION SCORECARD */}
      {evaluatingInterview && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-purple-800/80 rounded-3xl p-6 max-w-lg w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-base text-white flex items-center gap-2">
                <span>📝</span> Candidate Evaluation & Scorecard
              </h3>
              <button
                type="button"
                onClick={() => setEvaluatingInterview(null)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSubmitEvaluation} className="space-y-4 text-xs">
              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800 space-y-1">
                <p className="text-slate-400">Candidate: <strong className="text-white">{evaluatingInterview.candidate?.name}</strong></p>
                <p className="text-slate-400">Role: <strong className="text-purple-300">{evaluatingInterview.opportunity?.title}</strong></p>
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Technical (1-10)
                  </label>
                  <input
                    type="number"
                    min={1}
                    max={10}
                    value={techScore}
                    onChange={(e) => setTechScore(Number(e.target.value))}
                    required
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono font-bold text-center"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Communication (1-10)
                  </label>
                  <input
                    type="number"
                    min={1}
                    max={10}
                    value={commScore}
                    onChange={(e) => setCommScore(Number(e.target.value))}
                    required
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono font-bold text-center"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Overall Score (1-10)
                  </label>
                  <input
                    type="number"
                    min={1}
                    max={10}
                    value={overallScore}
                    onChange={(e) => setOverallScore(Number(e.target.value))}
                    required
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono font-bold text-center"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Final Hiring Recommendation *
                </label>
                <select
                  value={recommendation}
                  onChange={(e) => setRecommendation(e.target.value as 'select' | 'reject' | 'further_round')}
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-bold"
                >
                  <option value="select">🎉 SELECT - Extend Placement Offer</option>
                  <option value="further_round">🔄 Further Round Recommended</option>
                  <option value="reject">✕ REJECT Candidate</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Technical Feedback & Evaluation Comments
                </label>
                <textarea
                  rows={3}
                  value={feedbackNotes}
                  onChange={(e) => setFeedbackNotes(e.target.value)}
                  placeholder="Summarize candidate technical proficiency, coding challenge score, problem solving..."
                  className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setEvaluatingInterview(null)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={submittingEval}
                  className="px-6 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition shadow-lg shadow-purple-600/30"
                >
                  {submittingEval ? 'Saving Scorecard...' : 'Submit Evaluation Scorecard'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
