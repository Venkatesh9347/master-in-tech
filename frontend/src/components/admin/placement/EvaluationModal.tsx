import { useState } from 'react'
import API from '../../../services/api'
import type { AdminMockInterviewItem, AdminMockEvaluationItem } from '../../../types/mockInterview'

interface EvaluationModalProps {
  interview: AdminMockInterviewItem
  existingEvaluation?: AdminMockEvaluationItem | null
  onClose: () => void
  onSuccess: (updated: AdminMockInterviewItem) => void
}

export default function EvaluationModal({ interview, existingEvaluation, onClose, onSuccess }: EvaluationModalProps) {
  const [techScore, setTechScore] = useState<number>(existingEvaluation?.technical_knowledge ?? 7)
  const [codingScore, setCodingScore] = useState<number>(existingEvaluation?.programming_problem_solving ?? 7)
  const [commScore, setCommScore] = useState<number>(existingEvaluation?.communication ?? 8)
  const [confidenceScore, setConfidenceScore] = useState<number>(existingEvaluation?.confidence ?? 7)
  const [projectScore, setProjectScore] = useState<number>(existingEvaluation?.project_knowledge ?? 8)
  const [readinessScore, setReadinessScore] = useState<number>(existingEvaluation?.interview_readiness ?? 8)

  const [strengths, setStrengths] = useState<string>(
    existingEvaluation?.strengths ?? 'Strong fundamental concepts, excellent communication, and clear project explanations.'
  )
  const [areasForImprovement, setAreasForImprovement] = useState<string>(
    existingEvaluation?.areas_for_improvement ?? 'Practice more live coding problem solving and deeper distributed system design.'
  )
  const [remarks, setRemarks] = useState<string>(existingEvaluation?.interviewer_remarks ?? '')
  const [recommendation, setRecommendation] = useState<'Ready for Placement' | 'Needs Improvement' | 'Re-interview Required'>(
    existingEvaluation?.recommendation ?? 'Ready for Placement'
  )
  const [isPublished, setIsPublished] = useState<boolean>(existingEvaluation?.is_published_to_student ?? true)

  const [submitting, setSubmitting] = useState(false)
  const [errorMsg, setErrorMsg] = useState('')

  // Computed average overall score
  const computedRating = Number(
    ((techScore + codingScore + commScore + confidenceScore + projectScore + readinessScore) / 6).toFixed(1)
  )

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!strengths.trim() || !areasForImprovement.trim()) {
      setErrorMsg('Please provide strengths and areas for improvement.')
      return
    }

    setSubmitting(true)
    setErrorMsg('')

    try {
      const res = await API.post<{ message: string; interview: AdminMockInterviewItem }>(
        `/admin/mock-interviews/bookings/${interview.id}/evaluate`,
        {
          technical_knowledge: techScore,
          programming_problem_solving: codingScore,
          communication: commScore,
          confidence: confidenceScore,
          project_knowledge: projectScore,
          interview_readiness: readinessScore,
          overall_rating: computedRating,
          strengths: strengths.trim(),
          areas_for_improvement: areasForImprovement.trim(),
          interviewer_remarks: remarks.trim() || null,
          recommendation,
          is_published_to_student: isPublished,
        }
      )

      onSuccess(res.data.interview || interview)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to submit evaluation scorecard.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm overflow-y-auto">
      <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl space-y-6 my-8">
        <div className="flex items-center justify-between border-b border-slate-800 pb-4">
          <div>
            <h2 className="text-xl font-black text-white flex items-center gap-2">
              <span className="p-1.5 rounded-lg bg-purple-950 border border-purple-800 text-purple-400 text-base">
                📋
              </span>
              Mock Interview Scorecard & Evaluation
            </h2>
            <p className="text-xs text-slate-400 mt-0.5">
              Candidate: <span className="font-bold text-slate-200">{interview.student?.name}</span> ({interview.booking_code})
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition"
          >
            ✕
          </button>
        </div>

        {errorMsg && (
          <div className="bg-rose-950/80 border border-rose-800 text-rose-200 px-4 py-2.5 rounded-xl text-xs font-semibold">
            {errorMsg}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-6">
          {/* 6 Factor Scorecard Grid */}
          <div>
            <label className="text-xs font-black uppercase tracking-wider text-purple-300 block mb-3">
              Assessment Factors (1 - 10 Scale)
            </label>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800">
                <div className="flex justify-between items-center mb-1.5">
                  <span className="text-xs font-bold text-slate-300">Technical Knowledge</span>
                  <span className="text-xs font-black text-purple-400">{techScore} / 10</span>
                </div>
                <input
                  type="range"
                  min="1"
                  max="10"
                  value={techScore}
                  onChange={(e) => setTechScore(Number(e.target.value))}
                  className="w-full accent-purple-500 cursor-pointer"
                />
              </div>

              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800">
                <div className="flex justify-between items-center mb-1.5">
                  <span className="text-xs font-bold text-slate-300">Problem Solving & Coding</span>
                  <span className="text-xs font-black text-purple-400">{codingScore} / 10</span>
                </div>
                <input
                  type="range"
                  min="1"
                  max="10"
                  value={codingScore}
                  onChange={(e) => setCodingScore(Number(e.target.value))}
                  className="w-full accent-purple-500 cursor-pointer"
                />
              </div>

              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800">
                <div className="flex justify-between items-center mb-1.5">
                  <span className="text-xs font-bold text-slate-300">Communication Skills</span>
                  <span className="text-xs font-black text-purple-400">{commScore} / 10</span>
                </div>
                <input
                  type="range"
                  min="1"
                  max="10"
                  value={commScore}
                  onChange={(e) => setCommScore(Number(e.target.value))}
                  className="w-full accent-purple-500 cursor-pointer"
                />
              </div>

              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800">
                <div className="flex justify-between items-center mb-1.5">
                  <span className="text-xs font-bold text-slate-300">Confidence & Poise</span>
                  <span className="text-xs font-black text-purple-400">{confidenceScore} / 10</span>
                </div>
                <input
                  type="range"
                  min="1"
                  max="10"
                  value={confidenceScore}
                  onChange={(e) => setConfidenceScore(Number(e.target.value))}
                  className="w-full accent-purple-500 cursor-pointer"
                />
              </div>

              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800">
                <div className="flex justify-between items-center mb-1.5">
                  <span className="text-xs font-bold text-slate-300">Project Knowledge</span>
                  <span className="text-xs font-black text-purple-400">{projectScore} / 10</span>
                </div>
                <input
                  type="range"
                  min="1"
                  max="10"
                  value={projectScore}
                  onChange={(e) => setProjectScore(Number(e.target.value))}
                  className="w-full accent-purple-500 cursor-pointer"
                />
              </div>

              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800">
                <div className="flex justify-between items-center mb-1.5">
                  <span className="text-xs font-bold text-slate-300">Interview Readiness</span>
                  <span className="text-xs font-black text-purple-400">{readinessScore} / 10</span>
                </div>
                <input
                  type="range"
                  min="1"
                  max="10"
                  value={readinessScore}
                  onChange={(e) => setReadinessScore(Number(e.target.value))}
                  className="w-full accent-purple-500 cursor-pointer"
                />
              </div>
            </div>

            <div className="mt-3 flex items-center justify-between p-3 rounded-2xl bg-purple-950/40 border border-purple-800/60">
              <span className="text-xs font-extrabold text-purple-200">Overall Weighted Score:</span>
              <span className="text-lg font-black text-purple-300">{computedRating} / 10</span>
            </div>
          </div>

          {/* Recommendation Selection */}
          <div>
            <label className="text-xs font-bold text-slate-300 block mb-1.5">
              Placement Recommendation <span className="text-rose-400">*</span>
            </label>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
              <button
                type="button"
                onClick={() => setRecommendation('Ready for Placement')}
                className={`px-3 py-2.5 rounded-xl text-xs font-extrabold border transition text-center ${
                  recommendation === 'Ready for Placement'
                    ? 'bg-emerald-950 border-emerald-500 text-emerald-300 shadow-md shadow-emerald-950'
                    : 'bg-slate-950 border-slate-800 text-slate-400 hover:text-white'
                }`}
              >
                <span>🌟 Ready for Placement</span>
              </button>

              <button
                type="button"
                onClick={() => setRecommendation('Needs Improvement')}
                className={`px-3 py-2.5 rounded-xl text-xs font-extrabold border transition text-center ${
                  recommendation === 'Needs Improvement'
                    ? 'bg-amber-950 border-amber-500 text-amber-300 shadow-md shadow-amber-950'
                    : 'bg-slate-950 border-slate-800 text-slate-400 hover:text-white'
                }`}
              >
                <span>⚠️ Needs Improvement</span>
              </button>

              <button
                type="button"
                onClick={() => setRecommendation('Re-interview Required')}
                className={`px-3 py-2.5 rounded-xl text-xs font-extrabold border transition text-center ${
                  recommendation === 'Re-interview Required'
                    ? 'bg-rose-950 border-rose-500 text-rose-300 shadow-md shadow-rose-950'
                    : 'bg-slate-950 border-slate-800 text-slate-400 hover:text-white'
                }`}
              >
                <span>🔄 Re-interview Required</span>
              </button>
            </div>
          </div>

          {/* Qualitative Feedback */}
          <div className="space-y-4">
            <div>
              <label className="text-xs font-bold text-slate-300 block mb-1">
                Candidate Strengths & Highlights <span className="text-rose-400">*</span>
              </label>
              <textarea
                rows={2}
                required
                value={strengths}
                onChange={(e) => setStrengths(e.target.value)}
                placeholder="What did the candidate do well during the technical and behavioral segments?"
                className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
              />
            </div>

            <div>
              <label className="text-xs font-bold text-slate-300 block mb-1">
                Areas for Improvement & Action Plan <span className="text-rose-400">*</span>
              </label>
              <textarea
                rows={2}
                required
                value={areasForImprovement}
                onChange={(e) => setAreasForImprovement(e.target.value)}
                placeholder="Specific topics, algorithms, or behavioral skills the student should practice."
                className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
              />
            </div>

            <div>
              <label className="text-xs font-bold text-slate-300 block mb-1">
                Interviewer / Internal Remarks (Optional)
              </label>
              <textarea
                rows={2}
                value={remarks}
                onChange={(e) => setRemarks(e.target.value)}
                placeholder="Additional notes on fitment, salary expectations, or target company tiers."
                className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
              />
            </div>
          </div>

          {/* Publish to Student Checkbox */}
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="isPublished"
              checked={isPublished}
              onChange={(e) => setIsPublished(e.target.checked)}
              className="w-4 h-4 rounded border-slate-800 bg-slate-950 text-purple-600 focus:ring-purple-500"
            />
            <label htmlFor="isPublished" className="text-xs font-semibold text-slate-300 cursor-pointer">
              Publish scorecard and feedback to student portal
            </label>
          </div>

          {/* Actions */}
          <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              className="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
            >
              Cancel
            </button>

            <button
              type="submit"
              disabled={submitting}
              className="px-5 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-2"
            >
              {submitting ? (
                <>
                  <span className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                  <span>Submitting Scorecard...</span>
                </>
              ) : (
                <>
                  <span>✓</span>
                  <span>Save Evaluation & Update Eligibility</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
