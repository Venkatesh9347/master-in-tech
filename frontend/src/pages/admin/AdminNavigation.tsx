import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

interface NavItem {
  id: number
  location: string
  label: string
  url: string
  icon?: string | null
  target: string
  sort_order: number
  is_active: boolean
  children?: NavItem[]
}

export default function AdminNavigation() {
  const [items, setItems] = useState<NavItem[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editingItem, setEditingItem] = useState<NavItem | null>(null)

  // Form State
  const [location, setLocation] = useState('header')
  const [label, setLabel] = useState('')
  const [url, setUrl] = useState('')
  const [icon, setIcon] = useState('')
  const [target, setTarget] = useState('_self')
  const [sortOrder, setSortOrder] = useState(0)
  const [isActive, setIsActive] = useState(true)

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadData = useCallback(() => {
    setLoading(true)
    API.get<NavItem[]>('/admin/navigation')
      .then((res) => setItems(Array.isArray(res.data) ? res.data : []))
      .catch(() => setErrorMsg('Failed to load navigation items.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const openCreateModal = () => {
    setEditingItem(null)
    setLocation('header')
    setLabel('')
    setUrl('')
    setIcon('')
    setTarget('_self')
    setSortOrder(items.length + 1)
    setIsActive(true)
    setShowModal(true)
  }

  const openEditModal = (item: NavItem) => {
    setEditingItem(item)
    setLocation(item.location)
    setLabel(item.label)
    setUrl(item.url)
    setIcon(item.icon || '')
    setTarget(item.target || '_self')
    setSortOrder(item.sort_order)
    setIsActive(item.is_active)
    setShowModal(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const payload = {
      location,
      label: label.trim(),
      url: url.trim(),
      icon: icon.trim() || undefined,
      target,
      sort_order: Number(sortOrder),
      is_active: isActive,
    }

    try {
      if (editingItem) {
        await API.put(`/admin/navigation/${editingItem.id}`, payload)
        setSuccessMsg(`Menu link '${label}' updated!`)
      } else {
        await API.post('/admin/navigation', payload)
        setSuccessMsg(`Menu link '${label}' created!`)
      }
      setShowModal(false)
      loadData()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to save navigation item.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (item: NavItem) => {
    if (!window.confirm(`Delete menu link '${item.label}'?`)) return

    try {
      await API.delete(`/admin/navigation/${item.id}`)
      setSuccessMsg('Navigation link deleted.')
      loadData()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to delete navigation item.')
    }
  }

  const headerItems = items.filter((i) => i.location === 'header')
  const footerLearning = items.filter((i) => i.location === 'footer_learning')
  const footerSupport = items.filter((i) => i.location === 'footer_support')

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Navigation & Menu Management</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Configure header menu links and footer navigation trees across the public site.
          </p>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition flex items-center gap-2"
        >
          <span>+</span> Add Menu Link
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

      {loading ? (
        <p className="text-xs text-slate-500 py-8 text-center">Loading navigation items...</p>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {/* Header Links */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h2 className="font-bold text-white text-sm flex items-center gap-2">
                <span>🧭</span> Header Navigation
              </h2>
              <span className="text-[10px] text-purple-400 font-bold">{headerItems.length} items</span>
            </div>

            <div className="space-y-2">
              {headerItems.map((item) => (
                <div
                  key={item.id}
                  className="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between text-xs hover:border-slate-700 transition"
                >
                  <div className="space-y-0.5">
                    <p className="font-bold text-white flex items-center gap-1.5">
                      {item.icon && <span>{item.icon}</span>}
                      {item.label}
                    </p>
                    <p className="text-[10px] font-mono text-slate-400">{item.url}</p>
                  </div>

                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => openEditModal(item)}
                      className="px-2 py-1 rounded bg-slate-800 text-[10px] text-slate-300 hover:text-white"
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(item)}
                      className="px-2 py-1 rounded bg-red-950 text-[10px] text-red-400 hover:bg-red-900"
                    >
                      ×
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Footer Learning */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h2 className="font-bold text-white text-sm flex items-center gap-2">
                <span>📚</span> Footer Quick Links
              </h2>
              <span className="text-[10px] text-purple-400 font-bold">{footerLearning.length} items</span>
            </div>

            <div className="space-y-2">
              {footerLearning.map((item) => (
                <div
                  key={item.id}
                  className="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between text-xs hover:border-slate-700 transition"
                >
                  <div className="space-y-0.5">
                    <p className="font-bold text-white">{item.label}</p>
                    <p className="text-[10px] font-mono text-slate-400">{item.url}</p>
                  </div>

                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => openEditModal(item)}
                      className="px-2 py-1 rounded bg-slate-800 text-[10px] text-slate-300 hover:text-white"
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(item)}
                      className="px-2 py-1 rounded bg-red-950 text-[10px] text-red-400 hover:bg-red-900"
                    >
                      ×
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Footer Support */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h2 className="font-bold text-white text-sm flex items-center gap-2">
                <span>🤝</span> Footer Support & Help
              </h2>
              <span className="text-[10px] text-purple-400 font-bold">{footerSupport.length} items</span>
            </div>

            <div className="space-y-2">
              {footerSupport.map((item) => (
                <div
                  key={item.id}
                  className="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between text-xs hover:border-slate-700 transition"
                >
                  <div className="space-y-0.5">
                    <p className="font-bold text-white">{item.label}</p>
                    <p className="text-[10px] font-mono text-slate-400">{item.url}</p>
                  </div>

                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => openEditModal(item)}
                      className="px-2 py-1 rounded bg-slate-800 text-[10px] text-slate-300 hover:text-white"
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(item)}
                      className="px-2 py-1 rounded bg-red-950 text-[10px] text-red-400 hover:bg-red-900"
                    >
                      ×
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* MODAL */}
      {showModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl border border-slate-800 space-y-5 text-white">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-950 text-purple-300 border border-purple-800 rounded-md">
                Menu Link Editor
              </span>
              <h3 className="text-lg font-bold text-white mt-1">
                {editingItem ? `Edit Link` : 'Add Navigation Link'}
              </h3>
            </div>

            <form onSubmit={handleSave} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Menu Location</label>
                <select
                  value={location}
                  onChange={(e) => setLocation(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                >
                  <option value="header">Header Navigation</option>
                  <option value="footer_learning">Footer Quick Links</option>
                  <option value="footer_support">Footer Support & Help</option>
                </select>
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Link Label <span className="text-red-400">*</span>
                </label>
                <input
                  type="text"
                  value={label}
                  onChange={(e) => setLabel(e.target.value)}
                  placeholder="e.g. Explore Courses"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  URL Route / Path <span className="text-red-400">*</span>
                </label>
                <input
                  type="text"
                  value={url}
                  onChange={(e) => setUrl(e.target.value)}
                  placeholder="/courses"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none font-mono"
                  required
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Icon / Emoji</label>
                  <input
                    type="text"
                    value={icon}
                    onChange={(e) => setIcon(e.target.value)}
                    placeholder="📚"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none text-center"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Target</label>
                  <select
                    value={target}
                    onChange={(e) => setTarget(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  >
                    <option value="_self">Same Tab (_self)</option>
                    <option value="_blank">New Tab (_blank)</option>
                  </select>
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
                  {saving ? 'Saving...' : 'Save Link'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
