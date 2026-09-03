import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import ImageUploadField from '../../components/admin/ImageUploadField'

interface TestimonialItem {
  id: number
  student_name: string
  student_photo?: string | null
  student_role_or_company?: string | null
  course_title?: string | null
  rating: number
  content: string
  display_order: number
  is_featured: boolean
  is_published: boolean
}

export default function AdminTestimonials() {
  const [testimonials, setTestimonials] = useState<TestimonialItem[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editingItem, setEditingItem] = useState<TestimonialItem | null>(null)

  // Form State
  const [studentName, setStudentName] = useState('')
  const [studentPhoto, setStudentPhoto] = useState('')
  const [studentRole, setStudentRole] = useState('')
  const [courseTitle, setCourseTitle] = useState('')
  const [rating, setRating] = useState(5)
  const [content, setContent] = useState('')
  const [isFeatured, setIsFeatured] = useState(true)
  const [isPublished, setIsPublished] = useState(true)

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadData = useCallback(() => {
    setLoading(true)
    API.get<TestimonialItem[]>('/admin/testimonials')
      .then((res) => setTestimonials(Array.isArray(res.data) ? res.data : []))
      .catch(() => setErrorMsg('Failed to load testimonials.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const openCreateModal = () => {
    setEditingItem(null)
    setStudentName('')
    setStudentPhoto('')
    setStudentRole('')
    setCourseTitle('')
    setRating(5)
    setContent('')
    setIsFeatured(true)
    setIsPublished(true)
    setShowModal(true)
  }

  const openEditModal = (item: TestimonialItem) => {
    setEditingItem(item)
    setStudentName(item.student_name)
    setStudentPhoto(item.student_photo || '')
    setStudentRole(item.student_role_or_company || '')
    setCourseTitle(item.course_title || '')
    setRating(item.rating)
    setContent(item.content)
    setIsFeatured(item.is_featured)
    setIsPublished(item.is_published)
    setShowModal(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const payload = {
      student_name: studentName.trim(),
      student_photo: studentPhoto.trim() || undefined,
      student_role_or_company: studentRole.trim() || undefined,
      course_title: courseTitle.trim() || undefined,
      rating: Number(rating),
      content: content.trim(),
      is_featured: isFeatured,
      is_published: isPublished,
    }

    try {
      if (editingItem) {
        await API.put(`/admin/testimonials/${editingItem.id}`, payload)
        setSuccessMsg(`Testimonial for '${studentName}' updated!`)
      } else {
        await API.post('/admin/testimonials', payload)
        setSuccessMsg(`Testimonial for '${studentName}' created!`)
      }
      setShowModal(false)
      loadData()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to save testimonial.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (item: TestimonialItem) => {
    if (!window.confirm(`Delete testimonial by '${item.student_name}'?`)) return

    try {
      await API.delete(`/admin/testimonials/${item.id}`)
      setSuccessMsg(`Testimonial deleted.`)
      loadData()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to delete testimonial.')
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Student Testimonials & Reviews</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Manage real alumni career transition reviews and homepage success stories.
          </p>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition flex items-center gap-2"
        >
          <span>+</span> Add Testimonial
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

      {/* Grid */}
      <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8">
        {loading ? (
          <p className="text-xs text-slate-500 py-8 text-center">Loading testimonials...</p>
        ) : testimonials.length === 0 ? (
          <div className="py-10 text-center text-slate-500 text-xs">
            <span className="text-3xl mb-2 block">💬</span>
            <p className="font-bold text-slate-300">No testimonials found</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {testimonials.map((t) => (
              <div
                key={t.id}
                className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 hover:border-slate-700 transition flex flex-col justify-between"
              >
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="text-yellow-400 font-bold text-xs">
                      {'★'.repeat(t.rating)}
                    </span>
                    {t.is_featured && (
                      <span className="px-2 py-0.5 rounded-md bg-purple-950 text-purple-300 border border-purple-800 text-[10px] font-bold">
                        Featured
                      </span>
                    )}
                  </div>

                  <p className="text-xs text-slate-300 italic leading-relaxed">
                    "{t.content}"
                  </p>
                </div>

                <div className="pt-3 border-t border-slate-800 space-y-2">
                  <div>
                    <h4 className="font-bold text-white text-xs">{t.student_name}</h4>
                    <p className="text-[11px] text-purple-400 font-medium">{t.student_role_or_company}</p>
                    {t.course_title && (
                      <p className="text-[10px] text-slate-500 truncate">{t.course_title}</p>
                    )}
                  </div>

                  <div className="flex justify-end gap-2 pt-1">
                    <button
                      type="button"
                      onClick={() => openEditModal(t)}
                      className="px-2.5 py-1 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs font-bold transition"
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(t)}
                      className="px-2.5 py-1 rounded-lg bg-red-950/60 text-red-300 hover:bg-red-900 text-xs font-bold transition"
                    >
                      Delete
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* MODAL */}
      {showModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl border border-slate-800 space-y-5 text-white">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-950 text-purple-300 border border-purple-800 rounded-md">
                Testimonial Editor
              </span>
              <h3 className="text-lg font-bold text-white mt-1">
                {editingItem ? `Edit Review` : 'Add Alumni Testimonial'}
              </h3>
            </div>

            <form onSubmit={handleSave} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Student Name <span className="text-red-400">*</span>
                </label>
                <input
                  type="text"
                  value={studentName}
                  onChange={(e) => setStudentName(e.target.value)}
                  placeholder="e.g. Rohan Mehta"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Role / Company Transition
                </label>
                <input
                  type="text"
                  value={studentRole}
                  onChange={(e) => setStudentRole(e.target.value)}
                  placeholder="e.g. Software Engineer @ Adobe"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Enrolled Course Title
                </label>
                <input
                  type="text"
                  value={courseTitle}
                  onChange={(e) => setCourseTitle(e.target.value)}
                  placeholder="e.g. Artificial Intelligence & Deep Learning"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              {/* Student Photo */}
              <div>
                <ImageUploadField
                  label="Student Avatar / Headshot"
                  value={studentPhoto}
                  onChange={(url) => setStudentPhoto(url)}
                  folder="testimonials"
                  aspectRatio="avatar"
                  helpText="Upload a student profile photo (JPG, PNG, WebP)."
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Rating</label>
                <select
                  value={rating}
                  onChange={(e) => setRating(Number(e.target.value))}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                >
                  <option value={5}>★★★★★ (5 Stars)</option>
                  <option value={4}>★★★★☆ (4 Stars)</option>
                  <option value={3}>★★★☆☆ (3 Stars)</option>
                </select>
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Student Review / Quote <span className="text-red-400">*</span>
                </label>
                <textarea
                  value={content}
                  onChange={(e) => setContent(e.target.value)}
                  rows={3}
                  placeholder="Quote describing the learning experience, career transition, or outcomes..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div className="flex items-center gap-4 pt-1">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={isFeatured}
                    onChange={(e) => setIsFeatured(e.target.checked)}
                    className="w-4 h-4 rounded text-purple-600 bg-slate-950 border-slate-700"
                  />
                  <span className="text-xs text-slate-300 font-bold">Featured on Home</span>
                </label>
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
                  {saving ? 'Saving...' : 'Save Testimonial'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
