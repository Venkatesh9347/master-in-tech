import React, { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

interface MediaAsset {
  id: number
  file_name: string
  original_name?: string | null
  title?: string | null
  file_path: string
  url: string
  disk?: string
  folder?: string
  mime_type?: string | null
  file_size?: number
  alt_text?: string | null
  created_at?: string
  uploader?: { id: number; name: string }
}

const FOLDER_TABS = [
  { key: 'all', label: 'All Assets' },
  { key: 'courses', label: 'Courses' },
  { key: 'instructors', label: 'Instructors' },
  { key: 'events', label: 'Events' },
  { key: 'testimonials', label: 'Testimonials' },
  { key: 'resources', label: 'Resources' },
  { key: 'branding', label: 'Branding & Logo' },
  { key: 'general', label: 'General' },
]

export default function AdminMedia() {
  const [mediaList, setMediaList] = useState<MediaAsset[]>([])
  const [loading, setLoading] = useState(true)
  const [activeFolder, setActiveFolder] = useState('all')
  const [search, setSearch] = useState('')
  const [selectedAsset, setSelectedAsset] = useState<MediaAsset | null>(null)

  // Upload modal state
  const [uploadModalOpen, setUploadModalOpen] = useState(false)
  const [uploadFile, setUploadFile] = useState<File | null>(null)
  const [uploadTitle, setUploadTitle] = useState('')
  const [uploadAlt, setUploadAlt] = useState('')
  const [uploadFolder, setUploadFolder] = useState('courses')
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState('')

  // Edit metadata modal state
  const [editModalOpen, setEditModalOpen] = useState(false)
  const [editTitle, setEditTitle] = useState('')
  const [editAlt, setEditAlt] = useState('')
  const [editFolder, setEditFolder] = useState('general')
  const [savingEdit, setSavingEdit] = useState(false)

  // Replace file state
  const [replacing, setReplacing] = useState(false)
  const [toastMessage, setToastMessage] = useState('')
  const [loadError, setLoadError] = useState(false)

  const showToast = (msg: string) => {
    setToastMessage(msg)
    setTimeout(() => setToastMessage(''), 3000)
  }

  const fetchMedia = useCallback(() => {
    setLoading(true)
    setLoadError(false)
    const params: Record<string, string | number> = { per_page: 48 }
    if (activeFolder !== 'all') params.folder = activeFolder
    if (search.trim()) params.search = search.trim()

    API.get<{ data?: MediaAsset[] } | MediaAsset[]>('/admin/media', { params })
      .then((res) => {
        const raw = res.data
        const items = Array.isArray(raw) ? raw : (raw as { data?: MediaAsset[] }).data || []
        setMediaList(items)
        if (selectedAsset) {
          const updated = items.find((i) => i.id === selectedAsset.id)
          if (updated) setSelectedAsset(updated)
        }
      })
      .catch(() => {
        setMediaList([])
        setLoadError(true)
      })
      .finally(() => setLoading(false))
  }, [activeFolder, search, selectedAsset])

  useEffect(() => {
    fetchMedia()
  }, [fetchMedia])

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    fetchMedia()
  }

  const handleUploadSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!uploadFile) {
      setUploadError('Please select a file to upload.')
      return
    }

    setUploading(true)
    setUploadError('')

    const formData = new FormData()
    formData.append('file', uploadFile)
    formData.append('title', uploadTitle || uploadFile.name.replace(/\.[^/.]+$/, ''))
    formData.append('alt_text', uploadAlt || uploadFile.name.replace(/\.[^/.]+$/, ''))
    formData.append('folder', uploadFolder)

    try {
      const res = await API.post<{ media: MediaAsset }>('/admin/media', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setUploadModalOpen(false)
      setUploadFile(null)
      setUploadTitle('')
      setUploadAlt('')
      showToast('Media asset uploaded successfully.')
      if (res.data?.media) {
        setSelectedAsset(res.data.media)
      }
      fetchMedia()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setUploadError(msg || 'Upload failed. Please ensure file format is allowed (JPG, PNG, WebP, GIF, PDF, MP4).')
    } finally {
      setUploading(false)
    }
  }

  const handleOpenEdit = (asset: MediaAsset) => {
    setSelectedAsset(asset)
    setEditTitle(asset.title || asset.file_name)
    setEditAlt(asset.alt_text || '')
    setEditFolder(asset.folder || 'general')
    setEditModalOpen(true)
  }

  const handleSaveEdit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedAsset) return

    setSavingEdit(true)
    try {
      const res = await API.put<{ media: MediaAsset }>(`/admin/media/${selectedAsset.id}`, {
        title: editTitle,
        alt_text: editAlt,
        folder: editFolder,
      })
      setEditModalOpen(false)
      showToast('Metadata updated successfully.')
      if (res.data?.media) {
        setSelectedAsset(res.data.media)
      }
      fetchMedia()
    } catch {
      alert('Failed to update media metadata.')
    } finally {
      setSavingEdit(false)
    }
  }

  const handleReplaceFile = async (e: React.ChangeEvent<HTMLInputElement>, asset: MediaAsset) => {
    const file = e.target.files?.[0]
    if (!file) return

    if (!confirm(`Replace file for '${asset.title || asset.file_name}'? The old file will be removed.`)) {
      e.target.value = ''
      return
    }

    setReplacing(true)
    const formData = new FormData()
    formData.append('file', file)

    try {
      const res = await API.post<{ media: MediaAsset }>(`/admin/media/${asset.id}/replace`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      showToast('Media file replaced successfully on disk.')
      if (res.data?.media) {
        setSelectedAsset(res.data.media)
      }
      fetchMedia()
    } catch {
      alert('Failed to replace media file.')
    } finally {
      setReplacing(false)
      e.target.value = ''
    }
  }

  const handleDelete = async (asset: MediaAsset) => {
    if (!confirm(`Are you sure you want to permanently delete '${asset.title || asset.file_name}'?`)) {
      return
    }

    try {
      await API.delete(`/admin/media/${asset.id}`)
      showToast('Media asset deleted.')
      if (selectedAsset?.id === asset.id) {
        setSelectedAsset(null)
      }
      fetchMedia()
    } catch {
      alert('Failed to delete media asset.')
    }
  }

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text)
    showToast('Image URL copied to clipboard.')
  }

  return (
    <div className="space-y-6">
      {/* Toast */}
      {toastMessage && (
        <div className="fixed bottom-6 right-6 z-50 px-4 py-2.5 rounded-xl bg-slate-900 text-white text-xs font-bold shadow-xl border border-slate-700 animate-in fade-in">
          {toastMessage}
        </div>
      )}

      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-black text-slate-900">Media & Asset Library</h1>
          <p className="text-xs text-slate-500 mt-0.5">
            Central repository for course thumbnails, mentor avatars, event banners, and branding assets.
          </p>
        </div>
        <button
          type="button"
          onClick={() => {
            setUploadFile(null)
            setUploadTitle('')
            setUploadAlt('')
            setUploadFolder(activeFolder !== 'all' ? activeFolder : 'courses')
            setUploadModalOpen(true)
          }}
          className="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold transition shadow-xs flex items-center gap-1.5 cursor-pointer shrink-0"
        >
          <span>+ Upload New Media</span>
        </button>
      </div>

      {/* Filter & Search Bar */}
      <div className="p-4 bg-white rounded-2xl border border-slate-200 shadow-xs flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-0.5">
          {FOLDER_TABS.map((tab) => (
            <button
              key={tab.key}
              type="button"
              onClick={() => setActiveFolder(tab.key)}
              className={`px-3 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap cursor-pointer ${
                activeFolder === tab.key
                  ? 'bg-blue-600 text-white shadow-xs'
                  : 'bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <form onSubmit={handleSearchSubmit} className="flex items-center gap-1.5">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by file name or title..."
            className="px-3.5 py-1.5 rounded-xl border border-slate-200 text-xs bg-slate-50 focus:bg-white focus:outline-hidden focus:ring-1 focus:ring-blue-500 w-56"
          />
          <button
            type="submit"
            className="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition"
          >
            Search
          </button>
        </form>
      </div>

      {/* Main Grid + Inspector Layout */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        {/* Media Grid (Spans 8 or 12 cols) */}
        <div className={selectedAsset ? 'lg:col-span-8' : 'lg:col-span-12'}>
          {loading ? (
            <div className="p-12 text-center text-xs font-bold text-slate-400 bg-white rounded-3xl border border-slate-200">
              Loading media assets...
            </div>
          ) : mediaList.length === 0 ? (
            <div className="p-12 text-center bg-white rounded-3xl border border-slate-200 space-y-3">
              <span className="text-4xl block">{loadError ? '⚠️' : '🖼️'}</span>
              <h3 className="text-sm font-bold text-slate-800">
                {loadError ? 'Could not load media library' : 'No media assets found'}
              </h3>
              <p className="text-xs text-slate-400 max-w-sm mx-auto">
                {loadError
                  ? 'We could not reach the media library. Please check your connection and try again.'
                  : 'No images exist in this category yet. Click the "+ Upload New Media" button to add your first asset.'}
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
              {mediaList.map((asset) => {
                const isSelected = selectedAsset?.id === asset.id
                return (
                  <div
                    key={asset.id}
                    onClick={() => setSelectedAsset(asset)}
                    className={`group bg-white rounded-2xl border overflow-hidden transition cursor-pointer flex flex-col justify-between ${
                      isSelected
                        ? 'border-blue-600 ring-2 ring-blue-500/20 shadow-md'
                        : 'border-slate-200 hover:border-slate-300 hover:shadow-xs'
                    }`}
                  >
                    <div className="aspect-square bg-slate-100 relative overflow-hidden flex items-center justify-center">
                      <img
                        src={asset.url}
                        alt={asset.alt_text || asset.title || asset.file_name}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                        onError={(e) => {
                          ;(e.target as HTMLImageElement).src =
                            'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=400&q=80'
                        }}
                      />
                      <span className="absolute top-2 left-2 px-2 py-0.5 rounded-md bg-slate-900/80 text-[9px] font-bold text-white uppercase tracking-wider backdrop-blur-xs">
                        {asset.folder || 'general'}
                      </span>
                    </div>

                    <div className="p-3">
                      <p className="text-xs font-bold text-slate-900 truncate" title={asset.title || asset.file_name}>
                        {asset.title || asset.file_name}
                      </p>
                      <p className="text-[10px] text-slate-400 truncate mt-0.5">
                        {asset.file_size ? `${Math.round(asset.file_size / 1024)} KB` : ''} •{' '}
                        {asset.mime_type?.split('/')[1] || 'image'}
                      </p>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>

        {/* Selected Asset Inspector Sidebar */}
        {selectedAsset && (
          <div className="lg:col-span-4 bg-white rounded-3xl border border-slate-200 p-5 shadow-xs space-y-5 sticky top-20">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-xs font-black uppercase tracking-wider text-slate-800">
                Asset Details
              </h3>
              <button
                type="button"
                onClick={() => setSelectedAsset(null)}
                className="text-slate-400 hover:text-slate-600 text-xs font-bold"
              >
                ✕ Close
              </button>
            </div>

            {/* Preview Box */}
            <div className="rounded-2xl border border-slate-200 bg-slate-100 overflow-hidden flex items-center justify-center max-h-56 relative">
              <img
                src={selectedAsset.url}
                alt={selectedAsset.alt_text || selectedAsset.title || ''}
                className="w-full h-full object-contain"
              />
            </div>

            {/* Metadata Table */}
            <div className="space-y-2 text-xs">
              <div className="flex justify-between py-1 border-b border-slate-50">
                <span className="text-slate-400">Title:</span>
                <span className="font-bold text-slate-800 truncate max-w-[200px]">
                  {selectedAsset.title || '—'}
                </span>
              </div>
              <div className="flex justify-between py-1 border-b border-slate-50">
                <span className="text-slate-400">Folder:</span>
                <span className="font-bold text-blue-600 uppercase text-[10px]">
                  {selectedAsset.folder || 'general'}
                </span>
              </div>
              <div className="flex justify-between py-1 border-b border-slate-50">
                <span className="text-slate-400">Alt Text:</span>
                <span className="text-slate-700 italic truncate max-w-[200px]">
                  {selectedAsset.alt_text || '—'}
                </span>
              </div>
              <div className="flex justify-between py-1 border-b border-slate-50">
                <span className="text-slate-400">Dimensions / Size:</span>
                <span className="text-slate-600">
                  {selectedAsset.file_size ? `${Math.round(selectedAsset.file_size / 1024)} KB` : '—'}
                </span>
              </div>
              <div className="flex justify-between py-1 border-b border-slate-50">
                <span className="text-slate-400">MIME Type:</span>
                <span className="text-slate-600 font-mono text-[11px]">
                  {selectedAsset.mime_type || 'image/jpeg'}
                </span>
              </div>
              <div className="flex justify-between py-1">
                <span className="text-slate-400">Public URL:</span>
                <button
                  type="button"
                  onClick={() => copyToClipboard(selectedAsset.url)}
                  className="text-blue-600 hover:underline font-bold text-[11px]"
                >
                  Copy URL 📋
                </button>
              </div>
            </div>

            {/* Actions */}
            <div className="pt-2 border-t border-slate-100 space-y-2">
              <button
                type="button"
                onClick={() => handleOpenEdit(selectedAsset)}
                className="w-full py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition"
              >
                ✏️ Edit Metadata & Alt Text
              </button>

              <label className="w-full py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 text-xs font-bold transition flex items-center justify-center gap-1.5 cursor-pointer">
                <span>🔄 {replacing ? 'Replacing...' : 'Replace File on Disk'}</span>
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                  onChange={(e) => handleReplaceFile(e, selectedAsset)}
                  disabled={replacing}
                  className="hidden"
                />
              </label>

              <button
                type="button"
                onClick={() => handleDelete(selectedAsset)}
                className="w-full py-2 rounded-xl bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 text-xs font-bold transition"
              >
                🗑️ Delete Asset
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Upload Modal */}
      {uploadModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-xs">
          <div className="bg-white rounded-3xl p-6 shadow-2xl border border-slate-200 w-full max-w-md space-y-5">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-black text-slate-900">Upload Media Asset</h3>
              <button
                type="button"
                onClick={() => setUploadModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 font-bold"
              >
                ✕
              </button>
            </div>

            {uploadError && (
              <p className="p-3 bg-red-50 text-red-600 text-xs rounded-xl border border-red-100">
                {uploadError}
              </p>
            )}

            <form onSubmit={handleUploadSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Select File (JPG, PNG, WebP, GIF, PDF, MP4) *
                </label>
                <input
                  type="file"
                  required
                  accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,video/mp4"
                  onChange={(e) => {
                    const f = e.target.files?.[0] || null
                    setUploadFile(f)
                    if (f && !uploadTitle) {
                      setUploadTitle(f.name.replace(/\.[^/.]+$/, ''))
                    }
                  }}
                  className="w-full text-xs text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Folder / Category
                </label>
                <select
                  value={uploadFolder}
                  onChange={(e) => setUploadFolder(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white"
                >
                  <option value="courses">Courses</option>
                  <option value="instructors">Instructors</option>
                  <option value="events">Events</option>
                  <option value="testimonials">Testimonials</option>
                  <option value="resources">Resources</option>
                  <option value="branding">Branding & Logo</option>
                  <option value="general">General</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Asset Title
                </label>
                <input
                  type="text"
                  value={uploadTitle}
                  onChange={(e) => setUploadTitle(e.target.value)}
                  placeholder="e.g. Artificial Intelligence Mastery Thumbnail"
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Alt Text (SEO & Accessibility)
                </label>
                <input
                  type="text"
                  value={uploadAlt}
                  onChange={(e) => setUploadAlt(e.target.value)}
                  placeholder="e.g. AI Mastery Course Card Cover"
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setUploadModalOpen(false)}
                  className="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={uploading}
                  className="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold shadow-xs transition"
                >
                  {uploading ? 'Uploading...' : 'Confirm Upload'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Edit Metadata Modal */}
      {editModalOpen && selectedAsset && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-xs">
          <div className="bg-white rounded-3xl p-6 shadow-2xl border border-slate-200 w-full max-w-md space-y-5">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-black text-slate-900">Edit Asset Metadata</h3>
              <button
                type="button"
                onClick={() => setEditModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 font-bold"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSaveEdit} className="space-y-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Asset Title
                </label>
                <input
                  type="text"
                  value={editTitle}
                  onChange={(e) => setEditTitle(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Folder / Category
                </label>
                <select
                  value={editFolder}
                  onChange={(e) => setEditFolder(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white"
                >
                  <option value="courses">Courses</option>
                  <option value="instructors">Instructors</option>
                  <option value="events">Events</option>
                  <option value="testimonials">Testimonials</option>
                  <option value="resources">Resources</option>
                  <option value="branding">Branding & Logo</option>
                  <option value="general">General</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Alt Text
                </label>
                <input
                  type="text"
                  value={editAlt}
                  onChange={(e) => setEditAlt(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setEditModalOpen(false)}
                  className="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingEdit}
                  className="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold shadow-xs transition"
                >
                  {savingEdit ? 'Saving...' : 'Save Changes'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
