import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import type { ClassMaterial } from '../../types/classSession'
import type { Course } from '../../types/course'

interface MaterialFormData {
  course_id: number | ''
  class_session_id: number | ''
  title: string
  description: string
  material_type: string
  file: File | null
}

interface ClassSessionOption {
  id: number
  title: string
  scheduled_date: string
  course_id: number
}

export default function TutorMaterials() {
  const [materials, setMaterials] = useState<ClassMaterial[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [sessions, setSessions] = useState<ClassSessionOption[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  // Upload / Edit Modal State
  const [modalOpen, setModalOpen] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [formData, setFormData] = useState<MaterialFormData>({
    course_id: '',
    class_session_id: '',
    title: '',
    description: '',
    material_type: 'pdf',
    file: null,
  })
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState('')

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
      API.get<ClassMaterial[]>('/tutor/materials', { params }),
      API.get<Course[]>('/tutor/courses'),
      API.get<ClassSessionOption[]>('/tutor/class-sessions'),
    ])
      .then(([matRes, courseRes, sessRes]) => {
        setMaterials(Array.isArray(matRes.data) ? matRes.data : [])
        setCourses(Array.isArray(courseRes.data) ? courseRes.data : [])
        setSessions(Array.isArray(sessRes.data) ? sessRes.data : [])
      })
      .catch(() => setError('Failed to load learning materials.'))
      .finally(() => setLoading(false))
  }, [selectedCourseFilter])

  useEffect(() => {
    loadData()
  }, [loadData])

  const handleOpenUpload = () => {
    setEditId(null)
    setFormData({
      course_id: courses.length > 0 ? courses[0].id : '',
      class_session_id: '',
      title: '',
      description: '',
      material_type: 'pdf',
      file: null,
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleOpenEdit = (material: ClassMaterial) => {
    setEditId(material.id)
    setFormData({
      course_id: material.course_id,
      class_session_id: material.class_session_id || '',
      title: material.title,
      description: material.description || '',
      material_type: material.file_type || 'pdf',
      file: null,
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleDelete = async (id: number) => {
    if (!window.confirm('Are you sure you want to delete this learning material?')) return

    try {
      await API.delete(`/tutor/materials/${id}`)
      setSuccess('Material deleted successfully.')
      loadData()
      setTimeout(() => setSuccess(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      alert(response.response?.data?.message || 'Unable to delete material.')
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setFormError('')

    if (!formData.course_id) {
      setFormError('Please select a course.')
      return
    }

    if (!formData.title.trim()) {
      setFormError('Material title is required.')
      return
    }

    if (!editId && !formData.file) {
      setFormError('Please select a file to upload.')
      return
    }

    setSubmitting(true)

    try {
      if (editId) {
        // Edit mode (metadata only)
        await API.put(`/tutor/materials/${editId}`, {
          title: formData.title,
          description: formData.description,
          class_session_id: formData.class_session_id || null,
          material_type: formData.material_type,
        })
        setSuccess('Material updated successfully.')
      } else {
        // Upload mode
        const data = new FormData()
        data.append('course_id', String(formData.course_id))
        if (formData.class_session_id) {
          data.append('class_session_id', String(formData.class_session_id))
        }
        data.append('title', formData.title)
        data.append('description', formData.description)
        data.append('material_type', formData.material_type)
        if (formData.file) {
          data.append('file', formData.file)
        }

        await API.post('/tutor/materials', data, {
          headers: { 'Content-Type': 'multipart/form-data' },
        })
        setSuccess('Material uploaded successfully.')
      }

      setModalOpen(false)
      loadData()
      setTimeout(() => setSuccess(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setFormError(response.response?.data?.message || 'Error processing material.')
    } finally {
      setSubmitting(false)
    }
  }

  const filteredSessions = sessions.filter(
    (s) => formData.course_id === '' || s.course_id === Number(formData.course_id)
  )

  return (
    <div className="space-y-8">
      {/* Header Banner */}
      <div className="bg-gradient-to-r from-blue-700 via-indigo-700 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
        <div className="space-y-1">
          <span className="bg-blue-500/30 text-blue-200 border border-blue-400/30 text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full">
            Teaching Handouts & Resources
          </span>
          <h1 className="text-2xl sm:text-3xl font-black tracking-tight">Course Learning Materials 📚</h1>
          <p className="text-xs text-blue-100 max-w-xl">
            Upload and organize study handouts, slide decks, and code archives for your assigned courses and live classes.
          </p>
        </div>

        <button
          type="button"
          onClick={handleOpenUpload}
          className="px-6 py-3 rounded-2xl bg-white text-blue-700 hover:bg-blue-50 font-extrabold text-xs transition shadow-md flex items-center gap-2 shrink-0"
        >
          <span>➕</span> Add Material
        </button>
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
          <label htmlFor="course-filter" className="text-xs font-bold text-slate-500">Filter by Course:</label>
          <select
            id="course-filter"
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
          Showing {materials.length} Materials
        </div>
      </div>

      {/* Materials Grid */}
      {loading ? (
        <div className="py-20 text-center text-slate-500 space-y-3">
          <div className="w-10 h-10 border-2 border-blue-600/20 border-t-blue-600 rounded-full animate-spin mx-auto" />
          <p className="text-xs font-semibold">Loading materials...</p>
        </div>
      ) : materials.length === 0 ? (
        <div className="p-12 rounded-3xl bg-white border border-slate-200 text-center space-y-3 shadow-xs">
          <span className="text-4xl">📄</span>
          <h3 className="text-sm font-bold text-slate-900">No learning materials found</h3>
          <p className="text-xs text-slate-500 max-w-md mx-auto">
            Upload PDF handouts, code exercises, or presentation slides for your students.
          </p>
          <button
            type="button"
            onClick={handleOpenUpload}
            className="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold transition shadow-sm"
          >
            <span>➕</span> Upload First Material
          </button>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {materials.map((m) => (
            <div
              key={m.id}
              className="bg-white rounded-3xl p-5 border border-slate-200 shadow-xs hover:shadow-md transition flex flex-col justify-between space-y-4"
            >
              <div className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 truncate max-w-[170px]">
                    {m.course_id ? courses.find(c => c.id === m.course_id)?.title || 'Assigned Course' : 'Course Material'}
                  </span>
                  <span className="text-[10px] font-black uppercase px-2 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200">
                    {m.file_type || 'PDF'}
                  </span>
                </div>

                <h3 className="text-sm font-bold text-slate-900 line-clamp-1">{m.title}</h3>
                {m.description && (
                  <p className="text-xs text-slate-500 line-clamp-2">{m.description}</p>
                )}

                <div className="pt-2 text-[11px] text-slate-400 space-y-0.5 border-t border-slate-100">
                  <p>{m.file_name} • {(m.file_size / 1024).toFixed(1)} KB</p>
                  <p>Uploaded: {new Date(m.created_at).toLocaleDateString()}</p>
                </div>
              </div>

              <div className="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                <a
                  href={m.file_path}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex-grow py-2 px-3 rounded-xl bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-xs text-center transition"
                >
                  View / Download
                </a>

                <button
                  type="button"
                  onClick={() => handleOpenEdit(m)}
                  className="p-2 rounded-xl text-slate-600 hover:text-blue-600 hover:bg-slate-100 transition"
                  title="Edit Material"
                >
                  ✏️
                </button>

                <button
                  type="button"
                  onClick={() => handleDelete(m.id)}
                  className="p-2 rounded-xl text-slate-600 hover:text-rose-600 hover:bg-rose-50 transition"
                  title="Delete Material"
                >
                  🗑️
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Upload / Edit Modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4 overflow-y-auto backdrop-blur-xs">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl space-y-5 my-8">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h2 className="text-base font-black text-slate-900">
                {editId ? 'Edit Learning Material' : 'Upload Course Material'}
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
              <div>
                <label className="block font-bold text-slate-700 mb-1">Target Course *</label>
                <select
                  value={formData.course_id}
                  onChange={(e) => setFormData({ ...formData, course_id: Number(e.target.value), class_session_id: '' })}
                  disabled={Boolean(editId)}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-slate-50 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500 disabled:opacity-60"
                  required
                >
                  <option value="">Select an assigned course...</option>
                  {courses.map((c) => (
                    <option key={c.id} value={c.id}>{c.title}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block font-bold text-slate-700 mb-1">Associated Class Session (Optional)</label>
                <select
                  value={formData.class_session_id}
                  onChange={(e) => setFormData({ ...formData, class_session_id: e.target.value ? Number(e.target.value) : '' })}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-slate-50 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                >
                  <option value="">General Course Material (No specific session)</option>
                  {filteredSessions.map((s) => (
                    <option key={s.id} value={s.id}>{s.title} ({s.scheduled_date})</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block font-bold text-slate-700 mb-1">Material Title *</label>
                <input
                  type="text"
                  value={formData.title}
                  onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                  placeholder="e.g. React 19 State Architecture Notes"
                  className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 mb-1">Description</label>
                <textarea
                  rows={2}
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  placeholder="Brief description of the handout or contents..."
                  className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">Material Type</label>
                  <select
                    value={formData.material_type}
                    onChange={(e) => setFormData({ ...formData, material_type: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-semibold text-slate-800 focus:outline-hidden focus:border-blue-500"
                  >
                    <option value="pdf">PDF Document</option>
                    <option value="docx">Word DOCX</option>
                    <option value="pptx">PowerPoint PPTX</option>
                    <option value="xlsx">Excel XLSX</option>
                    <option value="zip">Source Code ZIP</option>
                    <option value="image">Diagram / Image</option>
                  </select>
                </div>

                {!editId && (
                  <div>
                    <label className="block font-bold text-slate-700 mb-1">Upload File * (Max 25MB)</label>
                    <input
                      type="file"
                      onChange={(e) => setFormData({ ...formData, file: e.target.files ? e.target.files[0] : null })}
                      className="w-full text-xs font-semibold file:mr-2 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                      required
                    />
                  </div>
                )}
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
                  {submitting ? 'Uploading...' : editId ? 'Save Changes' : 'Upload Material'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
