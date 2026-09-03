import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import API from '../../services/api'
import type { Course } from '../../types/course'

const categories = [
  'Cyber Security',
  'DATABASE',
  'CLOUD COMPUTING',
  'DATA ENGINEERING',
  'DATA ANALYST',
  'DATA SCIENCE',
  'SAP',
  'ARTIFICIAL INTELLIGENCE',
  'FULL STACK',
  'Marketing & Business',
  'Healthcare & Life Sciences',
  'Quality Assurance & Testing',
  'Mobile Engineering',
  'Design & Creative',
]

export default function TutorCourseForm() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const isEditing = Boolean(id)

  const [form, setForm] = useState({
    title: '',
    description: '',
    full_description: '',
    category: 'FULL STACK',
    duration: '8 weeks',
    difficulty: 'Intermediate',
    status: 'published',
  })
  const [loading, setLoading] = useState(isEditing)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [successMsg, setSuccessMsg] = useState('')

  useEffect(() => {
    if (isEditing && id) {
      API.get<Course>(`/tutor/courses/${id}`)
        .then((res) => {
          const c = res.data
          setForm({
            title: c.title,
            description: c.description,
            full_description: c.full_description || c.description || '',
            category: c.category || 'FULL STACK',
            duration: c.duration,
            difficulty: c.difficulty,
            status: c.status || (c.is_published ? 'published' : 'draft'),
          })
        })
        .catch((err: unknown) => {
          const response = err as { response?: { data?: { message?: string } } }
          setError(response.response?.data?.message || 'Failed to load course details or unauthorized.')
        })
        .finally(() => setLoading(false))
    }
  }, [id, isEditing])

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>
  ) => {
    setForm({ ...form, [e.target.name]: e.target.value })
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setError('')
    setSuccessMsg('')

    try {
      if (isEditing && id) {
        await API.put(`/tutor/courses/${id}`, {
          ...form,
          is_published: form.status === 'published',
        })
        setSuccessMsg('Course updated successfully!')
        setTimeout(() => navigate('/tutor/courses'), 700)
      } else {
        const res = await API.post('/tutor/courses', {
          ...form,
          is_published: form.status === 'published',
        })
        setSuccessMsg('Course created successfully!')
        setTimeout(() => {
          navigate(`/tutor/courses/${res.data.id}/curriculum`)
        }, 700)
      }
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(
        response.response?.data?.message || 'Failed to save course. Please check all fields.'
      )
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="p-12 text-center text-xs font-semibold text-slate-500">
        <span className="animate-spin inline-block w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full mb-2" />
        <p>Loading course editor...</p>
      </div>
    )
  }

  return (
    <div className="max-w-4xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <Link to="/tutor/courses" className="text-xs font-bold text-blue-600 hover:underline">
          ← Back to Course Inventory
        </Link>
      </div>

      <div className="bg-white rounded-3xl p-8 sm:p-10 border border-slate-200/80 shadow-xs">
        <h1 className="text-2xl font-black text-slate-900 mb-1">
          {isEditing ? 'Edit Course Details' : 'Create New Course'}
        </h1>
        <p className="text-xs text-slate-500 mb-8">
          Configure curriculum parameters, learning track, and publication status for learners.
        </p>

        {error && (
          <div className="mb-6 p-4 rounded-2xl bg-red-50 text-red-700 text-xs font-bold border border-red-200">
            ⚠️ {error}
          </div>
        )}

        {successMsg && (
          <div className="mb-6 p-4 rounded-2xl bg-emerald-50 text-emerald-700 text-xs font-bold border border-emerald-200">
            ✓ {successMsg}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div className="sm:col-span-2">
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                Course Title
              </label>
              <input
                type="text"
                name="title"
                value={form.title}
                onChange={handleChange}
                placeholder="e.g. Master Full Stack TypeScript & Cloud Systems"
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-xs text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none"
                required
              />
            </div>

            <div>
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                Domain Category
              </label>
              <select
                name="category"
                value={form.category}
                onChange={handleChange}
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-xs text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none bg-white"
              >
                {categories.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                Difficulty Level
              </label>
              <select
                name="difficulty"
                value={form.difficulty}
                onChange={handleChange}
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-xs text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none bg-white"
              >
                <option value="Basic">Basic</option>
                <option value="Intermediate">Intermediate</option>
                <option value="Advanced">Advanced</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                Estimated Duration
              </label>
              <input
                type="text"
                name="duration"
                value={form.duration}
                onChange={handleChange}
                placeholder="e.g. 10 weeks"
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-xs text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none"
                required
              />
            </div>

            <div>
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                Publication Status
              </label>
              <select
                name="status"
                value={form.status}
                onChange={handleChange}
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-xs text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none bg-white"
              >
                <option value="published">● Published (Visible to learners)</option>
                <option value="draft">○ Draft (In progress, hidden from public)</option>
              </select>
            </div>

            <div className="sm:col-span-2">
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                Course Description & Syllabus Highlights
              </label>
              <textarea
                name="description"
                value={form.description}
                onChange={handleChange}
                rows={5}
                placeholder="Describe key learning outcomes, real world projects, and prerequisites..."
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-xs text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none leading-relaxed"
                required
              />
            </div>
          </div>

          <div className="flex items-center justify-end gap-3 pt-6 border-t border-slate-100">
            <Link
              to="/tutor/courses"
              className="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition"
            >
              Cancel
            </Link>
            <button
              type="submit"
              disabled={saving}
              className="px-6 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 active:bg-blue-800 shadow-sm transition disabled:opacity-50"
            >
              {saving ? 'Saving Course...' : isEditing ? 'Update Course' : 'Create Course & Open Builder →'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
