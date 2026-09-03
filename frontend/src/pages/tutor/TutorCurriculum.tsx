import { useEffect, useState, useCallback } from 'react'
import { Link, useParams } from 'react-router-dom'
import API from '../../services/api'
import type { Section, Lesson, LessonType } from '../../types/lms'
import type { Course } from '../../types/course'

interface QuizQuestionDraft {
  id?: number
  question: string
  marks: number
  options: { option_text: string; is_correct: boolean }[]
}

export default function TutorCurriculum() {
  const { courseId, id } = useParams<{ courseId?: string; id?: string }>()
  const resolvedCourseId = courseId || id
  const [course, setCourse] = useState<Course | null>(null)
  const [sections, setSections] = useState<Section[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [successMsg, setSuccessMsg] = useState('')

  // Section Modal State
  const [showSectionModal, setShowSectionModal] = useState(false)
  const [editingSection, setEditingSection] = useState<Section | null>(null)
  const [sectionTitle, setSectionTitle] = useState('')
  const [sectionSort, setSectionSort] = useState(0)

  // Lesson Modal State
  const [showLessonModal, setShowLessonModal] = useState(false)
  const [targetSectionId, setTargetSectionId] = useState<number | null>(null)
  const [editingLesson, setEditingLesson] = useState<Lesson | null>(null)
  const [lessonTitle, setLessonTitle] = useState('')
  const [lessonDuration, setLessonDuration] = useState('10 min')
  const [lessonType, setLessonType] = useState<LessonType>('video')
  const [lessonSort, setLessonSort] = useState(0)
  const [lessonIsPublished, setLessonIsPublished] = useState(true)
  const [videoUrl, setVideoUrl] = useState('')
  const [lessonContent, setLessonContent] = useState('')
  const [documentUrl, setDocumentUrl] = useState('')
  const [documentTitle, setDocumentTitle] = useState('')

  // Quiz Builder Modal State
  const [showQuizModal, setShowQuizModal] = useState(false)
  const [activeQuizLesson, setActiveQuizLesson] = useState<Lesson | null>(null)
  const [quizTitle, setQuizTitle] = useState('')
  const [quizPassingScore, setQuizPassingScore] = useState(70)
  const [quizTimeLimit, setQuizTimeLimit] = useState(15)
  const [quizQuestions, setQuizQuestions] = useState<QuizQuestionDraft[]>([
    {
      question: 'Which of the following is the primary principle of this module?',
      marks: 5,
      options: [
        { option_text: 'Declarative component architecture', is_correct: true },
        { option_text: 'Direct global DOM mutations', is_correct: false },
        { option_text: 'Synchronous blocking I/O', is_correct: false },
      ],
    },
  ])

  // Assignment Builder Modal State
  const [showAssignmentModal, setShowAssignmentModal] = useState(false)
  const [activeAssignmentLesson, setActiveAssignmentLesson] = useState<Lesson | null>(null)
  const [assignmentTitle, setAssignmentTitle] = useState('')
  const [assignmentInstructions, setAssignmentInstructions] = useState('')
  const [assignmentDueDate, setAssignmentDueDate] = useState('')
  const [assignmentMaxMarks, setAssignmentMaxMarks] = useState(100)

  const [saving, setSaving] = useState(false)

  const loadCurriculum = useCallback(() => {
    if (!resolvedCourseId) return
    setLoading(true)

    Promise.all([
      API.get<Course>(`/courses/${resolvedCourseId}`),
      API.get<Section[]>(`/courses/${resolvedCourseId}/sections`),
    ])
      .then(([courseRes, secRes]) => {
        setCourse(courseRes.data)
        setSections(Array.isArray(secRes.data) ? secRes.data : [])
      })
      .catch(() => setError('Failed to load course curriculum.'))
      .finally(() => setLoading(false))
  }, [resolvedCourseId])

  useEffect(() => {
    loadCurriculum()
  }, [loadCurriculum])

  // Section Handlers
  const handleSaveSection = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!resolvedCourseId || !sectionTitle.trim()) return

    setSaving(true)
    setError('')
    try {
      if (editingSection) {
        await API.put(`/courses/${resolvedCourseId}/sections/${editingSection.id}`, {
          title: sectionTitle,
          sort_order: sectionSort,
        })
        setSuccessMsg('Section updated successfully.')
      } else {
        await API.post(`/courses/${resolvedCourseId}/sections`, {
          title: sectionTitle,
          sort_order: sectionSort,
        })
        setSuccessMsg('Section created successfully.')
      }
      setShowSectionModal(false)
      setEditingSection(null)
      setSectionTitle('')
      loadCurriculum()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to save section.')
    } finally {
      setSaving(false)
    }
  }

  const handleTogglePublishSection = async (secId: number) => {
    if (!resolvedCourseId) return
    try {
      await API.post(`/courses/${resolvedCourseId}/sections/${secId}/toggle-publish`)
      setSuccessMsg('Module publishing status updated.')
      loadCurriculum()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to toggle module status.')
    }
  }

  const handleDeleteSection = async (secId: number) => {
    if (!resolvedCourseId || !window.confirm('Delete this section and all associated lessons?')) return
    try {
      await API.delete(`/courses/${resolvedCourseId}/sections/${secId}`)
      setSuccessMsg('Section deleted.')
      loadCurriculum()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to delete section.')
    }
  }

  // Move Section Up/Down
  const handleMoveSection = async (index: number, direction: 'up' | 'down') => {
    if (!resolvedCourseId) return
    const targetIndex = direction === 'up' ? index - 1 : index + 1
    if (targetIndex < 0 || targetIndex >= sections.length) return

    const newSections = [...sections]
    const temp = newSections[index]
    newSections[index] = newSections[targetIndex]
    newSections[targetIndex] = temp

    const payload = {
      sections: newSections.map((sec, idx) => ({
        id: sec.id,
        sort_order: idx + 1,
      })),
    }

    try {
      await API.post(`/courses/${resolvedCourseId}/reorder`, payload)
      setSections(newSections)
    } catch {
      loadCurriculum()
    }
  }

  // Lesson Handlers
  const openAddLesson = (secId: number) => {
    setTargetSectionId(secId)
    setEditingLesson(null)
    setLessonTitle('')
    setLessonDuration('10 min')
    setLessonType('video')
    setLessonSort(0)
    setLessonIsPublished(true)
    setVideoUrl('')
    setLessonContent('')
    setDocumentUrl('')
    setDocumentTitle('')
    setShowLessonModal(true)
  }

  const openEditLesson = (lesson: Lesson, secId: number) => {
    setTargetSectionId(secId)
    setEditingLesson(lesson)
    setLessonTitle(lesson.title)
    setLessonDuration(lesson.duration || '10 min')
    setLessonType(lesson.type)
    setLessonSort(lesson.sort_order || 0)
    setLessonIsPublished(lesson.is_published !== false)
    setVideoUrl(lesson.metadata?.video_url || '')
    setLessonContent(lesson.metadata?.content || lesson.description || '')
    setDocumentUrl(lesson.metadata?.document_url || '')
    setDocumentTitle(lesson.metadata?.document_title || '')
    setShowLessonModal(true)
  }

  const handleSaveLesson = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!resolvedCourseId || !targetSectionId || !lessonTitle.trim()) return

    setSaving(true)
    setError('')

    const payload = {
      title: lessonTitle,
      duration: lessonDuration,
      type: lessonType,
      sort_order: lessonSort,
      is_published: lessonIsPublished,
      video_url: videoUrl || undefined,
      content: lessonContent || undefined,
      document_url: documentUrl || undefined,
      document_title: documentTitle || undefined,
    }

    try {
      if (editingLesson) {
        await API.put(
          `/courses/${resolvedCourseId}/sections/${targetSectionId}/lessons/${editingLesson.id}`,
          payload
        )
        setSuccessMsg('Lesson updated successfully.')
      } else {
        await API.post(
          `/courses/${resolvedCourseId}/sections/${targetSectionId}/lessons`,
          payload
        )
        setSuccessMsg('Lesson added to section.')
      }
      setShowLessonModal(false)
      setEditingLesson(null)
      loadCurriculum()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to save lesson.')
    } finally {
      setSaving(false)
    }
  }

  const handleTogglePublishLesson = async (secId: number, lessonId: number) => {
    if (!resolvedCourseId) return
    try {
      await API.post(`/courses/${resolvedCourseId}/sections/${secId}/lessons/${lessonId}/toggle-publish`)
      setSuccessMsg('Lesson publishing status updated.')
      loadCurriculum()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to toggle lesson status.')
    }
  }

  const handleDeleteLesson = async (secId: number, lessonId: number) => {
    if (!resolvedCourseId || !window.confirm('Are you sure you want to delete this lesson?')) return
    try {
      await API.delete(`/courses/${resolvedCourseId}/sections/${secId}/lessons/${lessonId}`)
      setSuccessMsg('Lesson deleted.')
      loadCurriculum()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to delete lesson.')
    }
  }

  // Move Lesson Up/Down
  const handleMoveLesson = async (secId: number, lessonIndex: number, direction: 'up' | 'down') => {
    if (!resolvedCourseId) return
    const section = sections.find((s) => s.id === secId)
    if (!section || !section.lessons) return

    const targetIndex = direction === 'up' ? lessonIndex - 1 : lessonIndex + 1
    if (targetIndex < 0 || targetIndex >= section.lessons.length) return

    const newLessons = [...section.lessons]
    const temp = newLessons[lessonIndex]
    newLessons[lessonIndex] = newLessons[targetIndex]
    newLessons[targetIndex] = temp

    const payload = {
      lessons: newLessons.map((l, idx) => ({
        id: l.id,
        section_id: secId,
        sort_order: idx + 1,
      })),
    }

    try {
      await API.post(`/courses/${resolvedCourseId}/reorder`, payload)
      loadCurriculum()
    } catch {
      loadCurriculum()
    }
  }

  // Quiz Builder Handlers
  const openQuizBuilder = (lesson: Lesson) => {
    setActiveQuizLesson(lesson)
    setQuizTitle(lesson.quiz?.title || `${lesson.title} - Quiz Checkpoint`)
    setQuizPassingScore(lesson.quiz?.passing_score || 70)
    setQuizTimeLimit(lesson.quiz?.time_limit || 15)

    if (lesson.quiz?.questions && lesson.quiz.questions.length > 0) {
      setQuizQuestions(
        lesson.quiz.questions.map((q) => ({
          id: q.id,
          question: q.question,
          marks: q.marks,
          options: (q.options || []).map((o) => ({
            option_text: o.option_text,
            is_correct: Boolean(o.is_correct),
          })),
        }))
      )
    } else {
      setQuizQuestions([
        {
          question: 'What is the main objective of this topic?',
          marks: 5,
          options: [
            { option_text: 'Core architecture pattern', is_correct: true },
            { option_text: 'Unchecked side effects', is_correct: false },
          ],
        },
      ])
    }
    setShowQuizModal(true)
  }

  const addQuestion = () => {
    setQuizQuestions([
      ...quizQuestions,
      {
        question: `Question ${quizQuestions.length + 1}`,
        marks: 5,
        options: [
          { option_text: 'Option A (Correct)', is_correct: true },
          { option_text: 'Option B', is_correct: false },
        ],
      },
    ])
  }

  const handleSaveQuiz = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!resolvedCourseId || !activeQuizLesson) return

    setSaving(true)
    setError('')
    try {
      await API.post(`/tutor/courses/${resolvedCourseId}/lessons/${activeQuizLesson.id}/quiz`, {
        title: quizTitle,
        passing_score: quizPassingScore,
        time_limit: quizTimeLimit,
        questions: quizQuestions,
      })
      setSuccessMsg('Quiz checkpoint saved successfully!')
      setShowQuizModal(false)
      loadCurriculum()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to save quiz.')
    } finally {
      setSaving(false)
    }
  }

  // Assignment Builder Handlers
  const openAssignmentBuilder = (lesson: Lesson) => {
    setActiveAssignmentLesson(lesson)
    setAssignmentTitle(lesson.assignment?.title || `${lesson.title} - Capstone Challenge`)
    setAssignmentInstructions(
      lesson.assignment?.instructions ||
        'Implement the requested module component, attach your public repository link, and submit for instructor code review.'
    )
    setAssignmentDueDate(lesson.assignment?.due_date ? lesson.assignment.due_date.substring(0, 10) : '')
    setAssignmentMaxMarks(lesson.assignment?.max_marks || 100)
    setShowAssignmentModal(true)
  }

  const handleSaveAssignment = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!resolvedCourseId || !activeAssignmentLesson) return

    setSaving(true)
    setError('')
    try {
      await API.post(
        `/tutor/courses/${resolvedCourseId}/lessons/${activeAssignmentLesson.id}/assignment`,
        {
          title: assignmentTitle,
          instructions: assignmentInstructions,
          due_date: assignmentDueDate || undefined,
          max_marks: assignmentMaxMarks,
        }
      )
      setSuccessMsg('Assignment capstone saved successfully!')
      setShowAssignmentModal(false)
      loadCurriculum()
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to save assignment.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <Link to="/tutor/courses" className="text-xs font-bold text-blue-600 hover:underline">
              ← My Courses
            </Link>
            <span className="text-xs text-slate-400">/</span>
            <span className="text-xs text-slate-500">{course?.title || 'Course'}</span>
          </div>
          <h1 className="text-2xl font-black text-slate-900 mt-1">LMS Curriculum Builder</h1>
          <p className="text-xs text-slate-500">
            Structure sections, publish video streams, configure interactive quizzes, and set up coding assignments.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => {
              setEditingSection(null)
              setSectionTitle('')
              setSectionSort(sections.length + 1)
              setShowSectionModal(true)
            }}
            className="px-4 py-2.5 rounded-xl font-bold text-xs bg-blue-600 hover:bg-blue-700 text-white transition flex items-center gap-1.5 shadow-sm"
          >
            <span>+</span> Add Section
          </button>
        </div>
      </div>

      {successMsg && (
        <div className="p-4 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-2xl border border-emerald-200">
          ✓ {successMsg}
        </div>
      )}
      {error && (
        <div className="p-4 bg-red-50 text-red-700 text-xs font-bold rounded-2xl border border-red-200">
          ⚠️ {error}
        </div>
      )}

      {/* Curriculum Sections List */}
      {loading ? (
        <div className="p-12 text-center text-xs text-slate-400">Loading curriculum hierarchy...</div>
      ) : sections.length === 0 ? (
        <div className="bg-white rounded-3xl p-10 text-center border border-slate-200/80 shadow-xs">
          <span className="text-4xl mb-2 block">📚</span>
          <h3 className="text-base font-bold text-slate-900">No Sections Added Yet</h3>
          <p className="text-xs text-slate-500 mt-1 mb-6">
            Begin by adding your first module/section to organize lessons.
          </p>
          <button
            type="button"
            onClick={() => {
              setEditingSection(null)
              setSectionTitle('')
              setSectionSort(1)
              setShowSectionModal(true)
            }}
            className="px-6 py-2.5 rounded-xl text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white"
          >
            + Create First Module
          </button>
        </div>
      ) : (
        <div className="space-y-6">
          {sections.map((section, sIdx) => {
            const lessons = section.lessons || []

            return (
              <div
                key={section.id}
                className="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden"
              >
                {/* Section Header */}
                <div className="p-5 bg-slate-50 border-b border-slate-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <span className="w-7 h-7 rounded-lg bg-blue-100 text-blue-700 font-bold text-xs flex items-center justify-center">
                      {sIdx + 1}
                    </span>
                    <div>
                      <div className="flex items-center gap-2">
                        <h3 className="text-sm font-bold text-slate-900">{section.title}</h3>
                        <span
                          className={`text-[9px] font-black uppercase px-2 py-0.5 rounded-md ${
                            section.is_published !== false
                              ? 'bg-emerald-100 text-emerald-700'
                              : 'bg-slate-200 text-slate-600'
                          }`}
                        >
                          {section.is_published !== false ? 'Published' : 'Draft'}
                        </span>
                      </div>
                      <p className="text-[11px] text-slate-400">{lessons.length} lessons in this module</p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 text-xs font-bold">
                    <button
                      type="button"
                      disabled={sIdx === 0}
                      onClick={() => handleMoveSection(sIdx, 'up')}
                      className="px-2 py-1.5 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-30 disabled:cursor-not-allowed transition"
                      title="Move Module Up"
                    >
                      ↑
                    </button>
                    <button
                      type="button"
                      disabled={sIdx === sections.length - 1}
                      onClick={() => handleMoveSection(sIdx, 'down')}
                      className="px-2 py-1.5 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-30 disabled:cursor-not-allowed transition"
                      title="Move Module Down"
                    >
                      ↓
                    </button>
                    <button
                      type="button"
                      onClick={() => handleTogglePublishSection(section.id)}
                      className={`px-3 py-1.5 rounded-xl transition ${
                        section.is_published !== false
                          ? 'bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100'
                          : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100'
                      }`}
                    >
                      {section.is_published !== false ? 'Unpublish' : 'Publish'}
                    </button>
                    <button
                      type="button"
                      onClick={() => openAddLesson(section.id)}
                      className="px-3 py-1.5 rounded-xl bg-blue-600 text-white hover:bg-blue-700 transition"
                    >
                      + Add Lesson
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setEditingSection(section)
                        setSectionTitle(section.title)
                        setSectionSort(section.sort_order || sIdx + 1)
                        setShowSectionModal(true)
                      }}
                      className="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 transition"
                    >
                      Rename
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDeleteSection(section.id)}
                      className="px-3 py-1.5 rounded-xl bg-red-50 text-red-600 hover:bg-red-100 transition"
                    >
                      Delete
                    </button>
                  </div>
                </div>

                {/* Lessons List in Section */}
                <div className="p-4 divide-y divide-slate-100">
                  {lessons.length === 0 ? (
                    <p className="text-xs text-slate-400 py-3 text-center">
                      No lessons in this module. Click "+ Add Lesson" above.
                    </p>
                  ) : (
                    lessons.map((lesson, lIdx) => (
                      <div
                        key={lesson.id}
                        className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs"
                      >
                        <div className="flex items-center gap-3">
                          <span className="text-base">
                            {lesson.type === 'video'
                              ? '🎥'
                              : lesson.type === 'quiz'
                              ? '📝'
                              : lesson.type === 'assignment'
                              ? '🛠️'
                              : lesson.type === 'document'
                              ? '📑'
                              : '📄'}
                          </span>
                          <div>
                            <div className="flex items-center gap-2">
                              <span className="font-bold text-slate-800">
                                {sIdx + 1}.{lIdx + 1} {lesson.title}
                              </span>
                              <span
                                className={`text-[8px] font-black uppercase px-1.5 py-0.5 rounded ${
                                  lesson.is_published !== false
                                    ? 'bg-emerald-100 text-emerald-700'
                                    : 'bg-slate-200 text-slate-600'
                                }`}
                              >
                                {lesson.is_published !== false ? 'Published' : 'Draft'}
                              </span>
                            </div>
                            <div className="flex items-center gap-2 mt-0.5">
                              <span className="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">
                                {lesson.type}
                              </span>
                              <span className="text-[10px] text-slate-400">{lesson.duration || '10 min'}</span>
                              {lesson.metadata?.video_url && (
                                <span className="text-[10px] text-blue-500 truncate max-w-[200px]">
                                  🎥 {lesson.metadata.video_url}
                                </span>
                              )}
                              {lesson.metadata?.document_url && (
                                <span className="text-[10px] text-indigo-500 truncate max-w-[200px]">
                                  📑 {lesson.metadata.document_title || lesson.metadata.document_url}
                                </span>
                              )}
                            </div>
                          </div>
                        </div>

                        <div className="flex items-center gap-2 font-bold shrink-0">
                          <button
                            type="button"
                            disabled={lIdx === 0}
                            onClick={() => handleMoveLesson(section.id, lIdx, 'up')}
                            className="px-2 py-1 rounded bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-30 disabled:cursor-not-allowed transition"
                            title="Move Lesson Up"
                          >
                            ↑
                          </button>
                          <button
                            type="button"
                            disabled={lIdx === lessons.length - 1}
                            onClick={() => handleMoveLesson(section.id, lIdx, 'down')}
                            className="px-2 py-1 rounded bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-30 disabled:cursor-not-allowed transition"
                            title="Move Lesson Down"
                          >
                            ↓
                          </button>
                          <button
                            type="button"
                            onClick={() => handleTogglePublishLesson(section.id, lesson.id)}
                            className={`px-2 py-1 rounded-lg text-[11px] transition ${
                              lesson.is_published !== false
                                ? 'bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100'
                                : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100'
                            }`}
                          >
                            {lesson.is_published !== false ? 'Draft' : 'Publish'}
                          </button>
                          {lesson.type === 'quiz' && (
                            <button
                              type="button"
                              onClick={() => openQuizBuilder(lesson)}
                              className="px-2.5 py-1 rounded-lg bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 transition"
                            >
                              ⚙️ Quiz
                            </button>
                          )}
                          {lesson.type === 'assignment' && (
                            <button
                              type="button"
                              onClick={() => openAssignmentBuilder(lesson)}
                              className="px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-200 hover:bg-purple-100 transition"
                            >
                              ⚙️ Assignment
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={() => openEditLesson(lesson, section.id)}
                            className="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition"
                          >
                            Edit
                          </button>
                          <button
                            type="button"
                            onClick={() => handleDeleteLesson(section.id, lesson.id)}
                            className="px-2.5 py-1 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 transition"
                          >
                            ✕
                          </button>
                        </div>
                      </div>
                    ))
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Section Modal */}
      {showSectionModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl border border-slate-100">
            <h3 className="text-lg font-bold text-slate-900 mb-4">
              {editingSection ? 'Rename Section' : 'Create Module Section'}
            </h3>
            <form onSubmit={handleSaveSection} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Module Title
                </label>
                <input
                  type="text"
                  value={sectionTitle}
                  onChange={(e) => setSectionTitle(e.target.value)}
                  placeholder="e.g. Module 1: Foundations & Architecture"
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Sort Order
                </label>
                <input
                  type="number"
                  value={sectionSort}
                  onChange={(e) => setSectionSort(Number(e.target.value))}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                />
              </div>

              <div className="flex justify-end gap-2 pt-3">
                <button
                  type="button"
                  onClick={() => setShowSectionModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700"
                >
                  {saving ? 'Saving...' : 'Save Section'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Lesson Modal */}
      {showLessonModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
            <h3 className="text-lg font-bold text-slate-900 mb-4">
              {editingLesson ? 'Edit Lesson' : 'Add New Lesson'}
            </h3>

            <form onSubmit={handleSaveLesson} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Lesson Title
                </label>
                <input
                  type="text"
                  value={lessonTitle}
                  onChange={(e) => setLessonTitle(e.target.value)}
                  placeholder="e.g. Deep Dive into State Management"
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  required
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Lesson Type
                  </label>
                  <select
                    value={lessonType}
                    onChange={(e) => setLessonType(e.target.value as LessonType)}
                    className="w-full px-3 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none bg-white"
                  >
                    <option value="video">🎥 Video Lesson</option>
                    <option value="text">📄 Text Lesson</option>
                    <option value="article">📰 Article Lesson</option>
                    <option value="document">📑 Document / Resource</option>
                    <option value="quiz">📝 Quiz Checkpoint</option>
                    <option value="assignment">🛠️ Practical Assignment</option>
                    <option value="project">🏆 Capstone Project</option>
                  </select>
                </div>

                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Estimated Duration
                  </label>
                  <input
                    type="text"
                    value={lessonDuration}
                    onChange={(e) => setLessonDuration(e.target.value)}
                    placeholder="e.g. 15 min"
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  />
                </div>
              </div>

              {lessonType === 'video' && (
                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Video Stream URL (YouTube, Vimeo, MP4, or WebM)
                  </label>
                  <input
                    type="text"
                    value={videoUrl}
                    onChange={(e) => setVideoUrl(e.target.value)}
                    placeholder="https://www.youtube.com/watch?v=... or https://..."
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  />
                </div>
              )}

              {lessonType === 'document' && (
                <div className="space-y-3">
                  <div>
                    <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                      Document Title / Reference
                    </label>
                    <input
                      type="text"
                      value={documentTitle}
                      onChange={(e) => setDocumentTitle(e.target.value)}
                      placeholder="e.g. System Architecture Diagram PDF"
                      className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                    />
                  </div>
                  <div>
                    <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                      Document Download / View URL
                    </label>
                    <input
                      type="text"
                      value={documentUrl}
                      onChange={(e) => setDocumentUrl(e.target.value)}
                      placeholder="https://... or /documents/..."
                      className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                    />
                  </div>
                </div>
              )}

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  {lessonType === 'text' ? 'Article Body / Markdown Content' : 'Lesson Summary & Notes'}
                </label>
                <textarea
                  value={lessonContent}
                  onChange={(e) => setLessonContent(e.target.value)}
                  rows={lessonType === 'text' ? 8 : 4}
                  placeholder={
                    lessonType === 'text'
                      ? '# Comprehensive Guide\n\nExplain key concepts, attach code snippets, and structured notes...'
                      : 'Key concepts, starter notes, or instructions for students...'
                  }
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none font-mono text-xs"
                />
              </div>

              <div className="flex items-center gap-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
                <input
                  type="checkbox"
                  id="lessonPublishedToggle"
                  checked={lessonIsPublished}
                  onChange={(e) => setLessonIsPublished(e.target.checked)}
                  className="w-4 h-4 text-blue-600 rounded"
                />
                <label htmlFor="lessonPublishedToggle" className="text-xs font-bold text-slate-700 cursor-pointer">
                  Publish Lesson immediately (uncheck to save as Draft)
                </label>
              </div>

              <div className="flex justify-end gap-2 pt-3">
                <button
                  type="button"
                  onClick={() => setShowLessonModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700"
                >
                  {saving ? 'Saving...' : editingLesson ? 'Update Lesson' : 'Create Lesson'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Quiz Builder Modal */}
      {showQuizModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto space-y-6">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-amber-100 text-amber-800 rounded-md">
                Interactive Assessment
              </span>
              <h3 className="text-lg font-bold text-slate-900 mt-1">
                Configure Quiz Checkpoint
              </h3>
              <p className="text-xs text-slate-500">
                Add multiple choice questions, set passing percentage, and configure time limit.
              </p>
            </div>

            <form onSubmit={handleSaveQuiz} className="space-y-6 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="sm:col-span-1">
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Quiz Title
                  </label>
                  <input
                    type="text"
                    value={quizTitle}
                    onChange={(e) => setQuizTitle(e.target.value)}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 outline-none focus:ring-2 focus:ring-blue-600"
                    required
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Passing Score (%)
                  </label>
                  <input
                    type="number"
                    value={quizPassingScore}
                    onChange={(e) => setQuizPassingScore(Number(e.target.value))}
                    min={1}
                    max={100}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 outline-none focus:ring-2 focus:ring-blue-600"
                    required
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Time Limit (Minutes)
                  </label>
                  <input
                    type="number"
                    value={quizTimeLimit}
                    onChange={(e) => setQuizTimeLimit(Number(e.target.value))}
                    min={1}
                    max={180}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 outline-none focus:ring-2 focus:ring-blue-600"
                    required
                  />
                </div>
              </div>

              {/* Questions List */}
              <div className="space-y-4 pt-2 border-t border-slate-100">
                <div className="flex items-center justify-between">
                  <h4 className="font-bold text-slate-900">Quiz Questions ({quizQuestions.length})</h4>
                  <button
                    type="button"
                    onClick={addQuestion}
                    className="px-3 py-1 bg-blue-50 text-blue-700 font-bold rounded-lg hover:bg-blue-100"
                  >
                    + Add Question
                  </button>
                </div>

                {quizQuestions.map((q, qIdx) => (
                  <div key={qIdx} className="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-bold text-slate-700 text-xs">Question {qIdx + 1}</span>
                      <div className="flex items-center gap-2">
                        <span className="text-[10px] text-slate-400">Marks:</span>
                        <input
                          type="number"
                          value={q.marks}
                          onChange={(e) => {
                            const val = Number(e.target.value)
                            setQuizQuestions((prev) =>
                              prev.map((item, idx) => (idx === qIdx ? { ...item, marks: val } : item))
                            )
                          }}
                          className="w-14 px-2 py-0.5 rounded border border-slate-300 text-xs text-center"
                          min={1}
                        />
                        {quizQuestions.length > 1 && (
                          <button
                            type="button"
                            onClick={() =>
                              setQuizQuestions((prev) => prev.filter((_, idx) => idx !== qIdx))
                            }
                            className="text-red-600 font-bold hover:underline text-xs"
                          >
                            Remove
                          </button>
                        )}
                      </div>
                    </div>

                    <input
                      type="text"
                      value={q.question}
                      onChange={(e) => {
                        const val = e.target.value
                        setQuizQuestions((prev) =>
                          prev.map((item, idx) => (idx === qIdx ? { ...item, question: val } : item))
                        )
                      }}
                      className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs font-semibold"
                      required
                    />

                    {/* Options */}
                    <div className="space-y-1.5 pl-2">
                      <p className="text-[10px] font-bold text-slate-400 uppercase">
                        Select radio button to indicate correct answer:
                      </p>
                      {q.options.map((opt, optIdx) => (
                        <div key={optIdx} className="flex items-center gap-2">
                          <input
                            type="radio"
                            name={`correct-${qIdx}`}
                            checked={opt.is_correct}
                            onChange={() => {
                              setQuizQuestions((prev) =>
                                prev.map((item, idx) =>
                                  idx === qIdx
                                    ? {
                                        ...item,
                                        options: item.options.map((o, oIdx) => ({
                                          ...o,
                                          is_correct: oIdx === optIdx,
                                        })),
                                      }
                                    : item
                                )
                              )
                            }}
                            className="text-blue-600"
                          />
                          <input
                            type="text"
                            value={opt.option_text}
                            onChange={(e) => {
                              const val = e.target.value
                              setQuizQuestions((prev) =>
                                prev.map((item, idx) =>
                                  idx === qIdx
                                    ? {
                                        ...item,
                                        options: item.options.map((o, oIdx) =>
                                          oIdx === optIdx ? { ...o, option_text: val } : o
                                        ),
                                      }
                                    : item
                                )
                              )
                            }}
                            className="flex-1 px-3 py-1.5 rounded-lg border border-slate-300 text-xs"
                            required
                          />
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
              </div>

              <div className="flex justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowQuizModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-6 py-2 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700"
                >
                  {saving ? 'Saving Quiz...' : 'Save Quiz Configuration'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Assignment Builder Modal */}
      {showAssignmentModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-100 space-y-4">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-100 text-purple-800 rounded-md">
                Capstone Challenge
              </span>
              <h3 className="text-lg font-bold text-slate-900 mt-1">Configure Assignment</h3>
              <p className="text-xs text-slate-500">
                Set problem statement instructions, maximum marks, and submission deadlines.
              </p>
            </div>

            <form onSubmit={handleSaveAssignment} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Assignment Title
                </label>
                <input
                  type="text"
                  value={assignmentTitle}
                  onChange={(e) => setAssignmentTitle(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 outline-none focus:ring-2 focus:ring-blue-600"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Problem Statement & Instructions
                </label>
                <textarea
                  value={assignmentInstructions}
                  onChange={(e) => setAssignmentInstructions(e.target.value)}
                  rows={5}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 outline-none focus:ring-2 focus:ring-blue-600 leading-relaxed"
                  required
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Max Marks / Points
                  </label>
                  <input
                    type="number"
                    value={assignmentMaxMarks}
                    onChange={(e) => setAssignmentMaxMarks(Number(e.target.value))}
                    min={1}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 outline-none focus:ring-2 focus:ring-blue-600"
                    required
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                    Due Date (Optional)
                  </label>
                  <input
                    type="date"
                    value={assignmentDueDate}
                    onChange={(e) => setAssignmentDueDate(e.target.value)}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 outline-none focus:ring-2 focus:ring-blue-600"
                  />
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-3">
                <button
                  type="button"
                  onClick={() => setShowAssignmentModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700"
                >
                  {saving ? 'Saving...' : 'Save Assignment'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
