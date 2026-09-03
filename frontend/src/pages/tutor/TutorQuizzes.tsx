import { useEffect, useState, useCallback } from 'react'
import { useAuth } from '../../context/useAuth'
import API from '../../services/api'
import type { Course } from '../../types/course'
import type { Quiz, QuizAttempt } from '../../types/lms'

interface QuizQuestionDraft {
  question: string
  type: string
  marks: number
  options: { option_text: string; is_correct: boolean }[]
}

interface QuizFormData {
  course_id: number | ''
  title: string
  description: string
  time_limit: number
  passing_score: number
  max_attempts: number
  is_published: boolean
  questions: QuizQuestionDraft[]
}

export default function TutorQuizzes() {
  const { user } = useAuth()
  const [quizzes, setQuizzes] = useState<(Quiz & { questions_count?: number; attempts_count?: number; lesson?: { course?: { id: number; title: string } } })[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  // Permissions state
  const perms = (user as unknown as { permissions?: Record<string, boolean> })?.permissions || {}
  const canCreateQuizzes = Boolean(perms.create_quizzes ?? false)
  const canEditQuizzes = Boolean(perms.edit_quizzes ?? false)
  const canDeleteQuizzes = Boolean(perms.delete_quizzes ?? false)
  const canPublishQuizzes = Boolean(perms.publish_quizzes ?? false)
  const canViewResults = Boolean(perms.view_quiz_results ?? true)

  // Create / Edit Modal State
  const [modalOpen, setModalOpen] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [formData, setFormData] = useState<QuizFormData>({
    course_id: '',
    title: '',
    description: '',
    time_limit: 15,
    passing_score: 70,
    max_attempts: 3,
    is_published: true,
    questions: [
      {
        question: '',
        type: 'multiple_choice',
        marks: 1,
        options: [
          { option_text: '', is_correct: true },
          { option_text: '', is_correct: false },
        ],
      },
    ],
  })
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState('')

  // Results Modal State
  const [resultsModalOpen, setResultsModalOpen] = useState(false)
  const [selectedQuizResults, setSelectedQuizResults] = useState<{ quiz: Quiz; attempts: QuizAttempt[] } | null>(null)
  const [loadingResults, setLoadingResults] = useState(false)

  // Course Filter
  const [selectedCourseFilter, setSelectedCourseFilter] = useState<string>('all')

  const loadData = useCallback(() => {
    setLoading(true)
    setError('')

    const params: Record<string, string> = {}
    if (selectedCourseFilter !== 'all') {
      params.course_id = selectedCourseFilter
    }

    Promise.all([
      API.get('/tutor/quizzes', { params }),
      API.get<Course[]>('/tutor/courses'),
    ])
      .then(([quizRes, courseRes]) => {
        setQuizzes(Array.isArray(quizRes.data) ? quizRes.data : [])
        setCourses(Array.isArray(courseRes.data) ? courseRes.data : [])
      })
      .catch(() => setError('Failed to load course quizzes.'))
      .finally(() => setLoading(false))
  }, [selectedCourseFilter])

  useEffect(() => {
    loadData()
  }, [loadData])

  const handleOpenCreate = () => {
    setEditId(null)
    setFormData({
      course_id: courses.length > 0 ? courses[0].id : '',
      title: '',
      description: '',
      time_limit: 15,
      passing_score: 70,
      max_attempts: 3,
      is_published: true,
      questions: [
        {
          question: '',
          type: 'multiple_choice',
          marks: 1,
          options: [
            { option_text: '', is_correct: true },
            { option_text: '', is_correct: false },
          ],
        },
      ],
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleOpenEdit = (q: Quiz & { questions_count?: number; attempts_count?: number; lesson?: { course?: { id: number; title: string } } }) => {
    setEditId(q.id)
    setFormData({
      course_id: q.lesson?.course?.id || (courses.length > 0 ? courses[0].id : ''),
      title: q.title,
      description: q.description || '',
      time_limit: q.time_limit || 15,
      passing_score: q.passing_score || 70,
      max_attempts: q.max_attempts || 3,
      is_published: q.is_published ?? true,
      questions: q.questions && q.questions.length > 0 ? q.questions.map(quest => ({
        question: quest.question,
        type: quest.type || 'multiple_choice',
        marks: quest.marks || 1,
        options: quest.options?.map(opt => ({
          option_text: opt.option_text,
          is_correct: Boolean(opt.is_correct),
        })) || [
          { option_text: '', is_correct: true },
          { option_text: '', is_correct: false },
        ],
      })) : [
        {
          question: '',
          type: 'multiple_choice',
          marks: 1,
          options: [
            { option_text: '', is_correct: true },
            { option_text: '', is_correct: false },
          ],
        },
      ],
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleTogglePublish = async (quizId: number) => {
    try {
      await API.post(`/tutor/quizzes/${quizId}/toggle-publish`)
      setSuccess('Quiz status updated.')
      loadData()
      setTimeout(() => setSuccess(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      alert(response.response?.data?.message || 'Unable to update publish status.')
    }
  }

  const handleDelete = async (quizId: number) => {
    if (!window.confirm('Are you sure you want to delete this quiz?')) return

    try {
      await API.delete(`/tutor/quizzes/${quizId}`)
      setSuccess('Quiz deleted successfully.')
      loadData()
      setTimeout(() => setSuccess(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      alert(response.response?.data?.message || 'Unable to delete quiz.')
    }
  }

  const handleViewResults = async (quizId: number) => {
    setLoadingResults(true)
    try {
      const res = await API.get<{ quiz: Quiz; attempts: QuizAttempt[] }>(`/tutor/quizzes/${quizId}/results`)
      setSelectedQuizResults(res.data)
      setResultsModalOpen(true)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      alert(response.response?.data?.message || 'Unable to fetch quiz results.')
    } finally {
      setLoadingResults(false)
    }
  }

  const addQuestion = () => {
    setFormData({
      ...formData,
      questions: [
        ...formData.questions,
        {
          question: '',
          type: 'multiple_choice',
          marks: 1,
          options: [
            { option_text: '', is_correct: true },
            { option_text: '', is_correct: false },
          ],
        },
      ],
    })
  }

  const removeQuestion = (index: number) => {
    if (formData.questions.length <= 1) return
    const updated = formData.questions.filter((_, i) => i !== index)
    setFormData({ ...formData, questions: updated })
  }

  const updateQuestionText = (index: number, text: string) => {
    const updated = [...formData.questions]
    updated[index].question = text
    setFormData({ ...formData, questions: updated })
  }

  const updateOptionText = (qIndex: number, oIndex: number, text: string) => {
    const updated = [...formData.questions]
    updated[qIndex].options[oIndex].option_text = text
    setFormData({ ...formData, questions: updated })
  }

  const setCorrectOption = (qIndex: number, oIndex: number) => {
    const updated = [...formData.questions]
    updated[qIndex].options.forEach((opt, idx) => {
      opt.is_correct = (idx === oIndex)
    })
    setFormData({ ...formData, questions: updated })
  }

  const addOption = (qIndex: number) => {
    const updated = [...formData.questions]
    updated[qIndex].options.push({ option_text: '', is_correct: false })
    setFormData({ ...formData, questions: updated })
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setFormError('')

    if (!formData.course_id) {
      setFormError('Please select a course.')
      return
    }

    if (!formData.title.trim()) {
      setFormError('Quiz title is required.')
      return
    }

    // Validate questions
    for (let i = 0; i < formData.questions.length; i++) {
      const q = formData.questions[i]
      if (!q.question.trim()) {
        setFormError(`Question #${i + 1} text is required.`)
        return
      }
      for (let j = 0; j < q.options.length; j++) {
        if (!q.options[j].option_text.trim()) {
          setFormError(`Option #${j + 1} in Question #${i + 1} cannot be empty.`)
          return
        }
      }
    }

    setSubmitting(true)

    try {
      if (editId) {
        await API.put(`/tutor/quizzes/${editId}`, formData)
        setSuccess('Quiz updated successfully.')
      } else {
        await API.post('/tutor/quizzes', formData)
        setSuccess('Quiz created successfully.')
      }
      setModalOpen(false)
      loadData()
      setTimeout(() => setSuccess(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setFormError(response.response?.data?.message || 'Error saving quiz.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="space-y-8">
      {/* Header Banner */}
      <div className="bg-gradient-to-r from-blue-700 via-indigo-700 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
        <div className="space-y-1">
          <span className="bg-blue-500/30 text-blue-200 border border-blue-400/30 text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full">
            Knowledge Checkpoints
          </span>
          <h1 className="text-2xl sm:text-3xl font-black tracking-tight">Course Assessments & Quizzes 📝</h1>
          <p className="text-xs text-blue-100 max-w-xl">
            Evaluate learner understanding through module quizzes, manage questions, and review submission scores.
          </p>
        </div>

        {canCreateQuizzes ? (
          <button
            type="button"
            onClick={handleOpenCreate}
            className="px-6 py-3 rounded-2xl bg-white text-blue-700 hover:bg-blue-50 font-extrabold text-xs transition shadow-md flex items-center gap-2 shrink-0"
          >
            <span>➕</span> Create Quiz
          </button>
        ) : (
          <div className="px-4 py-2 rounded-xl bg-slate-800/80 border border-slate-700 text-[11px] font-bold text-slate-300">
            🔒 Quiz creation managed by Administration
          </div>
        )}
      </div>

      {success && (
        <div className="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between">
          <span>✓ {success}</span>
          <button type="button" onClick={() => setSuccess('')} className="text-emerald-600 hover:text-emerald-800 font-bold">✕</button>
        </div>
      )}

      {error && (
        <div className="p-4 rounded-2xl bg-red-50 border border-red-200 text-red-800 text-xs font-semibold flex items-center justify-between">
          <span>⚠️ {error}</span>
          <button type="button" onClick={() => setError('')} className="text-red-600 hover:text-red-800 font-bold">✕</button>
        </div>
      )}

      {/* Filter Toolbar */}
      <div className="bg-white p-4 rounded-3xl border border-slate-200 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-2">
          <label htmlFor="course-filter-select" className="text-xs font-bold text-slate-500">Filter by Course:</label>
          <select
            id="course-filter-select"
            value={selectedCourseFilter}
            onChange={(e) => setSelectedCourseFilter(e.target.value)}
            className="px-3.5 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-semibold text-slate-700 focus:outline-hidden focus:border-blue-500"
          >
            <option value="all">All Assigned Courses ({courses.length})</option>
            {courses.map((c) => (
              <option key={c.id} value={c.id}>{c.title}</option>
            ))}
          </select>
        </div>

        <div className="text-xs font-bold text-slate-400">
          Showing {quizzes.length} Quizzes
        </div>
      </div>

      {/* Quizzes List */}
      {loading ? (
        <div className="py-20 text-center text-slate-500 space-y-3">
          <div className="w-10 h-10 border-2 border-blue-600/20 border-t-blue-600 rounded-full animate-spin mx-auto" />
          <p className="text-xs font-semibold">Loading quizzes...</p>
        </div>
      ) : quizzes.length === 0 ? (
        <div className="p-12 rounded-3xl bg-white border border-slate-200 text-center space-y-3 shadow-xs">
          <span className="text-4xl">📝</span>
          <h3 className="text-sm font-bold text-slate-900">No quizzes assigned or created</h3>
          <p className="text-xs text-slate-500 max-w-md mx-auto">
            {canCreateQuizzes
              ? 'Click "Create Quiz" above to build knowledge checks for your students.'
              : 'Course quizzes will appear here once configured by administrators.'}
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {quizzes.map((q) => (
            <div
              key={q.id}
              className="bg-white rounded-3xl p-5 border border-slate-200 shadow-xs hover:shadow-md transition flex flex-col justify-between space-y-4"
            >
              <div className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 truncate max-w-[170px]">
                    {q.lesson?.course?.title || 'Assigned Course'}
                  </span>
                  <span
                    className={`text-[10px] font-black uppercase px-2 py-0.5 rounded border ${
                      q.is_published
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                        : 'bg-slate-100 text-slate-500 border-slate-200'
                    }`}
                  >
                    {q.is_published ? 'Published' : 'Draft'}
                  </span>
                </div>

                <h3 className="text-sm font-bold text-slate-900 line-clamp-1">{q.title}</h3>
                {q.description && (
                  <p className="text-xs text-slate-500 line-clamp-2">{q.description}</p>
                )}

                <div className="pt-2 text-[11px] text-slate-500 space-y-0.5 border-t border-slate-100 flex justify-between">
                  <span>⏱️ {q.time_limit || 15} Mins</span>
                  <span>🎯 Pass: {q.passing_score}%</span>
                  <span>📊 {q.questions_count || q.questions?.length || 0} Questions</span>
                </div>
              </div>

              <div className="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                {canViewResults && (
                  <button
                    type="button"
                    onClick={() => handleViewResults(q.id)}
                    disabled={loadingResults}
                    className="flex-grow py-2 px-3 rounded-xl bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-xs text-center transition"
                  >
                    Results ({q.attempts_count || 0})
                  </button>
                )}

                {canPublishQuizzes && (
                  <button
                    type="button"
                    onClick={() => handleTogglePublish(q.id)}
                    className="p-2 rounded-xl text-slate-600 hover:text-blue-600 hover:bg-slate-100 transition"
                    title={q.is_published ? 'Unpublish Quiz' : 'Publish Quiz'}
                  >
                    {q.is_published ? '👁️' : '🔒'}
                  </button>
                )}

                {canEditQuizzes && (
                  <button
                    type="button"
                    onClick={() => handleOpenEdit(q)}
                    className="p-2 rounded-xl text-slate-600 hover:text-blue-600 hover:bg-slate-100 transition"
                    title="Edit Quiz"
                  >
                    ✏️
                  </button>
                )}

                {canDeleteQuizzes && (
                  <button
                    type="button"
                    onClick={() => handleDelete(q.id)}
                    className="p-2 rounded-xl text-slate-600 hover:text-rose-600 hover:bg-rose-50 transition"
                    title="Delete Quiz"
                  >
                    🗑️
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create / Edit Quiz Modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4 overflow-y-auto backdrop-blur-xs">
          <div className="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl space-y-5 my-8">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h2 className="text-base font-black text-slate-900">
                {editId ? 'Edit Quiz' : 'Create Course Quiz'}
              </h2>
              <button
                type="button"
                onClick={() => setModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 font-bold text-base"
              >
                ✕
              </button>
            </div>

            {formError && (
              <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs font-semibold">
                ⚠️ {formError}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">Target Course *</label>
                  <select
                    value={formData.course_id}
                    onChange={(e) => setFormData({ ...formData, course_id: Number(e.target.value) })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-slate-50 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                    required
                  >
                    <option value="">Select assigned course...</option>
                    {courses.map((c) => (
                      <option key={c.id} value={c.id}>{c.title}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block font-bold text-slate-700 mb-1">Quiz Title *</label>
                  <input
                    type="text"
                    value={formData.title}
                    onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                    placeholder="e.g. React Fundamentals Checkpoint"
                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                    required
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-700 mb-1">Description</label>
                <textarea
                  rows={2}
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  placeholder="Instructions for students taking this quiz..."
                  className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                />
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">Time Limit (Mins)</label>
                  <input
                    type="number"
                    min={1}
                    value={formData.time_limit}
                    onChange={(e) => setFormData({ ...formData, time_limit: Number(e.target.value) })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-700 mb-1">Passing Score (%)</label>
                  <input
                    type="number"
                    min={0}
                    max={100}
                    value={formData.passing_score}
                    onChange={(e) => setFormData({ ...formData, passing_score: Number(e.target.value) })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                    required
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-700 mb-1">Publish Status</label>
                  <select
                    value={formData.is_published ? 'true' : 'false'}
                    onChange={(e) => setFormData({ ...formData, is_published: e.target.value === 'true' })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                  >
                    <option value="true">Published</option>
                    <option value="false">Draft</option>
                  </select>
                </div>
              </div>

              {/* Questions Section */}
              <div className="space-y-4 pt-3 border-t border-slate-100">
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-slate-800">Quiz Questions ({formData.questions.length})</h3>
                  <button
                    type="button"
                    onClick={addQuestion}
                    className="px-3 py-1.5 rounded-xl bg-blue-50 text-blue-700 font-bold hover:bg-blue-100 transition"
                  >
                    + Add Question
                  </button>
                </div>

                {formData.questions.map((q, qIdx) => (
                  <div key={qIdx} className="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-extrabold text-slate-700">Question #{qIdx + 1}</span>
                      {formData.questions.length > 1 && (
                        <button
                          type="button"
                          onClick={() => removeQuestion(qIdx)}
                          className="text-rose-500 hover:text-rose-700 font-bold"
                        >
                          Remove
                        </button>
                      )}
                    </div>

                    <input
                      type="text"
                      value={q.question}
                      onChange={(e) => updateQuestionText(qIdx, e.target.value)}
                      placeholder="Enter question prompt..."
                      className="w-full px-3.5 py-2 rounded-xl border border-slate-200 bg-white font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                      required
                    />

                    <div className="space-y-2">
                      <p className="text-[11px] font-bold text-slate-500">Options (Select radio for correct answer):</p>
                      {q.options.map((opt, oIdx) => (
                        <div key={oIdx} className="flex items-center gap-2">
                          <input
                            type="radio"
                            name={`correct-${qIdx}`}
                            checked={opt.is_correct}
                            onChange={() => setCorrectOption(qIdx, oIdx)}
                            className="text-blue-600 focus:ring-blue-500"
                          />
                          <input
                            type="text"
                            value={opt.option_text}
                            onChange={(e) => updateOptionText(qIdx, oIdx, e.target.value)}
                            placeholder={`Option #${oIdx + 1}`}
                            className="w-full px-3 py-1.5 rounded-xl border border-slate-200 bg-white font-semibold text-slate-800 text-xs focus:outline-hidden focus:border-blue-500"
                            required
                          />
                        </div>
                      ))}

                      {q.options.length < 5 && (
                        <button
                          type="button"
                          onClick={() => addOption(qIdx)}
                          className="text-[11px] font-bold text-blue-600 hover:underline mt-1"
                        >
                          + Add Option
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>

              <div className="pt-3 border-t border-slate-100 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="px-4 py-2.5 rounded-xl border border-slate-200 font-bold text-slate-600 hover:bg-slate-50 transition"
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  disabled={submitting}
                  className="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-extrabold transition shadow-md shadow-blue-500/25"
                >
                  {submitting ? 'Saving...' : editId ? 'Save Changes' : 'Create Quiz'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Results Modal */}
      {resultsModalOpen && selectedQuizResults && (
        <div className="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4 overflow-y-auto backdrop-blur-xs">
          <div className="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div>
                <h3 className="text-base font-black text-slate-900">{selectedQuizResults.quiz.title}</h3>
                <p className="text-xs text-slate-500">Student Submission Results</p>
              </div>
              <button
                type="button"
                onClick={() => setResultsModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 font-bold text-base"
              >
                ✕
              </button>
            </div>

            {selectedQuizResults.attempts.length === 0 ? (
              <div className="py-8 text-center text-slate-400 text-xs italic">
                No student attempts recorded for this quiz yet.
              </div>
            ) : (
              <div className="space-y-2 max-h-80 overflow-y-auto">
                {selectedQuizResults.attempts.map((att) => (
                  <div
                    key={att.id}
                    className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between gap-3 text-xs"
                  >
                    <div>
                      <p className="font-bold text-slate-900">{att.user?.name || 'Student'}</p>
                      <p className="text-[10px] text-slate-400">{att.user?.email} • {new Date(att.created_at).toLocaleDateString()}</p>
                    </div>

                    <div className="text-right">
                      <span
                        className={`px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase ${
                          att.passed ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'
                        }`}
                      >
                        {att.score}% ({att.passed ? 'Passed' : 'Failed'})
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
