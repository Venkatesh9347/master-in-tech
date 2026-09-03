import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import API from '../../services/api'
import type { Course } from '../../types/course'

interface AnalyticsData {
  course: Course & {
    sections_count?: number
    lessons_count?: number
  }
  total_enrolled: number
  in_progress: number
  completed: number
  average_progress: number
  average_rating: number
  reviews_count: number
  reviews: { id: number; rating: number; review_text: string; created_at: string; user?: { name: string } }[]
  submissions_count: number
  graded_submissions_count: number
  quiz_attempts_count: number
  average_quiz_score: number
}

export default function TutorCourseAnalytics() {
  const { id } = useParams<{ id: string }>()
  const [data, setData] = useState<AnalyticsData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!id) return
    setLoading(true)

    API.get<AnalyticsData>(`/tutor/courses/${id}/analytics`)
      .then((res) => setData(res.data))
      .catch((err: unknown) => {
        const response = err as { response?: { data?: { message?: string } } }
        setError(response.response?.data?.message || 'Failed to load course analytics.')
      })
      .finally(() => setLoading(false))
  }, [id])

  if (loading) {
    return (
      <div className="p-12 text-center text-xs font-semibold text-slate-500">
        <span className="animate-spin inline-block w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full mb-2" />
        <p>Loading course performance metrics...</p>
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-xs text-center">
        <span className="text-3xl mb-2 block">⚠️</span>
        <h2 className="text-base font-bold text-slate-900 mb-1">Analytics Unavailable</h2>
        <p className="text-xs text-slate-500 mb-4">{error || 'Could not load analytics for this course.'}</p>
        <Link to="/tutor/courses" className="text-xs font-bold text-blue-600 hover:underline">
          ← Back to My Courses
        </Link>
      </div>
    )
  }

  const { course } = data

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <Link to="/tutor/courses" className="text-xs font-bold text-blue-600 hover:underline">
              ← Courses
            </Link>
            <span className="text-xs text-slate-400">/</span>
            <span className="text-xs text-slate-500">{course.title}</span>
          </div>
          <h1 className="text-2xl font-black text-slate-900 mt-1">Course Performance Analytics</h1>
          <p className="text-xs text-slate-500">
            Real-time enrollment, curriculum completion, and assessment benchmarks.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Link
            to={`/tutor/courses/${course.id}/curriculum`}
            className="px-4 py-2 rounded-xl text-xs font-bold bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 transition"
          >
            🛠️ Curriculum Builder
          </Link>
          <Link
            to={`/courses/${course.id}`}
            className="px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition"
          >
            🌐 Public View
          </Link>
        </div>
      </div>

      {/* Metrics Row */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
          <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Enrolled</p>
          <p className="text-3xl font-black text-slate-900 mt-1">{data.total_enrolled}</p>
          <p className="text-[10px] text-slate-500 mt-0.5">Students</p>
        </div>

        <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
          <p className="text-[11px] font-bold text-blue-600 uppercase tracking-wider">In Progress</p>
          <p className="text-3xl font-black text-blue-600 mt-1">{data.in_progress}</p>
          <p className="text-[10px] text-slate-500 mt-0.5">Active learners</p>
        </div>

        <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
          <p className="text-[11px] font-bold text-emerald-600 uppercase tracking-wider">Completed</p>
          <p className="text-3xl font-black text-emerald-600 mt-1">{data.completed}</p>
          <p className="text-[10px] text-slate-500 mt-0.5">Certificates earned</p>
        </div>

        <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
          <p className="text-[11px] font-bold text-amber-600 uppercase tracking-wider">Average Rating</p>
          <p className="text-3xl font-black text-amber-600 mt-1">★ {data.average_rating}</p>
          <p className="text-[10px] text-slate-500 mt-0.5">{data.reviews_count} reviews</p>
        </div>
      </div>

      {/* 2-Column: Assessments & Reviews */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {/* Assessment Performance */}
        <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
          <h2 className="text-base font-bold text-slate-900 flex items-center gap-2">
            <span>📝</span> Assessments & Quizzes
          </h2>

          <div className="grid grid-cols-2 gap-3 text-xs">
            <div className="p-3.5 bg-slate-50 rounded-2xl border border-slate-100">
              <p className="text-slate-400 font-bold uppercase text-[10px]">Quiz Attempts</p>
              <p className="text-2xl font-black text-slate-900 mt-0.5">{data.quiz_attempts_count}</p>
            </div>
            <div className="p-3.5 bg-slate-50 rounded-2xl border border-slate-100">
              <p className="text-slate-400 font-bold uppercase text-[10px]">Avg Quiz Score</p>
              <p className="text-2xl font-black text-emerald-600 mt-0.5">{data.average_quiz_score}%</p>
            </div>
            <div className="p-3.5 bg-slate-50 rounded-2xl border border-slate-100">
              <p className="text-slate-400 font-bold uppercase text-[10px]">Submissions</p>
              <p className="text-2xl font-black text-blue-600 mt-0.5">{data.submissions_count}</p>
            </div>
            <div className="p-3.5 bg-slate-50 rounded-2xl border border-slate-100">
              <p className="text-slate-400 font-bold uppercase text-[10px]">Graded</p>
              <p className="text-2xl font-black text-purple-600 mt-0.5">{data.graded_submissions_count}</p>
            </div>
          </div>

          <div className="pt-2">
            <Link
              to="/tutor/submissions"
              className="w-full py-2.5 rounded-xl font-bold text-xs text-center text-white bg-blue-600 hover:bg-blue-700 transition block"
            >
              Open Grading Desk →
            </Link>
          </div>
        </div>

        {/* Student Reviews Breakdown */}
        <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-bold text-slate-900 flex items-center gap-2">
              <span>⭐</span> Student Feedback
            </h2>
            <span className="text-xs font-bold text-amber-600">★ {data.average_rating} / 5.0</span>
          </div>

          {data.reviews.length === 0 ? (
            <div className="p-6 text-center bg-slate-50 rounded-2xl text-xs text-slate-500">
              No written student reviews for this course yet.
            </div>
          ) : (
            <div className="space-y-3 max-h-64 overflow-y-auto pr-1">
              {data.reviews.map((rev) => (
                <div key={rev.id} className="p-3.5 bg-slate-50 rounded-2xl border border-slate-100 text-xs space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="font-bold text-slate-800">{rev.user?.name || 'Verified Learner'}</span>
                    <span className="text-amber-500 font-bold">{'★'.repeat(rev.rating)}</span>
                  </div>
                  <p className="text-slate-600">{rev.review_text}</p>
                  <span className="text-[10px] text-slate-400 block">{new Date(rev.created_at).toLocaleDateString()}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
