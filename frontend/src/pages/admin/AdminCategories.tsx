import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import ImageUploadField from '../../components/admin/ImageUploadField'

interface CategoryItem {
  id: number
  name: string
  slug: string
  description?: string | null
  icon?: string | null
  image?: string | null
  sort_order: number
  is_active: boolean
  courses_count?: number
}

export default function AdminCategories() {
  const [categories, setCategories] = useState<CategoryItem[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editingCategory, setEditingCategory] = useState<CategoryItem | null>(null)

  // Form State
  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [icon, setIcon] = useState('💻')
  const [image, setImage] = useState('')
  const [description, setDescription] = useState('')
  const [sortOrder, setSortOrder] = useState(0)
  const [isActive, setIsActive] = useState(true)

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadCategories = useCallback(() => {
    setLoading(true)
    API.get<CategoryItem[]>('/admin/categories')
      .then((res) => setCategories(Array.isArray(res.data) ? res.data : []))
      .catch(() => setErrorMsg('Failed to load categories.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadCategories()
  }, [loadCategories])

  const openCreateModal = () => {
    setEditingCategory(null)
    setName('')
    setSlug('')
    setIcon('💻')
    setImage('')
    setDescription('')
    setSortOrder(categories.length + 1)
    setIsActive(true)
    setShowModal(true)
  }

  const openEditModal = (cat: CategoryItem) => {
    setEditingCategory(cat)
    setName(cat.name)
    setSlug(cat.slug)
    setIcon(cat.icon || '💻')
    setImage(cat.image || '')
    setDescription(cat.description || '')
    setSortOrder(cat.sort_order)
    setIsActive(cat.is_active)
    setShowModal(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const payload = {
      name: name.trim(),
      slug: slug.trim() || undefined,
      icon: icon.trim() || undefined,
      image: image.trim() || undefined,
      description: description.trim() || undefined,
      sort_order: Number(sortOrder),
      is_active: isActive,
    }

    try {
      if (editingCategory) {
        await API.put(`/admin/categories/${editingCategory.id}`, payload)
        setSuccessMsg(`Category '${name}' updated successfully!`)
      } else {
        await API.post('/admin/categories', payload)
        setSuccessMsg(`Category '${name}' created successfully!`)
      }
      setShowModal(false)
      loadCategories()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to save category.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (cat: CategoryItem) => {
    if (!window.confirm(`Are you sure you want to delete category '${cat.name}'?`)) return

    try {
      await API.delete(`/admin/categories/${cat.id}`)
      setSuccessMsg(`Category '${cat.name}' deleted.`)
      loadCategories()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to delete category.')
    }
  }

  const handleMove = async (index: number, direction: 'up' | 'down') => {
    const targetIndex = direction === 'up' ? index - 1 : index + 1
    if (targetIndex < 0 || targetIndex >= categories.length) return

    const newCats = [...categories]
    const temp = newCats[index]
    newCats[index] = newCats[targetIndex]
    newCats[targetIndex] = temp

    const orders = newCats.map((c, idx) => ({ id: c.id, sort_order: idx + 1 }))
    setCategories(newCats)

    try {
      await API.post('/admin/categories/reorder', { orders })
      setSuccessMsg('Category display order updated!')
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      loadCategories()
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Course Category Management</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Manage course domains, explore tabs, and category ordering across the public platform.
          </p>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition flex items-center gap-2"
        >
          <span>+</span> Add Category
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

      {/* Table */}
      <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8">
        {loading ? (
          <p className="text-xs text-slate-500 py-8 text-center">Loading course categories...</p>
        ) : categories.length === 0 ? (
          <div className="py-10 text-center text-slate-500 text-xs">
            <span className="text-3xl mb-2 block">🏷️</span>
            <p className="font-bold text-slate-300">No categories found</p>
            <p className="mt-1">Click "Add Category" to create your first domain.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                  <th className="py-3 px-4">Order</th>
                  <th className="py-3 px-4">Category Name</th>
                  <th className="py-3 px-4">Slug</th>
                  <th className="py-3 px-4">Courses</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60">
                {categories.map((cat, idx) => (
                  <tr key={cat.id} className="hover:bg-slate-900/60 transition">
                    <td className="py-4 px-4 font-mono text-slate-400">
                      <div className="flex items-center gap-1.5">
                        <span className="font-bold text-white">#{idx + 1}</span>
                        <div className="flex flex-col">
                          <button
                            type="button"
                            disabled={idx === 0}
                            onClick={() => handleMove(idx, 'up')}
                            className="text-[10px] text-slate-400 hover:text-white disabled:opacity-20"
                          >
                            ▲
                          </button>
                          <button
                            type="button"
                            disabled={idx === categories.length - 1}
                            onClick={() => handleMove(idx, 'down')}
                            className="text-[10px] text-slate-400 hover:text-white disabled:opacity-20"
                          >
                            ▼
                          </button>
                        </div>
                      </div>
                    </td>
                    <td className="py-4 px-4">
                      <div className="flex items-center gap-2">
                        <span className="text-base">{cat.icon || '💻'}</span>
                        <span className="font-bold text-white">{cat.name}</span>
                      </div>
                    </td>
                    <td className="py-4 px-4 font-mono text-[11px] text-slate-400">
                      {cat.slug}
                    </td>
                    <td className="py-4 px-4 text-slate-300">
                      <span className="px-2 py-0.5 rounded-full bg-slate-900 border border-slate-800 text-[11px] font-bold">
                        {cat.courses_count ?? 0} courses
                      </span>
                    </td>
                    <td className="py-4 px-4">
                      <span
                        className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                          cat.is_active
                            ? 'bg-emerald-950 text-emerald-300 border border-emerald-800'
                            : 'bg-slate-800 text-slate-400 border border-slate-700'
                        }`}
                      >
                        {cat.is_active ? 'Active' : 'Disabled'}
                      </span>
                    </td>
                    <td className="py-4 px-4 text-right space-x-2">
                      <button
                        type="button"
                        onClick={() => openEditModal(cat)}
                        className="px-2.5 py-1 rounded-lg bg-slate-900 text-slate-300 hover:text-white border border-slate-700 font-bold text-[11px] transition"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDelete(cat)}
                        className="px-2.5 py-1 rounded-lg bg-red-950/60 text-red-300 hover:bg-red-900/80 border border-red-800 font-bold text-[11px] transition"
                      >
                        Delete
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* MODAL */}
      {showModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl border border-slate-800 space-y-5 text-white">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-950 text-purple-300 border border-purple-800 rounded-md">
                {editingCategory ? 'Edit Category' : 'New Category'}
              </span>
              <h3 className="text-lg font-bold text-white mt-1">
                {editingCategory ? `Edit '${editingCategory.name}'` : 'Create Course Category'}
              </h3>
            </div>

            <form onSubmit={handleSave} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Category Name <span className="text-red-400">*</span>
                </label>
                <input
                  type="text"
                  value={name}
                  onChange={(e) => {
                    setName(e.target.value)
                    if (!editingCategory) {
                      setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, ''))
                    }
                  }}
                  placeholder="e.g. Generative AI & LLMs"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Slug (URL Key)
                  </label>
                  <input
                    type="text"
                    value={slug}
                    onChange={(e) => setSlug(e.target.value)}
                    placeholder="generative-ai"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none font-mono"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Icon / Emoji
                  </label>
                  <input
                    type="text"
                    value={icon}
                    onChange={(e) => setIcon(e.target.value)}
                    placeholder="🤖"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none text-center"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Description
                </label>
                <textarea
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  rows={3}
                  placeholder="Brief curriculum description for this domain..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              {/* Category Image */}
              <div>
                <ImageUploadField
                  label="Category Header Graphic / Illustration"
                  value={image}
                  onChange={(url) => setImage(url)}
                  folder="courses"
                  aspectRatio="video"
                  helpText="Optional header graphic displayed on the category landing page."
                />
              </div>

              <div className="flex items-center gap-3 pt-1">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={isActive}
                    onChange={(e) => setIsActive(e.target.checked)}
                    className="w-4 h-4 rounded text-purple-600 bg-slate-950 border-slate-700 focus:ring-purple-500"
                  />
                  <span className="text-xs text-slate-300 font-bold">Category is Active & Visible</span>
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
                  {saving ? 'Saving...' : editingCategory ? 'Save Changes' : 'Create Category'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
