import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import ImageUploadField from '../../components/admin/ImageUploadField'

interface ResourceItem {
  id: number
  title: string
  slug: string
  description?: string | null
  type: string
  tag?: string | null
  icon?: string | null
  url_or_file?: string | null
  author?: string | null
  display_order: number
  is_published: boolean
}

export default function AdminResources() {
  const [resources, setResources] = useState<ResourceItem[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editingRes, setEditingRes] = useState<ResourceItem | null>(null)

  // Form State
  const [title, setTitle] = useState('')
  const [slug, setSlug] = useState('')
  const [type, setType] = useState('Guide')
  const [tag, setTag] = useState('')
  const [icon, setIcon] = useState('💡')
  const [description, setDescription] = useState('')
  const [urlOrFile, setUrlOrFile] = useState('')
  const [author, setAuthor] = useState('MasterInTech Faculty')
  const [isPublished, setIsPublished] = useState(true)

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadData = useCallback(() => {
    setLoading(true)
    API.get<ResourceItem[]>('/admin/resources')
      .then((res) => setResources(Array.isArray(res.data) ? res.data : []))
      .catch(() => setErrorMsg('Failed to load resources.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const openCreateModal = () => {
    setEditingRes(null)
    setTitle('')
    setSlug('')
    setType('Guide')
    setTag('Career Toolkit')
    setIcon('💡')
    setDescription('')
    setUrlOrFile('')
    setAuthor('MasterInTech Faculty')
    setIsPublished(true)
    setShowModal(true)
  }

  const openEditModal = (item: ResourceItem) => {
    setEditingRes(item)
    setTitle(item.title)
    setSlug(item.slug)
    setType(item.type)
    setTag(item.tag || '')
    setIcon(item.icon || '💡')
    setDescription(item.description || '')
    setUrlOrFile(item.url_or_file || '')
    setAuthor(item.author || '')
    setIsPublished(item.is_published)
    setShowModal(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const payload = {
      title: title.trim(),
      slug: slug.trim() || undefined,
      type,
      tag: tag.trim() || undefined,
      icon: icon.trim() || undefined,
      description: description.trim() || undefined,
      url_or_file: urlOrFile.trim() || undefined,
      author: author.trim() || undefined,
      is_published: isPublished,
    }

    try {
      if (editingRes) {
        await API.put(`/admin/resources/${editingRes.id}`, payload)
        setSuccessMsg(`Resource '${title}' updated!`)
      } else {
        await API.post('/admin/resources', payload)
        setSuccessMsg(`Resource '${title}' created!`)
      }
      setShowModal(false)
      loadData()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to save resource.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (item: ResourceItem) => {
    if (!window.confirm(`Delete resource '${item.title}'?`)) return

    try {
      await API.delete(`/admin/resources/${item.id}`)
      setSuccessMsg('Resource deleted.')
      loadData()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to delete resource.')
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Open Knowledge Base & Resources</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Manage public engineering guides, cheatsheets, developer templates, and notebook repos.
          </p>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition flex items-center gap-2"
        >
          <span>+</span> Add Resource
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
          <p className="text-xs text-slate-500 py-8 text-center">Loading resources...</p>
        ) : resources.length === 0 ? (
          <div className="py-10 text-center text-slate-500 text-xs">
            <span className="text-3xl mb-2 block">💡</span>
            <p className="font-bold text-slate-300">No resources found</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {resources.map((r) => (
              <div
                key={r.id}
                className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 hover:border-slate-700 transition flex flex-col justify-between"
              >
                <div className="space-y-2.5">
                  <div className="flex items-center justify-between">
                    <span className="text-2xl">{r.icon || '💡'}</span>
                    <span className="px-2 py-0.5 rounded-md bg-purple-950 text-purple-300 border border-purple-800 text-[10px] font-bold uppercase">
                      {r.type}
                    </span>
                  </div>

                  <div>
                    <span className="text-[10px] font-semibold text-blue-400 uppercase tracking-wider">
                      {r.tag || 'General'}
                    </span>
                    <h3 className="font-bold text-white text-sm mt-0.5 leading-tight">{r.title}</h3>
                  </div>

                  <p className="text-xs text-slate-300 leading-relaxed line-clamp-3">
                    {r.description}
                  </p>
                </div>

                <div className="pt-3 border-t border-slate-800 flex items-center justify-between">
                  <span className="text-[10px] text-slate-500">{r.author || 'Faculty'}</span>

                  <div className="flex gap-1.5">
                    <button
                      type="button"
                      onClick={() => openEditModal(r)}
                      className="px-2.5 py-1 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs font-bold transition"
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(r)}
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
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-800 space-y-5 text-white max-h-[90vh] overflow-y-auto">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-950 text-purple-300 border border-purple-800 rounded-md">
                Resource Editor
              </span>
              <h3 className="text-lg font-bold text-white mt-1">
                {editingRes ? `Edit Resource` : 'Add Knowledge Resource'}
              </h3>
            </div>

            <form onSubmit={handleSave} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Resource Title <span className="text-red-400">*</span>
                </label>
                <input
                  type="text"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="e.g. Full Stack & AI Developer Roadmap"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Resource Type</label>
                  <select
                    value={type}
                    onChange={(e) => setType(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  >
                    <option value="Guide">Guide</option>
                    <option value="Cheatsheet">Cheatsheet</option>
                    <option value="Code Repo">Code Repo</option>
                    <option value="Documentation">Documentation</option>
                    <option value="Template">Template</option>
                    <option value="Video">Video</option>
                  </select>
                </div>
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Category Tag</label>
                  <input
                    type="text"
                    value={tag}
                    onChange={(e) => setTag(e.target.value)}
                    placeholder="e.g. Career Roadmap / AI & ML"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
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
                  placeholder="Summary of knowledge contents, tool versions, and takeaways..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              {/* Resource Thumbnail / Cover */}
              <div>
                <ImageUploadField
                  label="Resource Cover Graphic / Thumbnail"
                  value={urlOrFile.startsWith('http') && (urlOrFile.includes('/uploads/media') || urlOrFile.match(/\.(jpeg|jpg|png|webp|svg)$/i)) ? urlOrFile : ''}
                  onChange={(url) => setUrlOrFile(url)}
                  folder="resources"
                  aspectRatio="video"
                  helpText="Optional cover graphic or cheatsheet preview image."
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Download / External Link URL
                  </label>
                  <input
                    type="text"
                    value={urlOrFile}
                    onChange={(e) => setUrlOrFile(e.target.value)}
                    placeholder="https://github.com/... or /downloads/..."
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Author / Contributor</label>
                  <input
                    type="text"
                    value={author}
                    onChange={(e) => setAuthor(e.target.value)}
                    placeholder="e.g. Senior Tech Leads"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  />
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
                  {saving ? 'Saving...' : 'Save Resource'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
