import { useEffect, useState, useCallback } from 'react'
import { useAuth } from '../../context/useAuth'
import API from '../../services/api'

interface SubmissionItem {
  id: number
  user_id: number
  assignment_id: number
  course_id: number
  submission_text: string | null
  file_url: string | null
  submitted_at: string | null
  score: number | null
  feedback: string | null
  status: 'submitted' | 'graded' | 'returned'
  user?: {
    id: number
    name: string
    email: string
  }
  assignment?: {
    id: number
    title: string
    max_marks: number
  }
  course?: {
    id: number
    title: string
  }
}

export default function TutorSubmissions() {
  const { user } = useAuth()
  const [submissions, setSubmissions] = useState<SubmissionItem[]>([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState<'all' | 'submitted' | 'graded'>('all')
  const [selectedSub, setSelectedSub] = useState<SubmissionItem | null>(null)
  const [scoreInput, setScoreInput] = useState<number | string>('')
  const [feedbackInput, setFeedbackInput] = useState('')
  const [savingGrade, setSavingGrade] = useState(false)
  const [error, setError] = useState('')
  const [successMsg, setSuccessMsg] = useState('')

  const loadSubmissions = useCallback(() => {
    setLoading(true)
    API.get<SubmissionItem[]>('/tutor/submissions')
      .then((res) => {
        setSubmissions(Array.isArray(res.data) ? res.data : [])
      })
      .catch(() => setError('Failed to load assignment submissions.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadSubmissions()
  }, [loadSubmissions, user?.id])

  const openGradingDrawer = (sub: SubmissionItem) => {
    setSelectedSub(sub)
    setScoreInput(sub.score ?? '')
    setFeedbackInput(sub.feedback ?? '')
    setError('')
    setSuccessMsg('')
  }

  const handleGradeSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedSub) return

    setSavingGrade(true)
    setError('')

    try {
      await API.post(`/tutor/submissions/${selectedSub.id}/grade`, {
        score: Number(scoreInput),
        feedback: feedbackInput || undefined,
        status: 'graded',
      })

      setSuccessMsg('Grade and feedback saved successfully!')
      setSelectedSub(null)
      loadSubmissions()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to submit grade.')
    } finally {
      setSavingGrade(false)
    }
  }

  const filteredSubmissions = submissions.filter((s) => {
    if (statusFilter === 'all') return true
    return s.status === statusFilter
  })

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-slate-900">Grading Desk & Capstone Submissions</h1>
          <p className="text-xs text-slate-500">
            Review student repository submissions, assign scores, and provide actionable mentor feedback.
          </p>
        </div>

        {/* Filter Pills */}
        <div className="flex items-center gap-1.5 bg-white p-1 rounded-2xl border border-slate-200 shadow-xs">
          {[
            { id: 'all', label: `All (${submissions.length})` },
            {
              id: 'submitted',
              label: `Pending (${submissions.filter((s) => s.status === 'submitted').length})`,
            },
            {
              id: 'graded',
              label: `Graded (${submissions.filter((s) => s.status === 'graded').length})`,
            },
          ].map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => setStatusFilter(tab.id as typeof statusFilter)}
              className={`px-3 py-1.5 rounded-xl text-xs font-bold transition ${
                statusFilter === tab.id
                  ? 'bg-blue-600 text-white shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-2xl bg-red-50 text-red-700 text-xs font-bold border border-red-200">
          ⚠️ {error}
        </div>
      )}
      {successMsg && (
        <div className="p-4 rounded-2xl bg-emerald-50 text-emerald-700 text-xs font-bold border border-emerald-200">
          ✓ {successMsg}
        </div>
      )}

      {/* Submissions Table */}
      <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs">
        {loading ? (
          <p className="text-xs text-slate-500 py-12 text-center">Loading submissions...</p>
        ) : filteredSubmissions.length === 0 ? (
          <div className="py-12 text-center">
            <span className="text-4xl mb-2 block">📝</span>
            <h3 className="text-sm font-bold text-slate-900">No submissions found</h3>
            <p className="text-xs text-slate-500 mt-1">
              {statusFilter === 'submitted'
                ? 'All pending assignment submissions have been graded!'
                : 'Students will submit coding assignments as they progress.'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-slate-200 text-slate-400 font-bold uppercase tracking-wider">
                  <th className="py-3 px-4">Student</th>
                  <th className="py-3 px-4">Assignment</th>
                  <th className="py-3 px-4">Course</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4">Score</th>
                  <th className="py-3 px-4 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredSubmissions.map((sub) => (
                  <tr key={sub.id} className="hover:bg-slate-50/80 transition">
                    <td className="py-4 px-4 font-bold text-slate-900">
                      {sub.user?.name || `Student #${sub.user_id}`}
                      <span className="block text-[11px] text-slate-400 font-normal">{sub.user?.email}</span>
                    </td>
                    <td className="py-4 px-4 font-semibold text-slate-800">
                      {sub.assignment?.title}
                      <span className="block text-[10px] text-slate-400">
                        Max Marks: {sub.assignment?.max_marks || 100}
                      </span>
                    </td>
                    <td className="py-4 px-4 text-slate-600">{sub.course?.title}</td>
                    <td className="py-4 px-4">
                      <span
                        className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                          sub.status === 'graded'
                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                            : 'bg-amber-50 text-amber-800 border border-amber-200'
                        }`}
                      >
                        {sub.status}
                      </span>
                    </td>
                    <td className="py-4 px-4 font-bold text-slate-800">
                      {sub.score !== null ? `${sub.score} / ${sub.assignment?.max_marks || 100}` : '—'}
                    </td>
                    <td className="py-4 px-4 text-right">
                      <button
                        type="button"
                        onClick={() => openGradingDrawer(sub)}
                        className={`px-3.5 py-1.5 rounded-xl font-bold text-xs transition ${
                          sub.status === 'graded'
                            ? 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                            : 'bg-blue-600 text-white hover:bg-blue-700 shadow-xs'
                        }`}
                      >
                        {sub.status === 'graded' ? 'Edit Grade' : 'Grade Submission →'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Grading Drawer / Modal */}
      {selectedSub && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-xl w-full shadow-2xl border border-slate-100 space-y-6 max-h-[90vh] overflow-y-auto">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-blue-100 text-blue-800 rounded-md">
                Submission Evaluation
              </span>
              <h3 className="text-lg font-bold text-slate-900 mt-1">{selectedSub.assignment?.title}</h3>
              <p className="text-xs text-slate-500">
                Submitted by <strong className="text-slate-800">{selectedSub.user?.name}</strong> (
                {selectedSub.user?.email})
              </p>
            </div>

            {/* Submission Content */}
            <div className="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3 text-xs">
              <div>
                <p className="text-[10px] font-bold text-slate-400 uppercase">Student Written Solution / Notes</p>
                <p className="text-slate-800 mt-1 whitespace-pre-line leading-relaxed">
                  {selectedSub.submission_text || 'No text note provided.'}
                </p>
              </div>

              {selectedSub.file_url && (
                <div className="pt-2 border-t border-slate-200">
                  <p className="text-[10px] font-bold text-slate-400 uppercase mb-1">Attached Repository / Asset Link</p>
                  <a
                    href={selectedSub.file_url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 text-blue-600 font-bold hover:underline"
                  >
                    <span>🔗</span> {selectedSub.file_url}
                  </a>
                </div>
              )}
            </div>

            {/* Grading Form */}
            <form onSubmit={handleGradeSubmit} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Score Awarded (Max: {selectedSub.assignment?.max_marks || 100})
                </label>
                <input
                  type="number"
                  value={scoreInput}
                  onChange={(e) => setScoreInput(e.target.value)}
                  min="0"
                  max={selectedSub.assignment?.max_marks || 100}
                  step="0.5"
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none text-sm font-bold"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Instructor Feedback & Code Review Notes
                </label>
                <textarea
                  value={feedbackInput}
                  onChange={(e) => setFeedbackInput(e.target.value)}
                  rows={4}
                  placeholder="Provide constructive feedback, highlighting strong architectural choices and areas for improvement..."
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none leading-relaxed"
                />
              </div>

              <div className="flex justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setSelectedSub(null)}
                  className="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingGrade}
                  className="px-6 py-2 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 active:bg-blue-800 shadow-sm"
                >
                  {savingGrade ? 'Submitting Grade...' : 'Save & Publish Grade'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
