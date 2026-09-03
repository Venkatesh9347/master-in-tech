import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import ImageUploadField from '../../components/admin/ImageUploadField'
import type { Course } from '../../types/course'

interface LearningPathItem {
  id: number
  title: string
  slug: string
  description?: string | null
  icon?: string | null
  image?: string | null
  difficulty: string
  estimated_duration: string
  display_order: number
  is_published: boolean
  courses?: Course[]
}

export default function AdminLearningPaths() {
  const [paths, setPaths] = useState<LearningPathItem[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editingPath, setEditingPath] = useState<LearningPathItem | null>(null)

  // Form State
  const [title, setTitle] = useState('')
  const [slug, setSlug] = useState('')
  const [description, setDescription] = useState('')
  const [icon, setIcon] = useState('🗺️')
  const [image, setImage] = useState('')
  const [difficulty, setDifficulty] = useState('Beginner to Advanced')
  const [duration, setDuration] = useState('6 Months')
  const [selectedCourseIds, setSelectedCourseIds] = useState<number[]>([])
  const [isPublished, setIsPublished] = useState(true)

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadData = useCallback(() => {
    setLoading(true)
    Promise.all([
      API.get<LearningPathItem[]>('/admin/learning-paths'),
      API.get<Course[]>('/courses'),
    ])
      .then(([pathsRes, coursesRes]) => {
        setPaths(Array.isArray(pathsRes.data) ? pathsRes.data : [])
        setCourses(Array.isArray(coursesRes.data) ? coursesRes.data : [])
      })
      .catch(() => setErrorMsg('Failed to load learning paths.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const openCreateModal = () => {
    setEditingPath(null)
    setTitle('')
    setSlug('')
    setDescription('')
    setIcon('🗺️')
    setImage('')
    setDifficulty('Beginner to Advanced')
    setDuration('6 Months')
    setSelectedCourseIds([])
    setIsPublished(true)
    setShowModal(true)
  }

  const openEditModal = (path: LearningPathItem) => {
    setEditingPath(path)
    setTitle(path.title)
    setSlug(path.slug)
    setDescription(path.description || '')
    setIcon(path.icon || '🗺️')
    setImage(path.image || '')
    setDifficulty(path.difficulty || 'Beginner to Advanced')
    setDuration(path.estimated_duration || '6 Months')
    setSelectedCourseIds(path.courses?.map((c) => c.id) || [])
    setIsPublished(path.is_published)
    setShowModal(true)
  }

  const toggleCourseSelection = (courseId: number) => {
    if (selectedCourseIds.includes(courseId)) {
      setSelectedCourseIds(selectedCourseIds.filter((id) => id !== courseId))
    } else {
      setSelectedCourseIds([...selectedCourseIds, courseId])
    }
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const payload = {
      title: title.trim(),
      slug: slug.trim() || undefined,
      description: description.trim() || undefined,
      icon: icon.trim() || undefined,
      image: image.trim() || undefined,
      difficulty: difficulty.trim() || undefined,
      estimated_duration: duration.trim() || undefined,
      course_ids: selectedCourseIds,
      is_published: isPublished,
    }

    try {
      if (editingPath) {
        await API.put(`/admin/learning-paths/${editingPath.id}`, payload)
        setSuccessMsg(`Learning Path '${title}' updated!`)
      } else {
        await API.post('/admin/learning-paths', payload)
        setSuccessMsg(`Learning Path '${title}' created!`)
      }
      setShowModal(false)
      loadData()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to save learning path.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (path: LearningPathItem) => {
    if (!window.confirm(`Delete learning path '${path.title}'?`)) return

    try {
      await API.delete(`/admin/learning-paths/${path.id}`)
      setSuccessMsg(`Learning path '${path.title}' deleted.`)
      loadData()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to delete learning path.')
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Learning Path Management</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Create structured career specialization roadmaps combining sequential courses.
          </p>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition flex items-center gap-2"
        >
          <span>+</span> Create Learning Path
        </button>
      </div>

      {successMsg && (
        <div className="p-4 bg-emerald-950/80 border border-emerald-800 text-emerald-300 rounded-2xl text-xs font-bold">
          ✓ {successMsg}
        </div>
      )}

      {errorMsg && (
        <div className="p-4 bg-red-950/80 border border-red-800 text-red-300 rounded-2xl text-xs font-bold">
          ⚠️ {errorMsg}
        </div>
      )}

      {/* List */}
      <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8 space-y-4">
        {loading ? (
          <p className="text-xs text-slate-500 py-8 text-center">Loading learning paths...</p>
        ) : paths.length === 0 ? (
          <div className="py-10 text-center text-slate-500 text-xs">
            <span className="text-3xl mb-2 block">🗺️</span>
            <p className="font-bold text-slate-300">No learning paths found</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {paths.map((p) => (
              <div
                key={p.id}
                className="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4 hover:border-slate-700 transition"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <span className="w-12 h-12 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-center text-2xl">
                      {p.icon || '🗺️'}
                    </span>
                    <div>
                      <h3 className="font-bold text-white text-base leading-tight">{p.title}</h3>
                      <p className="text-[11px] text-purple-400 font-mono">{p.slug}</p>
                    </div>
                  </div>
                  <span
                    className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                      p.is_published
                        ? 'bg-emerald-950 text-emerald-300 border border-emerald-800'
                        : 'bg-slate-800 text-slate-400 border border-slate-700'
                    }`}
                  >
                    {p.is_published ? 'Published' : 'Draft'}
                  </span>
                </div>

                <p className="text-xs text-slate-300 leading-relaxed">{p.description}</p>

                <div className="flex items-center gap-4 text-xs text-slate-400">
                  <span>⏱️ {p.estimated_duration}</span>
                  <span>📊 {p.difficulty}</span>
                  <span>📚 {p.courses?.length || 0} Courses</span>
                </div>

                {p.courses && p.courses.length > 0 && (
                  <div className="space-y-1 pt-2 border-t border-slate-800/80">
                    <p className="text-[10px] font-bold uppercase text-slate-500">Curriculum Sequence:</p>
                    <div className="flex flex-wrap gap-1.5">
                      {p.courses.map((c, idx) => (
                        <span
                          key={c.id}
                          className="px-2 py-0.5 rounded-md bg-slate-950 text-[10px] text-slate-300 border border-slate-800"
                        >
                          {idx + 1}. {c.title}
                        </span>
                      ))}
                    </div>
                  </div>
                )}

                <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => openEditModal(p)}
                    className="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs font-bold transition"
                  >
                    Edit Roadmap
                  </button>
                  <button
                    type="button"
                    onClick={() => handleDelete(p)}
                    className="px-3 py-1.5 rounded-lg bg-red-950/60 text-red-300 hover:bg-red-900 text-xs font-bold transition"
                  >
                    Delete
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* MODAL */}
      {showModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-800 space-y-5 text-white max-h-[90vh] overflow-y-auto">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-950 text-purple-300 border border-purple-800 rounded-md">
                Learning Path Editor
              </span>
              <h3 className="text-lg font-bold text-white mt-1">
                {editingPath ? `Edit '${editingPath.title}'` : 'Create Career Roadmap'}
              </h3>
            </div>

            <form onSubmit={handleSave} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Learning Path Title <span className="text-red-400">*</span>
                </label>
                <input
                  type="text"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="e.g. AI & Intelligent Systems Architect"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Duration</label>
                  <input
                    type="text"
                    value={duration}
                    onChange={(e) => setDuration(e.target.value)}
                    placeholder="6 Months"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Difficulty</label>
                  <input
                    type="text"
                    value={difficulty}
                    onChange={(e) => setDifficulty(e.target.value)}
                    placeholder="Beginner to Advanced"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Roadmap Description
                </label>
                <textarea
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  rows={3}
                  placeholder="Career roadmap summary, skill progressions, learning milestones..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              {/* Learning Path Image / Thumbnail */}
              <div>
                <ImageUploadField
                  label="Learning Path Cover Image"
                  value={image}
                  onChange={(url) => setImage(url)}
                  folder="courses"
                  aspectRatio="video"
                  helpText="Cover illustration or diagram for this specialization track."
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Select Included Courses:
                </label>
                <div className="max-h-40 overflow-y-auto space-y-1.5 p-3 rounded-xl bg-slate-950 border border-slate-800">
                  {courses.map((c) => {
                    const isSelected = selectedCourseIds.includes(c.id)
                    return (
                      <label
                        key={c.id}
                        className="flex items-center gap-2 text-xs text-slate-300 cursor-pointer hover:text-white"
                      >
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => toggleCourseSelection(c.id)}
                          className="w-4 h-4 rounded text-purple-600 bg-slate-900 border-slate-700"
                        />
                        <span>{c.title}</span>
                      </label>
                    )
                  })}
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-400 hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl font-bold bg-purple-600 hover:bg-purple-700 text-white shadow-md transition disabled:opacity-50"
                >
                  {saving ? 'Saving...' : 'Save Learning Path'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
